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

## Receive coordination verified / resume here

- Last source/test main: ab96acd. Final full CI PASSED:
  https://github.com/agp-l/BTCPayServerLite/actions/runs/34179267951
- Focused receive test on 0bc0e08 and corrected HTTP routing fixture PASSED locally.
  Full preceding 58-file MariaDB suite passed; final CI includes all subsequent
  guards/tests. PHP syntax: 228 files, zero errors.
- Operational guide: docs/RECEIVE_COORDINATION.md. Migration 007 required on top
  of 006. Fresh sql.sql and installer include both. No user DB/config modified.
- Installed admin/stateless/Greenfield now share the receive sequence. Explicit
  legacy Electrum stores on a managed wallet fail closed until repaired. No
  source implementation work is uncommitted or awaiting its first verification.
- CLI sync deliberately registers bounded ranges, without increasing gap limit;
  registered does not mean blockchain synchronized. External daemon writers and
  standalone integrations without shared DB remain outside application control.
- Next substantive work, if requested: deployment smoke test against a disposable
  real Electrum wallet, operational backlog measurements, and later separate payout
  UTXO/broadcast recovery audit. Do not invent production credentials or operate
  the user's live daemon without the actual configured environment.
- Retain local Composer validation dependencies outside commits. Continue frequent
  small source commits before tests and keep this checkpoint current.

## Reported CLI PDOException: diagnosis checkpoint before tests

- User's installed CLI emitted only `Receive synchronization failed: PDOException`.
  This hides the SQLSTATE/driver code, so the actual local cause is not known.
- Added read-only --check-db: actual selected DB, CLI PHP/ini, server version,
  missing required tables/columns, migration filenames and key-hash collations.
  It validates the exact worker selection SQL with LIMIT 0 and no Electrum RPC.
- Normal CLI now checks schema before constructing the RPC client. Missing
  migrations produce actionable output instead of an anonymous exception class.
- PDO diagnostics preserve SQLSTATE/driver codes through wrapped exceptions and
  map common errors safely, without raw SQL values/passwords/RPC responses.
- Source checkpoint is published before tests. Next reproduce missing migration,
  partial schema and healthy --check-db through actual PHP CLI against MariaDB;
  verify unknown SQL errors do not expose sensitive exception messages.

## Receive SQL diagnostics verification

- Source published on main as ecdb1fb, tests separately as e23cf1a before execution.
- User confirmed SQL was not upgraded; missing migrations are a likely cause,
  not a verified observation of their localhost database.
- ReceiveSyncDiagnosticsTest and ReceiveCoordinationTest PASSED on isolated
  MariaDB: missing 006/007 reproduced; importing those exact migrations restores
  the actual CLI check; no RPC configuration or DB writes needed; partial table
  columns detected; wrapped SQLSTATE/driver codes retained without raw secrets.
- Existing mixed 60-process allocation and bounded sync recovery tests also passed.
- docs/RECEIVE_COORDINATION.md now gives phpMyAdmin upgrade/check-db steps and
  explains config-selected DB and PHP CLI/XAMPP differences. No user DB changed.

## Administrator schema upgrade tool: source checkpoint before tests

- User's --check-db passed on btcpay_lite, MariaDB 10.4.32. Empty wallets means no
  registered range is due, not daemon discovery. Explicit --wallet can resolve
  an existing XPUB store; it cannot repair a legacy Electrum store implicitly.
- Added database_upgrade.php: current active admin/session-version check, CSRF,
  read-only schema preview, one reviewed migration per POST, displayed-plan hash.
- Catalog 001–008; historical/unknown migrations remain manual. Partial effects
  block automatic replay. Source sql.sql remains a fresh-install schema only.
- Added durable schema_migrations journal (008 and fresh schema), DB advisory
  lock, checksums, Running/Applied/Failed and last completed statement. Interrupted
  DDL is not automatically retried; data migrations need stopped writers/backup.
- Comparison scope is explicit: tables, InnoDB, column type/NULL, required indexes,
  explicit collations; not defaults/FK/CHECK/data backfill proof. No live DB touched.
- NEXT: publish source before tests; verify HTTP authorization/CSRF and real MariaDB
  fresh/006+007 missing/partial upgrade/replay/stale plan/failure cases.

## Database upgrade verification checkpoint

- Source main 71813f1, initial tests/idle explanation ff4759d; committed before
  verification. Actual MariaDB tests PASSED: fresh compare, 006/007 import, journal,
  lock contention, stale plan, repeated POST, partial schema, collation mismatch,
  interrupted/failed DDL, checksums, HTTP admin/CSRF/revoked account boundary.
- InstallerIntegrationTest and ReceiveSyncDiagnosticsTest also PASSED with the
  new 16-table fresh schema. Source still contains no user config or secrets.
- Added actual escaped SQL preview and execution of the journal DDL from the
  already reviewed plan snapshot. docs/DATABASE_UPGRADE.md covers scope and recovery.
- CLI now explains no_registered_wallets versus no_wallets_due. To initialize an
  existing XPUB store binding, use --wallet with that store's actual wallet path.
- Pending verification: final SQL preview change rerun plus CI. Automatic migration
  support is intentionally explicit 001–008; historic/backfill scripts are manual.

## Latest verification and handoff

- Final feature main: 6382a90. SQL preview/journal snapshot change PASSED the real
  DatabaseUpgradeTest again (including actual HTTP POST and crash recovery).
- Full CI on ff4759d PASSED:
  https://github.com/agp-l/BTCPayServerLite/actions/runs/34205427429
- Full CI for final 6382a90 was still running at this checkpoint:
  https://github.com/agp-l/BTCPayServerLite/actions/runs/34205713847
- No source implementation remains uncommitted. User deploys with git pull and
  opens /BTCPayLite/database_upgrade.php after normal admin login. Their database
  and Electrum daemon have not been accessed or changed by this session.
- Local vendor changes are validation dependencies only; keep out of commits.

## Store creation failure: first source checkpoint

- User reports generic failure in admin/client; their real PHP/web log has not been
  supplied. Found deployment gap: git does not contain bitcoin-p8/ECC dependencies;
  Composer install is required, and installer omitted GMP/dependency checks.
- Added XpubRuntime requirement checks to installer, XPUB generator and before
  spawning Electrum provisioning. Missing runtime now fails before creating a key.
- Added safe categorized store errors (PHP/dependency/path/provisioning/DB) in admin
  and client; no raw subprocess stdout/stderr, seed material or SQL values exposed.
- Next: expose read-only actual web PHP preflight to admin, run real store insert
  and provisioning integration tests, publish checkpoints before verification.

## Store creation runtime verification

- Source published as 0380fc2, web diagnostics/tests as 9b0e582 before verification.
- StoreCreationIntegrationTest PASSED against isolated MariaDB: first admin-created
  client store, second shared store and client-created store keep XPUB metadata and
  one wallet assignment; schema errors identify SQL cause in both flows.
- PHP -n test reproduces absent GMP and returns a safe actionable category before
  spawning Electrum. Missing executable and sensitive PDO-message suppression pass.
- WalletProvisioningTest, InstallerIntegrationTest and DatabaseUpgradeTest PASSED.
  No real user daemon, DB, wallet or permissions were changed.
- User should deploy code, run composer install with the lockfile, then inspect
  actual web PHP in database_upgrade.php and retry store creation. Do not claim
  their precise local cause is established without that diagnostic output.
- docs/STORE_CREATION_TROUBLESHOOTING.md records XAMPP/system PHP distinction and
  operational repair paths. No dependency upgrade or wallet encryption change.

## Final store-runtime checkpoint

- Main source/test revision: 11ef131. Full local suite PASSED: 61 files, 0 failures.
- CI initially caught a brittle English timeout-message assertion in
  ClientUiBoundaryTest. It now checks the stable electrum_create_timeout category
  and proc_terminate; no production timeout protection was removed.
- Final CI run: https://github.com/agp-l/BTCPayServerLite/actions/runs/34267097755
  (still running when this checkpoint was written).
- All source changes are published. Only local validation vendor dependencies stay
  uncommitted. No private user config is tracked by this work.
- Deployment next step: git pull --ff-only; composer install --no-dev --prefer-dist;
  inspect the web-runtime section of database_upgrade.php, then retry creation.
  Exact root cause on user's XAMPP still requires their new diagnostic result.

## Offline provisioning and live daemon lock (2026-09-08)

- User fixed GMP/dependencies and config read ACL for PHP user daemon. The next
  reproduced error is Electrum rejecting --offline with a daemon lockfile.
- Verified upstream run_electrum handle_cmd offline branch: it refuses a lockfile
  before executing any command, including create/getmpk/listaddresses/version.
- Provisioning now uses a private 0700 random temporary data directory per call
  for all three offline commands. Final wallet path remains explicitly managed.
- Only boolean chain-selection keys are carried from the configured data dir;
  daemon secrets, lockfiles, plugins and wallet defaults are not copied. Cleanup
  runs on success/failure and does not follow symlinks or remove the wallet.
- Source checkpoint committed BEFORE tests per user request. Regression testing
  pending: daemon lock remains intact, offline paths isolated, success/error cleanup.
- Based on remote 97b97d5 (user committed Composer packages); using isolated
  worktree provisioning-fix to preserve prior local validation vendor files.

## Offline provisioning verification checkpoint (2026-09-09)

- Source published to main as adec1c5; regression/docs as 1e34c6c.
- Focused local checks PASSED: WalletProvisioningTest, ClientUiBoundaryTest
  (10 assertions/groups), StoreCreationIntegrationTest against isolated MariaDB.
- Regression uses an executable CLI fixture that refuses offline access whenever
  its selected directory contains a daemon lock. All three commands instead use
  one private directory per creation; different creations have different dirs.
- Verified 0700 permissions, no copied RPC secret, unchanged live lock/config,
  cleanup after success, invalid public metadata and nonzero create exit. Cleanup
  does not follow a simulated symlink back to the live data directory.
- User's actual Apache/Python/Electrum installation has NOT been exercised here.
  Deployment: git pull --ff-only, retry admin store creation. No migration,
  daemon restart or lockfile deletion is needed for this fix.
- Worktree: /workspace/scratch/829e566c9a88/provisioning-fix. Original workspace
  remains untouched with its local validation dependency modifications.

## Wallet address action diagnosis (2026-09-09)

- User can read balance/seed, but cannot create an address. Exact local error
  and whether selected wallet has an XPUB binding are still unknown.
- Confirmed controller defect: new_address failures fell into WalletBalanceError
  and stopped dashboard reads, misreporting address/DB failures as balance errors.
- Added safe action-specific diagnostics traversing wrapped SQL, RPC, runtime
  and lock failures; controller now continues ordinary reads after allocation
  fails. No raw exception messages or keys are sent to UI/logs.
- Allocation algorithm/sequence is unchanged; no fallback to bypass a failed XPUB
  reservation. Source checkpoint before tests; local root cause still needs new
  address error code from user after deployment.

- WalletAddressErrorTest PASSED for nested SQL error 1146, missing GMP, wrapped
  wallet locks/RPC failures, XPUB conflicts and suppression of raw messages.
- BtcDashboardTest PASSED (6 cases). ReceiveCoordinationTest PASSED with isolated
  MariaDB: 60 concurrent mixed API/admin/stateless allocations, unique addresses,
  zero wallet RPC/locks for XPUB, persistent binding and bounded receive sync.
- No local allocation failure reproduced. User must pull main, retry New address
  and send the new address-specific error code; do not claim the underlying local
  cause is fixed. The action error appears at the top while balance reads continue.

## Repeatable deployment toolkit (2026-09-09, source checkpoint)

- User confirmed shared lock ACL fix restored address creation; now requests a
  reproducible deployment instead of remembering console fixes from this thread.
- Added bin/deployment.php (CLI-only, bootstrap diagnostics without installed vendor)
  and DeploymentEnvironment: current runtime checks plus reviewable permissions
  script generated from config paths and explicit web/worker/Electrum accounts.
- Permission plan preserves ownership/lockfiles, sets existing and default ACLs
  for wallet locks, blockchain cache and managed wallets; config access read-only.
- Web StoreCreationDiagnostics now includes locks, existing files, cache, temp
  directory, config-file readability and PHP curl/PDO. Daemon data dir no longer
  falsely requires PHP write access after offline context isolation.
- Pending: deployment guide, XAMPP GMP recipe, web link and tests. Source committed
  before verification. Tool generates a script; it never invokes sudo or SQL/RPC.

## Deployment toolkit delivery checkpoint

- Source on main 22664b8; guide, pinned XAMPP recipe, admin link and tests 506ac48.
- DeploymentEnvironmentTest PASSED; shell syntax check for GMP recipe PASSED.
- StoreCreationIntegrationTest and DatabaseUpgradeTest PASSED, including real
  HTTP admin authentication/CSRF/read-only GET and isolated MariaDB migrations.
- The GMP recipe was NOT compiled/installed in this environment; it records the
  user's working 8.0.30 approach, pins official source checksum and verifies the
  built module against target PHP before installation. Other PHP versions reject.
- Added fresh-installer config.php 0600 bootstrap instructions: run generator as
  web account, then execute reviewed permission plan. No PHP config execution as root.
- User confirmed previous address failure resolved by shared lock ACL. Next use:
  git pull; php bin/deployment.php --check; read docs/DEPLOYMENT.md. Actual server
  preparation remains an explicit local operation; no local user infrastructure
  was changed from this environment.

## Login persistence source checkpoint (2026-09-09)

- User requests fewer admin logins; existing limits 30m idle/12h absolute confirmed.
- Normal idle limit increased to 8h (absolute 12h retained). PHP file sessions use
  an application/UID-private subdirectory with matching GC lifetime, avoiding other
  apps' short GC in a shared session pool. Existing login may need one fresh login.
- Optional 30-day remembered login uses a separate random device token with hashed
  validator in DB, fixed expiry, rotation on restore and 30s previous-token grace
  for concurrent requests. User status/session_version checked at restore.
- Migration 009 adds remembered_logins; fresh sql.sql and migration catalog updated.
- Restoration only on GET/HEAD; POST/CSRF boundaries preserved. Logout revokes the
  device token. Source checkpoint before tests; verification pending.
- Read user's AI audit attachment as suggestions. Confirmed generic xpub/tpub
  defaults currently differ from explicit Electrum provisioning; targeted review
  queued after finishing session feature. Existing zero-RPC/shared-index architecture
  and read fast-path remain intact; no broad rewrite requested.

## Session and targeted core review verification

- Session source published 415e579; XPUB explicit policy 8ca50f9.
- Full suite PASSED: 64 files, 0 failures, isolated MariaDB enabled, including
  real HTTP remembered token restoration/rotation/logout and POST rejection.
- Added docs/SESSION_LOGIN.md and CORE_TARGETED_REVIEW_2026_09.md with call-site
  classification, deployment instructions and explicitly deferred items.
- Last narrow repair correction after suite: preserve shared sequence in reported
  and stored repair floor; reject mismatching explicit policy on existing XPUB store.
  Relevant syntax/static checks pending; no live user repair run.
- User must apply migration 009 via existing updater, then log in once with the
  30-day checkbox. Normal login without checkbox works without migration 009.

## Final checkpoint: remembered login and narrow XPUB audit

- Full pre-repair suite: PASS 64/64 with MariaDB. Post-repair targeted actual CLI
  test LegacyRepairPolicyTest: PASS for floor=100 despite Electrum count=2 and
  rejection of existing-store script mismatch. New total test files: 65.
- PHP lint: PASS 245 files before adding the final tested repair fixture.
- AuthManagerTest manual includes updated for RememberedLogin; no production
  autoload failure was present. HTTP fixture verifies actual cookie lifecycle.
- Normal login, optional 30-day device login and migration 009 ready for deployment.
- Still not claimed: execution on user's real Apache/Electrum, automatic cleanup
  of uncertain provisioning failure, admin request snapshot optimization.

## Admin database upgrade integration — source checkpoint

- Canonical admin route `/admin/database_upgrade`, menu Nástroje → Aktualizace systému.
- Controller in admin/database_upgrade.php; HTML in admin/views/database_upgrade_view.php,
  shared admin header/footer/CSS. Root database_upgrade.php is a compatibility 308 alias.
- Front controller owns role/account/session-version validation. Direct handler access is
  denied; migration CSRF, backup/maintenance confirmations, plan hash and DB lock retained.
- Source committed before validation. Next: route and real HTTP/MariaDB regression checks.

### Admin upgrade integration — completed verification

- 14 routing checks passed, including GET/HEAD/POST admin protection and legacy alias.
- Real HTTP/MariaDB integration passed: shared layout/assets and form target, anonymous
  rejection, direct handler rejection, CSRF enforcement, suspended account rejection,
  read-only migration preview and successful authorized upgrade. Existing migration
  locking, stale-plan, checksum, partial-DDL and journal checks also passed.
- Changed PHP files passed lint; no schema migration is required for this UI integration.
- User confirmed the page works on their installation. The final template groups schema,
  migrations and environment checks into shared admin cards; HTML remains separate from
  the controller. DatabaseMigrationManager remains the sole migration implementation.

## Payment monitoring admin — source checkpoint

- Added admin/payment_monitor controller/view and menu, DB-only GET, CSRF protected
  bounded manual run using the existing PaymentWorker (no shell or checkout RPC).
- CLI and manual runner share an instance DB advisory lock; invoice leases and atomic
  observation/status/outbox transactions remain unchanged. Manual requests are cooled
  down 15s and bounded to 20 invoices/12s; CLI to 100/45s (whole operation budget).
- Migration 010 runtime table records CLI/manual separately, including zero-work success,
  failures and interrupted runs. UI describes observed CLI activity, not installed cron.
- State machine suggestions are not applied: partial-payment policy would conflict with
  the explicitly agreed terminal/non-regressing transitions. No new payment semantics.
- Next: targeted DB/HTTP tests, deployment instructions and final checkpoint.

### Payment monitoring — validation and delivery checkpoint

- 67 test files passed with isolated MariaDB, 0 failures. Includes existing invoice
  lease concurrency and atomic webhook tests, new heartbeat/source/cooldown/recovery
  tests and actual admin HTTP GET/POST/CSRF/role revocation. Changed PHP lint passed.
- 010 upgrades an existing schema; fresh schema comparison passes. Fresh sql.sql uses
  a separate PRIMARY KEY definition required by the existing schema parser; the already
  published migration SQL remains unchanged (same resulting schema, checksum preserved).
- Added deployment.php --payment-systemd=service|timer, emitting reviewable units with
  explicit non-root worker user and selected PHP; no automatic system installation.
  Timer waits 15s after completion. CLI-only boundaries remain intact.
- docs/PAYMENT_MONITORING.md documents migration, manual limits, honest scheduler
  evidence, CLI --check scope, installation/recovery and unchanged partial/late policy.
- No access to the user's Linux deployment: OS timer installation and real Electrum
  smoke test must occur there. Local tests use isolated DB/provider fixtures.

## Payment failure diagnostics — source checkpoint

User journal proves timer executes every ~16s. Two observations fail every ~32s;
30s retry postponement explains intervening empty successful runs. Generic provider
busy error hid both cache permissions and underlying RPC errors; root cause on the
user host is still unknown. Added allowlisted cache/RPC/SQL diagnostics, no raw
upstream messages. Empty batches now retain the last error until a non-empty clean
batch, independently of successful process heartbeat. No migration or scheduler change.
Next: regression tests, publish, ask user for new reason from journal.

### Failure diagnostics — verified

- Cache directory/open/write failures and lock timeout are distinct; RPC authentication,
  timeout, protocol and missing command preserve typed causes without raw messages.
- Targeted tests passed: PaymentFailureDiagnosticsTest, PaymentWorkerMonitorTest,
  CorePaymentArchitectureTest (including 100 concurrent statuses/single-flight), and
  DatabaseUpgradeTest (actual admin HTTP and migration). Empty retry-window batches
  retain the warning; non-empty clean batches clear it.
- No database migration required. User must pull, then inspect a new scheduled failed
  run's reason. The original generic log does not establish whether their host has a
  permission failure, RPC failure, or contention; no blind permission changes made.

## 2026-09-10 — README and documentation cleanup checkpoint

- User supplied a successful scheduled run at 09:38:33 CEST: scanned 2,
  transitioned 2, expired 2, failed 0. Shared cache ACL repair resolved the
  observed deployment failure. This verifies expiry, not funded settlement
  or webhook delivery; previous diagnostic uncertainty is now resolved.
- Replaced the oversized outdated README with current installation, admin tools,
  worker ownership and explicit evidence limits. Extracted API/config reference,
  added documentation index, testing guide and prioritized ROADMAP.
- Archived the original refactor checklist with a compatibility pointer. Kept
  historical audit results, migration SQL, vendor and standalone Node/EJS demo
  unchanged. Demo has its own entrypoint, so removal is a separate decision.
- Added the confirmed cache ACL repair to deployment instructions, covering
  parent traversal, existing files and default ACL; never delete active locks.
- Source checkpoint before validation. Next: check changed links, verify claims
  against implementation and review diff; no PHP/SQL behavior changed.

### Documentation cleanup — validation complete

- Checked 66 local documentation links/anchors: no missing targets.
- git diff --check is clean after removing extraction/archive whitespace.
- Cross-checked current worker eligibility, balance semantics, stateless factory,
  payout broadcast boundary, deployment ACL generator and admin menu names.
- Marked the old architecture upgrade paragraph as a historical baseline and
  linked the current migration/monitoring guides; original test counts retained.
- No PHP, SQL, dependency or running deployment changes. Full runtime tests were
  not rerun for this documentation-only pass; no claim of funded payment or
  webhook end-to-end validation added. Next development steps are in ROADMAP.md.

## 2026-09-10 — Remove standalone Node/EJS demo (source checkpoint)

- Owner explicitly requested removal after the prior documentation-only pass.
- Removed server.js, its src/config.js and src/store.js, 20 root views/*.ejs
  templates, package.json, bun.lock and demo environment/metadata files.
- PHP and CI reference none of these files. The demo served shared assets but
  PHP uses those too: assets, PHP module views, Composer and vendor are retained.
- No OS packages uninstalled, database changes or wallet/config modifications.
- README and ROADMAP now describe the PHP-only application; historical demo
  discussion remains a record, not an outstanding task. Git retains the demo.
- Source committed before validation. Next: existing PHP routing/UI/checkout
  boundary tests, deleted-path reference scan and diff checks.

### Node demo removal — validated

- Six existing suites passed: FrontControllerBoundaryTest, RouteLinkBoundaryTest,
  AdminUiBoundaryTest, ClientUiBoundaryTest, CheckoutHttpBoundaryTest and
  StatelessCheckoutBoundaryTest (60 checks in total).
- All 27 removed demo paths are absent. No PHP or CI references to their
  entrypoint, modules, templates or package/metadata files remain.
- 26 README/ROADMAP local links resolve; git diff --check passes.
- assets, admin/client/checkout PHP, core classes, Composer and vendor have no
  diff against the pre-cleanup commit. No new runtime tests or migrations needed.
