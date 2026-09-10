# Payment core

This document describes the stabilization of `main` from `cbeba360`. The core uses
the existing PHP/PDO, Electrum, XPUB generator and `shanelic/bitcoin-p8` stack.

For current installation and migration steps use [Deployment](DEPLOYMENT.md) and
[Database upgrade](DATABASE_UPGRADE.md). [Payment monitoring](PAYMENT_MONITORING.md)
describes the later bounded CLI/admin runner and heartbeat. Historical validation
counts below belong to their checkpoint; open work is in [Roadmap](ROADMAP.md).

## Owners and boundaries

| Responsibility | Owner | External work |
| --- | --- | --- |
| Address creation | `AddressGeneratorInterface` implementations | XPUB: DB index reservation and local derivation; Electrum: explicit wallet mutation |
| Invoice creation | `BtcInvoiceManager` | Persist immutable invoice creation data |
| Create-invoice resource reservation and response replay | `IdempotencyService` / `IdempotencyReservation` | DB reservation and exact saved API response |
| Blockchain observation | `BlockchainProviderInterface` | Walletless address query, cache and single-flight |
| Persistent payment state | `PaymentWorker` / `InvoiceStateMachine` | Lease, observation, status and webhook outbox in one commit |
| Database checkout | `CheckoutRepository` / `DatabaseCheckoutService` | One DB snapshot and pure presentation; no wallet or RPC configuration |
| Stateless status | `BtcStatelessInvoiceManager` | Verified token address/amount/expiry plus provider; no loaded wallet |
| Webhook delivery | `WebhookProcessor` | Existing persistent outbox, retries and HTTP transport |
| Electrum wallet mutation serialization | Lowest mutation service with `WalletLockManager` | Shared per-wallet lock around ensure-loaded and mutating RPC only |
| RPC transport and dialect | `ElectrumRPC` / `ElectrumRPCFactory` | Explicit network, daemon or wallet scope |

`BtcInvoiceManager::checkDatabasePaymentStatus()` is deprecated and reads DB only.
Manual admin status writes are disabled: they bypassed both state rules and outbox
consistency. Admin presentation was not redesigned in this pass.

## Observation and state

`AddressPaymentObservation` contains integer satoshis with honest balance semantics:

- `confirmedBalanceSatoshis`: current confirmed address balance, nonnegative.
- `mempoolDeltaSatoshis`: signed current mempool delta. An outgoing unconfirmed
  spend can reduce the current balance.
- `currentBalanceSatoshis`: confirmed balance plus mempool delta, nonnegative.
- `observedAt`: time of the actual observation, preserved when stale cache is returned.

The provider normalizes inconsistent negative totals. The DTO validates its
contract; it does not silently clamp a value described as cumulative receipts.
The database columns are `confirmed_balance_sats`, signed `mempool_delta_sats`,
and `payment_observed_at`. Old HTTP amount aliases remain for presentation
compatibility; `current_balance` is the accurate name. No cumulative history is
claimed. Migrated old maxima remain payment evidence until refresh, but without
an observation timestamp checkout does not present them as current balance.

| Current status | Allowed next status |
| --- | --- |
| New | New, Processing, Expired, Settled |
| Processing | Processing, Settled |
| Expired | Expired, Processing, Settled |
| Settled | Settled |

A partial payment produces Processing, including after expiration. Processing
retains the fact that payment was observed even if a mempool transaction disappears
or funds are spent. A sufficient confirmed balance produces Settled. Settled is
terminal and excluded from further scans. The expiry of a checkout timer does not
write or invent a status transition.

The existing late-payment scan window is 24 hours after expiry, with a five-minute
poll interval for unpaid Expired invoices. New and Processing invoices are due
every 15 seconds. Persisted evidence of a partial payment is processed even outside
the late-payment window, then remains Processing. Payments first arriving after
that window require an explicit rescan/policy extension.

Current balances cannot prove outputs spent before the first observation. Do not
build a sweep/withdrawal accounting system on these balances. A future provider
can account for historical outputs without changing the worker ownership boundary.
Stateless tokens have no durable terminal-state memory: after funds are spent,
current-balance status alone cannot reproduce an earlier settlement.

## Atomic worker commit

The worker claims one invoice immediately before its RPC. It never leases a batch
that then waits behind earlier network calls. Each claim has a random token and a
DB-clock deadline. Providers declare a maximum operation duration; lease duration
is `max(60, provider maximum + 30)` seconds. Electrum's maximum includes its HTTP
timeout and lock/cache overhead.

Blockchain RPC runs outside a DB transaction. Afterwards one short transaction:

1. Locks and reloads the invoice row; checks the exact lease token and deadline.
2. Evaluates the current persisted status against the observation.
3. Saves the integer observation and permitted state transition.
4. Calls `WebhookDeliveryRepository::enqueueInTransaction()` on the same PDO.
5. Clears the lease and commits.

Outbox insertion failure or process death rolls back the observation and status.
A killed process leaves a bounded lease; its successor observes again and creates
the event in the same commit as the transition. Existing webhook registration
cutover, event uniqueness and delivery retries are retained.

## Idempotent invoice creation

Authenticate the store before replay. A store/key pair identifies a reservation;
a different request hash is 409. A connection-owned DB named lock serializes that
key, and is released by MySQL on connection death. Busy callers receive 503 and
retry the same key. Different keys proceed independently.

`Pending` reserves an invoice ID before address allocation. XPUB index reservation
and immutable creation snapshot commit together. Invoice INSERT and `Completed`
response persistence then share a transaction. Response-write failure rolls back
the invoice; a retry reuses its reserved ID, address, index, amount and timestamps.
The exact saved successful response is replayed. Safe deterministic application
4xx responses are saved as `Failed`; transient failures leave recoverable Pending
data. Do not automatically purge unresolved reservations.

Electrum allocation occurs outside the DB transaction. A crash between its mutation
and snapshot persistence can leave an unused address. It cannot create a second
invoice under the reserved ID. XPUB avoids this RPC gap entirely. Historical file-generator
index domains remain per store; do not configure the same XPUB/derivation branch in
independent stores that have independent counters.

## Electrum routing and shared directories

Every entrypoint uses `ElectrumRPCFactory::fromConfig()`. Transport construction
does not perform RPC. Configure:

```php
'rpc_wallet_param_key' => 'wallet_path', // upstream default; 'wallet' for an explicit adapter
'rpc_timeout' => 30,
'rpc_connect_timeout' => 5,
'rpc_scheme' => 'http',
```

Wallet commands carry an explicit path. `load_wallet` and `close_wallet` always use
their documented `wallet_path` argument, independently of wallet-command dialect.
`activeWallet` and `loadWallet()` selection are deprecated admin compatibility.
There is no mutation retry to guess a dialect. `callNetwork()` is used for address
balance and broadcast; `callDaemon()` is reserved for daemon lifecycle commands.
`ElectrumWallet::callWallet()` exposes explicit routing for additional upstream
commands. A mutating caller must own the shared lock around ensure-loaded plus RPC.
Payout preparation now follows that rule; this is not a completed exchange UTXO
reservation, reconciliation or withdrawal-monitoring implementation.

All PHP-FPM, API, stateless, worker and admin processes targeting the same wallets
must use the **same flock-capable shared directory** and canonical absolute daemon
wallet paths. The default is `<project>/var/locks`; production can set
`BTCPAY_WALLET_LOCK_DIR` to a stable shared path outside release directories. Across
hosts the shared mount must support cross-host flock. Independent local directories
on different hosts are not a shared lock backend. Do not alias wallet paths through
different symlinks/mount names across processes. There is no MySQL/file backend mix.

Likewise set `BTCPAY_BLOCKCHAIN_CACHE_DIR` (default `<project>/var/blockchain`) to a
shared directory for all observers. Cache keys include daemon endpoint and address.
Defaults are two seconds fresh, up to 30 seconds stale, 1.5 seconds lock wait, and
two seconds upstream-failure cooldown. A cache-miss leader makes one
`getaddressbalance` network RPC; waiters reuse cache. On lock timeout they return
bounded stale data or 503, never their own fallback query. Files/directories must
be writable by all participating service users; keep lock files in place while
processes run. No wallet lock is taken by XPUB creation or provider-based status.

The command contracts were checked against [Electrum JSON-RPC documentation](https://electrum.readthedocs.io/en/latest/jsonrpc.html)
and [upstream command definitions](https://github.com/spesmilo/electrum/blob/master/electrum/commands.py).
The latter defines wallet-path injection and the signed, current-balance semantics
of `getaddressbalance`; it is not a historical-receipts endpoint.

## Upgrade and validation

Historical stabilization baseline (not the full current upgrade path): for an
installation already at `cbeba360`, stop invoice/worker writers, apply
`migrations/004_core_payment_consistency.sql` then
`migrations/005_idempotency_resource_reservation.sql`, deploy the matching code,
and resume writers. Fresh installations use `sql.sql` and do not reapply these
migrations. Earlier installations must first reach the baseline schema.

Migration 004 clears old leases and scan scheduling, preserving payment evidence.
Migration 005 preserves completed response bodies. Anonymous old `response_code=0`
claims become Failed/409 for explicit reconciliation; blindly reexecuting them
could duplicate an invoice created by the old code.

Schedule `php payment_worker.php` independently of `php webhook_cron.php`.
The payment entrypoint is CLI-only and returns empty HTTP 404 before configuration
or DB access. Database checkout needs only `db_*` configuration. Stateless status
needs RPC configuration and the token signing secret, but no configured/loaded
wallet or wallet file; creation additionally needs an explicit wallet path.

Run `composer install` with the existing lockfile, then `php tests/run_all.php`.
For real persistence/concurrency and migration tests, set `BTCPAY_TEST_MYSQL_HOST`,
`BTCPAY_TEST_MYSQL_PORT` (default 3306), `BTCPAY_TEST_MYSQL_USER` (default root), and
`BTCPAY_TEST_MYSQL_PASS`. The test account must create/drop isolated test databases.
Tests use random `btcpay_core_*` names and never target the application's DB.
PHP needs PDO MySQL/SQLite, GMP/BCMath and, for process tests, pcntl/posix.
GitHub Actions supplies MariaDB and these extensions.

The acceptance suite covers all thirteen requested invariants, including actual
100-process bursts and SIGKILL during the transaction. At the stabilization
checkpoint all 53 test files passed with real MariaDB, and 213 PHP files passed
syntax checks. Electrum calls were controlled test doubles; an actual daemon
integration smoke test remains deployment validation.

## XPUB provisioning follow-up (2026-09-08)

DB-backed stores now share a durable sequence by public key/chain code across
stores and SLIP-0132 aliases (migration 006). New wallet provisioning exports
validated public metadata offline once. See [the follow-up audit](XPUB_FIRST_MULTI_WALLET_AUDIT.md)
for corrected ownership, installation, repair, verification and receive-range
synchronization limits. The per-store limitation above still applies to the
legacy file index store, not the DB sequence.

## Shared receive allocation and bounded synchronization

See [receive coordination](RECEIVE_COORDINATION.md) for migration 007, the durable
wallet binding, shared Greenfield/admin/stateless reservations, address-only v3
tokens and CLI-only range registration. Provider status still never initializes
the lazy receive database. External daemon writers must use the same allocator
or a different receive branch. RPC registration does not imply completed SPV sync.
