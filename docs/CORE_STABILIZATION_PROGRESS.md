# Core payment stabilization — work checkpoint

Target: `main`, starting at `cbeba36019a24057ba09d0e7f79bd286b49f88f3`.
Scope: payment core only, no UI redesign or dependency changes.

## Completed implementation

- `c5c41b0`: explicit RPC composer/dialect; walletless stateless provider path;
  one file lock domain for stateless and Electrum address creation; DB-only
  checkout snapshots; one invoice state machine; short atomic worker transaction
  joining observation, status, webhook outbox and lease release; CLI-only worker.
- Current checkpoint: idempotency `Pending/Completed/Failed`, stable invoice ID,
  durable creation snapshot and XPUB index reservation. Invoice INSERT and exact
  API response completion share a transaction. Replay authenticates first.
- Provider uses one `callNetwork(getaddressbalance)` per refresh, bounded
  single-flight, stale-or-503 on contention and cross-process failure cooldown.
  Amounts describe current balance and signed mempool delta, not historical receipts.
- Corrected pre-existing calls to nonexistent `BitcoinAmount::toSatoshis()`;
  the actual contract is `satoshis()`.
- XPUB factory can operate without an ElectrumWallet. Payout preparation now
  explicitly routes the wallet and uses the shared mutation lock.

## Validation so far

- Initial source checkpoint: 208 PHP files passed syntax checks.
- Existing tests after early contract adaptations: 46 passed, 3 failed.
  Remaining failures then were outdated checkout/idempotency fixtures and the
  Greenfield test still expecting an outer API wallet lock.
- `IdempotencyServiceTest` and the rewritten checkout snapshot test subsequently passed.
- New `CorePaymentArchitectureTest` is written but has NOT run yet. It covers
  100-process single-flight, timeout backpressure, stateless wallet independence,
  shared mutation locks, state-machine rules and both RPC dialects.
- Full stabilization is NOT yet verified; do not describe all acceptance tests as passed.

## Next steps (resume here)

1. Finish Greenfield fixtures: expect no outer wallet lock or wallet load;
   inject busy failures at the invoice/generator boundary instead.
2. Run/fix `CorePaymentArchitectureTest` and existing tests.
3. Add/run real MariaDB integration tests for 100 XPUB indices, 100 identical
   idempotency requests, two worker processes, outbox rollback/crash injection,
   idempotency response-write failure recovery, DB-only checkout, and HTTP 404 worker.
4. Test migrations 004/005 against original schema and review pending consistency edges.
5. Document runtime config, ownership, current-balance limitations and migration order.
6. Publish small commits to main and update this checkpoint after each verified milestone.

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
