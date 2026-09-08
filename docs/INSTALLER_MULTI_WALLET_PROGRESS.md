# Installer and multi-wallet follow-up checkpoint

Starting main: 306df18. Do not commit the local Composer/vendor test-runtime files.

## Active work

1. Installer: actionable requirement failures and web PHP configuration details;
   actual fresh installation and first admin; recognize a pristine current sql.sql
   import without allowing takeover/reinstallation of a populated database.
2. Fix incomplete schema cleanup (api_idempotency_keys was missing), installer
   lock-file unlink race, and serialize installation into the same DB across roots.
3. Verify with MariaDB and real HTTP GET/POST/CSRF flow; publish a small checkpoint.
4. Then audit the attached multi-wallet proposals against actual current code.
   Many assumptions in that attachment predate completed core stabilization:
   loadWallet no longer closes peers; stateless status is walletless; there is no
   wallets table/user+store+wallet_id token contract. Keep the newer core architecture.
5. Check remaining admin/client/payout explicit routing, tenant authorization,
   wallet loading races and honest error reporting. Verify upstream RPC dialect
   before adding any query-URL compatibility adapter. No credentials or config.php
   from the user are to be committed.

## Runtime / publishing

Local runtime is ../core-runtime; run-mysql.py starts MariaDB and PHP tests in the
same invocation. The existing locked Composer dependencies are installed locally.
GitHub pushes use the connected Git-data API (create_tree, create_commit,
update_ref main force=false), then git fetch and mixed reset after fetch finishes.
Record test results and published commits here as work progresses.

## Installer checkpoint verified

- Completed actionable web-PHP diagnostics, actual config-write probe, missing
  Composer handling, explicit RPC dialect defaults and non-destructive adoption of
  a pristine imported schema (types, columns, indexes and InnoDB checked).
- New sql.sql uses STORED, supported by MySQL and MariaDB, instead of the MariaDB
  PERSISTENT spelling. No alteration of existing installed tables is needed.
- Cleanup derives its table list from sql.sql, including api_idempotency_keys.
  File lock inode is retained; a per-database MySQL lock serializes independent roots.
- InstallerIntegrationTest PASSED on real MariaDB: new/empty/imported database,
  exactly one hashed admin, config deletion takeover refusal, outdated schema
  refusal without deletion, injected DDL cleanup, actual HTTP GET/CSRF/POST/redirect.
- Existing InstallationManagerTest and InstallerHttpBoundaryTest PASSED.
- Publish this installer checkpoint next, then continue the multi-wallet audit.
- The user's exact failed local requirement is not accessible remotely. The updated
  page displays it and identifies the active web PHP configuration to repair.

## Multi-wallet implementation checkpoint

- Installer published as 7989b14; GitHub CI passed:
  https://github.com/agp-l/BTCPayServerLite/actions/runs/34148586338
- Verified upstream commands.py and daemon.py: JSON wallet_path selects the wallet;
  the HTTP handler ignores request.query. Do not replace explicit named routing
  with the attachment's URL-only assumption or introduce automatic mutation retry.
- Removed the production first-store query/defaultStore fallback. Admin creation
  requires an explicit store; provisioned Electrum stores now default to electrum,
  matching sql.sql, instead of incorrectly defaulting to xpub without an XPUB.
- Bound admin BtcDashboard to a fixed explicit path; exact balance strings now
  flow through admin/client balance displays. Its mutations share the existing lock.
- Benign load race does one read-only recheck; transport/auth failures propagate.
  No normal request closes another wallet. Explicit close remains maintenance-only.
- Admin wallet errors distinguish transport, authentication, wallet/path and request
  failures; invalid requested wallet never falls back to the default for a mutation.
- MultiWalletRoutingTest passed via actual HTTP JSON-RPC transport, three loaded
  wallets, 0.00008827 fixture balance, repeated load, load race, two processes and
  daemon/auth error classification. Existing wallet/dashboard/admin tests passed.
- Real MariaDB tenant test passed assigned-wallet/store/webhook/API-key isolation;
  extended test now also checks actual store-to-Electrum-address routing.
- Full local suite with MariaDB: 55 passed; the only failing test asserted the
  obsolete first-store fallback. That assertion now requires explicit store lookup
  and passed on rerun. All production behaviors and new integration tests passed.
- Source syntax validation: 217 PHP files, zero errors. Next publish this verified
  implementation, finish audit documentation and confirm the full GitHub CI run.

## 2026-09-08 follow-up: XPUB-first provisioning

- Baseline e88594d CI passed (56 test files):
  https://github.com/agp-l/BTCPayServerLite/actions/runs/34149766167
- Reviewed every production change in e88594d. KEEP explicit store selection,
  ownership, wallet_path RPC scope, exact amounts and error categories. FIX the
  missing provisioning-to-XPUB metadata flow and exclusive lock on loaded reads.
- New user instruction: publish coherent source checkpoints BEFORE tests, then
  record validation separately. Do not wait for the entire task to make a commit.
- First checkpoint: loadWallet validates and checks list_wallets before locking;
  only an unloaded target takes the shared per-wallet lock and rechecks within it.
  Tests pending for this new change at publication.
- Next: structured provisioning result, export/validate public key once, persist
  metadata in all store creation paths, explicit repair command for existing data.
  Preserve conservative SQL default electrum for incomplete historical rows.
- Important design risk to resolve: several stores can share a single wallet/XPUB;
  their receive-index allocation must not restart independently and reuse addresses.

### XPUB source checkpoint (publish before tests)

- load-read fix published as 6b74b80; validation to follow.
- Fresh wallet provisioning now returns validated public receive metadata. CLI
  creates offline, exports getmpk and receiving addresses offline exactly once;
  no seed/private output is retained and no daemon wallet needs loading for setup.
- Electrum public-key prefixes determine script type (xpub/tpub = p2pkh,
  ypub/upub = nested SegWit, zpub/vpub = native SegWit). Unsupported/invalid public
  exports fail provisioning; they do not silently select Electrum generation.
- Admin, registration and client store repositories persist/reuse public metadata.
  Legacy wallets remain explicitly electrum until an operator runs repair.
- Added migration 006_shared_xpub_address_sequences.sql, also in sql.sql. Shared
  key/chain-code sequences prevent different stores/key-prefix aliases from
  restarting the same receive branch. Existing high water seeds the sequence;
  the durable pool must survive deleting stores. Apply migration before this code.
- Tests pending at this checkpoint. Next: explicit existing-wallet repair command,
  actual CLI/public export fixture, no-lock load tests, shared-XPUB concurrency,
  adapt existing high-water assertions and installer table count to new schema.

### Repair checkpoint (before validation)

- XPUB provisioning/sequence checkpoint published as 0893a5c.
- Added CLI-only repair_store_xpub.php: one explicit store; default inspect/plan,
  --apply --maintenance after pausing writers for that wallet. Reads outside DB
  transaction; compares current store, preserves high water, never overwrites a
  different public key and never silently falls back after an RPC error.
- Verify first and last Electrum receiving addresses against local derivation
  before accepting an exported XPUB, both during new provisioning and repair.
- Installer parser now recognizes IF NOT EXISTS sequence DDL; table-count tests
  derive expected count from schema. Existing shared-key tests expect global
  high-water indices instead of independent per-store restarts.
- Sequence initialization scans historical stores only when a pool is absent.

### Test checkpoint (committed before execution)

- Repair/branch validation published as 1a60544.
- Added real offline CLI process test, public-only result/cleanup checks, unsupported
  key and receive-branch rejection; shared-key prefix identity checks.
- Multi-wallet HTTP fixture now reads successfully while the same mutation lock is
  held, and two processes perform the first load exactly once.
- Real DB tests now cover admin/client XPUB metadata propagation, client-created
  second store, zero RPC after provisioning, and 20 concurrent invoices across
  different stores sharing the same key (in addition to existing 100-way tests).
- Full baseline suite currently running locally on MariaDB; inspect results next.

### Verification in progress

- Tests published as dca767a. Offline CLI provisioning test PASSED.
- Full first local MariaDB run: 55 passed, one old ElectrumWalletTest fixture
  needed the new second list_wallets response for the unloaded slow path. Updated
  that fixture to model the read-before-lock plus read-under-lock sequence.
- Repair also preserves MAX invoice.address_index across all stores for the wallet,
  in addition to persisted counters and Electrum receiving-address high water.
- Run the full 57-file suite on this checkpoint; then finish documentation/CI.

## Verified checkpoint / resume here

- Main source 12306d7: full local MariaDB run PASSED, 57 files, 0 failures.
- GitHub CI PASSED: https://github.com/agp-l/BTCPayServerLite/actions/runs/34177553920
- Audit/upgrade/operator instructions: docs/XPUB_FIRST_MULTI_WALLET_AUDIT.md.
- Migration 006 must precede running new DB sequence code on an old database.
- No real localhost database/daemon/config was accessed or changed. Do not commit
  local vendor files. No source changes await testing at this checkpoint.
- Known remaining receive-range integration: Electrum does not learn local XPUB
  reservations automatically. Admin new_address, stateless requests and external
  address writers must not independently allocate from the same XPUB receive
  branch. See audit limits before a future sync/address-range coordination pass.
- Keep frequent small commits BEFORE tests and record validation afterwards.

## 2026-09-08 receive coordination follow-up (source checkpoint before tests)

- Baseline 2915df0; local vendor validation files retained and excluded from commits.
- Verified upstream: add_request chooses wallet.get_unused_address(), independent
  of local XPUB reservations. Native RPC cannot attach add_request to an arbitrary
  supplied address. Keep walletless status; use address-only token v3 instead.
- Added migration 007_wallet_receive_ranges.sql (also fresh sql.sql): durable
  wallet-path/public-key binding survives store deletion and supports sync progress.
- Unified wallet-authorized admin/stateless allocation with the existing shared
  DB sequence. Installed stateless factory opens DB lazily on creation only;
  provider status and standalone mode retain their previous dependency boundaries.
- Explicit Electrum stores sharing an XPUB-managed wallet now fail with a repair
  instruction rather than letting two independent generators reuse a receive branch.
- No tests run for this checkpoint yet. Next: bounded CLI range synchronization,
  mixed admin/stateless/Greenfield concurrency tests, old token compatibility,
  restart/crash recovery and updated operational documentation.

### Receive sync source checkpoint (before tests)

- Shared allocation published as a09f6fe. Added WalletReceiveSyncWorker and CLI-only
  wallet_receive_sync.php. Default 2 wallets / 25 new addresses / 10-second loop
  budget each; one in-flight RPC remains bounded by the configured timeout.
- Reads actual MPK/script/first+last receive addresses before extending a range.
  Uses explicit createnewaddress under the existing per-wallet lock; no gap-limit
  mutation, close_wallet, signing, invoice status change or DB transaction during RPC.
- Progress is a scheduling hint only. After crash/timeout/restart the next run
  re-reads the daemon; an uncertain mutating RPC is never immediately retried.
- Handles daemon-side auto extension by read-only reconciliation. Concurrent sync
  workers use the same non-blocking wallet lock; other wallets stay independent.
- Admin no longer recommends a zero-balance address as if it were unreserved;
  the existing new-address action returns the actual reserved public address.
- Repair now establishes the durable receive binding in its DB transaction.
- Next: mixed three-path concurrency and bounded-sync recovery tests, full suite,
  source audit and deployment instructions. External CLI users still must respect
  the application's receive allocator; no application can intercept arbitrary
  authenticated daemon commands issued outside its process ecosystem.

### Receive coordination tests checkpoint (before execution)

- CLI synchronization source published as 9ecf261.
- Added real-MariaDB 60-process mixed Greenfield/admin/stateless allocation test
  with forbidden RPC and forbidden XPUB wallet lock, exact shared high water,
  address-only v3 plus v1/v2 status, explicit mixed legacy rejection, and binding
  survival after stores are deleted.
- Sync tests inject lost response after daemon mutation, restore an older wallet
  while DB hints claim a newer range, reject wrong MPK, enforce batch size and
  simulate a competing worker holding the same wallet lock.
- HTTP guard test now covers payment worker, receive sync and repair endpoints.
- Run the new integration test, then the full suite; no test result claimed yet.

### Verified receive coordination; final snapshot guard checkpoint

- Source/test commit e381c5c passed all 58 files locally with MariaDB and in CI:
  https://github.com/agp-l/BTCPayServerLite/actions/runs/34178829353
- Runtime audit confirmed normal creation flows enter the coordinated allocator;
  only sync/explicit legacy primitives still invoke createnewaddress/add_request.
- Added a final reservation guard: a generator built from a stale store XPUB/script
  snapshot fails before reserving if DB configuration changed. This avoids deriving
  under one key/script while consuming another configuration's index.
- Added direct production stateless-factory test with deliberately invalid DB
  configuration; provider status must not initialize the lazy allocator/DB.
- Run the focused receive test after publication, record final CI and lint, then
  publish migration 007/sync operations documentation. No external daemon writes.

### CI fixture recovery checkpoint

- Focused receive test on 0bc0e08 PASSED locally, including stale store rejection
  and status with invalid DB config. Syntax: 228 PHP files, zero errors.
- CI 34179064772 stopped in the existing MultiWalletRoutingTest before its first
  RPC because its HTTP fixture never started listening. The test guessed a port
  in the ephemeral range and did not assert readiness. Reserve a free OS-selected
  port and fail with startup diagnostics if readiness is not reached. This is a
  fixture correction; no production RPC retry or timeout behavior was changed.
- Publish this fix before running it; then confirm CI and finish operations docs.
