# Core payment stabilization — work checkpoint

Target: `main`, starting at `cbeba36019a24057ba09d0e7f79bd286b49f88f3`.
Scope: payment core only, no UI redesign or dependency changes.

## Completed implementation

- `c5c41b0`: explicit RPC composer/dialect; walletless stateless provider path;
  one file lock domain for stateless and Electrum address creation; DB-only
  checkout snapshots; one invoice state machine; short atomic worker transaction
  joining observation, status, webhook outbox and lease release; CLI-only worker.
- `aa295e8`: idempotency `Pending/Completed/Failed`, stable invoice ID,
  durable creation snapshot and XPUB index reservation. Invoice INSERT and exact
  API response completion share a transaction. Replay authenticates first.
- Provider uses one `callNetwork(getaddressbalance)` per refresh, bounded
  single-flight, stale-or-503 on contention and cross-process failure cooldown.
  Amounts describe current balance and signed mempool delta, not historical receipts.
- Corrected pre-existing calls to nonexistent `BitcoinAmount::toSatoshis()`;
  the actual contract is `satoshis()`.
- XPUB factory can operate without an ElectrumWallet. Payout preparation now
  explicitly routes the wallet and uses the shared mutation lock.

## Verified checkpoint (2026-09-07)

- All **53 test files passed, 0 failed**, with real MariaDB 10.11 enabled.
  PHP runtime: 8.3. Integration tests used isolated disposable databases, never production.
- 100 concurrent XPUB creates: 100 addresses and unique indices; zero Electrum
  calls and zero wallet locks. Generator and bitcoin-p8 remain unchanged.
- 100 concurrent identical idempotency requests: one invoice/address/index and
  identical response. Response-write failure rolls back invoice insertion;
  retry reuses the durable resource snapshot. Unauthorized replay and hash conflict covered.
- 100 concurrent walletless stateless checks: one refresh RPC. Slow refresh:
  bounded stale cache or 503, no second query. Same-wallet mutation serialization,
  independent wallets, explicit wallet routing and both dialects covered.
- Two worker processes claim one invoice once. Blockchain observation occurs
  outside the transaction; lease exceeds the provider's declared maximum duration.
- SQL outbox failure and actual SIGKILL immediately before enqueue roll back
  status/observation. Recovery produces exactly one webhook event.
- Processing never regresses; Settled remains terminal. Upgrade from the exact
  original cbeba360 schema preserves late partial-payment evidence, settlement
  and exact completed idempotency response bytes. Anonymous legacy claims become Failed/409.
- Checkout works without RPC config, reads DB only; deprecated monitoring facade
  is read-only. Actual HTTP request to payment_worker.php returns empty 404.
- GitHub Actions now configures MariaDB and required test extensions so the
  persistence and migration tests run there rather than silently skipping.

## Next steps (resume here)

1. Publish this verified tests/migration checkpoint on main.
2. Finish ownership/deployment documentation and correct stale README claims.
3. Verify published GitHub Actions and document any environment-only limitation.
4. Keep small commits and update this checkpoint; do not restart the implementation.

## Operational notes

- Apply `004_core_payment_consistency.sql` and
  `005_idempotency_resource_reservation.sql` with API/worker writers stopped.
- All processes targeting an Electrum daemon must share the SAME flock-capable
  `BTCPAY_WALLET_LOCK_DIR` (default `<project>/var/locks`) and canonical wallet paths.
  Across hosts this requires a common mount with working cross-host flock.
- `BTCPAY_BLOCKCHAIN_CACHE_DIR` must be shared for cross-process single-flight.
- `rpc_wallet_param_key` defaults to upstream `wallet_path`; `wallet` is explicit
  adapter compatibility. `load_wallet`/`close_wallet` keep their documented
  `wallet_path` argument. No mutation retries or implicit dialect probing.
- Current-balance observations cannot prove receipts already spent before the
  first scan. Settled is protected by persistent state. Stateless status has no
  durable terminal-state memory. History/output accounting is a future extension.
- A process killed after Electrum generated an address but before it persisted
  the resource snapshot can leave an unused address; it cannot create a second
  invoice under the reserved ID. XPUB index + snapshot are atomic in the DB.
- Legacy anonymous idempotency claims are marked Failed/409 for reconciliation;
  migration must not blindly re-execute potentially completed old operations.

## Development environment recovery

The scratch Git checkout survives interruptions; `/tmp` was cleared on resume.
Use scratch (outside Git) for reproducible local runtime dependencies.
Current runtime is in `../core-runtime/`; `run-mysql.py tests/run_all.php`
starts MariaDB and all tests together. `php.ini` enables the extracted extensions.
PHP 8.3 CLI/extensions and MariaDB 10.11 were obtained as Ubuntu .deb packages
and extracted without system installation. Enable PDO/mysql/sqlite, gmp, bcmath,
curl, mbstring, ctype, iconv, tokenizer, phar, dom/xml/simplexml and fileinfo.
The project dependencies must match composer.lock (`shanelic/bitcoin-p8` retained).
Local Composer/vendor changes are validation artifacts: do NOT commit vendor.

Network/process namespaces appear isolated between shell tool invocations.
Start test MariaDB and its PHP integration tests in the SAME shell invocation.
MariaDB can run TCP-only with `--socket=`; Unix socket creation is unavailable.
Initialize with bootstrap SQL prefixed by `CREATE DATABASE mysql; USE mysql;`.
No production database or Electrum daemon has been contacted.

Direct Git push lacks CLI credentials. Publish via the connected GitHub Git-data
API: create_tree(base tree + changed file content), create_commit(parent main),
update_ref(main, force=false). Then public git fetch and mixed reset to the new
main commit while retaining any later unstaged edits. Never force push.
