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
