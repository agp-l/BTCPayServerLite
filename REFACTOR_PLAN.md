# BTC Pay Lite – Core PHP Architecture Refactoring Plan

**Branch:** `googleAIstudio`  
**Focus:** Evolutionary refactoring of core PHP classes for concurrency, multi-wallet Electrum, atomic XPUB address derivation, decoupled background payment monitoring, and removal of global lock serialization.

---

## 1. Architectural Principles
- **No Rewrite & No External Dependencies:** Evolutionary refactor of existing PHP classes. Preserve existing public methods for backwards compatibility.
- **Strict Concurrency:** Eliminate global serialization locks (`GET_LOCK('electrum_rpc')` and `btcpay_electrum_stateless.lock`). Wallet A must never block Wallet B.
- **Clean Separation of Concerns:**
  - Address Generation (`AddressGeneratorInterface`, `ElectrumAddressGenerator`, `XpubAddressGenerator`, `AddressGeneratorFactory`).
  - Atomic Index Reservation (`AddressIndexStoreInterface`, `DbAddressIndexStore`, `FileAddressIndexStore`).
  - Blockchain Monitoring (`BlockchainProviderInterface`, `ElectrumBlockchainProvider`, `AddressPaymentObservation`).
  - Payment Processing (`PaymentWorker` with atomic claim and monotonic CAS status transitions).
- **Zero Electrum RPC in HTTP Checkout:** Checkout and polling read from the database/cache only.

---

## 2. Implementation Roadmap

### Phase 1: Electrum Multi-Wallet & RPC Compatibility Layer
- [ ] **`ElectrumRPC.php`**:
  - Remove mutable global transport state `activeWallet`.
  - Classify commands into Daemon commands, Wallet commands, and Network commands.
  - Implement explicit wallet parameter handling (`wallet_path` or `wallet` compatibility) per request.
  - Retain backwards-compatible methods (`setWallet()`, `callForWallet()`, `call()`).
- [ ] **`ElectrumWallet.php`**:
  - Refactor `loadWallet()` so it never closes or unloads other wallets.
  - Support explicit wallet passing to all wallet-scoped methods.
  - Preserve all existing public methods (`getNewAddress`, `getWalletBalance`, `getAddressBalanceExact`, `createTransaction`, `sendPayment`, `createPaymentRequest`, etc.).
- [ ] **`ElectrumWalletManager.php`**:
  - Provide multi-wallet management, tracking loaded wallets in daemon, and returning configured wallet instances.
- [ ] **`WalletPathResolver.php`**:
  - Security hardening: resolve and canonicalize wallet paths using `realpath`, guaranteeing the path resides strictly inside the configured `store_wallets_dir`.

### Phase 2: Eliminate Global Serialization Locks
- [ ] **`WalletLockManager.php`**:
  - Implement per-wallet exclusive locks (`electrum_wallet_<hash>`).
  - Lock wraps ONLY mutating operations (`createnewaddress`, `payto`, `broadcast`, `load_wallet`).
  - Wallet reads and network reads run completely lock-free.
  - Fast timeout (e.g. 2-5s) yielding HTTP 503 `Retry-After` if a wallet is busy, preventing PHP-FPM thread exhaustion.
- [ ] Remove all global `GET_LOCK('electrum_rpc')` and file lock `btcpay_electrum_stateless.lock` across `api_stateless.php`, `admin/url_invoices.php`, `GreenfieldApiService.php`, `DatabaseCheckoutFactory.php`, and `PayoutService.php`.

### Phase 3: Address Generation Decoupling & XPUB Derivation
- [ ] **Interfaces and DTOs**:
  - `AddressGeneratorInterface`
  - `GeneratedAddress` (address, source, index, derivationPath)
  - `AddressGenerationContext` (storeId, walletPath, memo, network, etc.)
- [ ] **`ElectrumAddressGenerator.php`**:
  - Generates addresses via Electrum RPC with per-wallet locking and routing.
- [ ] **`XpubAddressGenerator.php`**:
  - Pure PHP BIP32/BIP84/BIP44 hierarchical deterministic derivation without calling Electrum or launching external processes.
  - Supports Native SegWit (P2WPKH - `zpub`/`xpub`), Nested SegWit (P2SH-P2WPKH - `ypub`), and Legacy (P2PKH - `xpub`).
- [ ] **`AddressGeneratorFactory.php`**:
  - Routes to either `XpubAddressGenerator` or `ElectrumAddressGenerator` according to store configuration.

### Phase 4: Atomic Address Index Reservation
- [ ] **`AddressIndexStoreInterface.php`**:
  - `reserveNextIndex(string $storeId): int`
  - Atomic, race-condition-free index generation.
- [ ] **`DbAddressIndexStore.php`**:
  - Canonical database index store using row-level locking (`SELECT ... FOR UPDATE` or atomic `UPDATE stores SET xpub_last_index = xpub_last_index + 1`).
- [ ] **`FileAddressIndexStore.php`**:
  - File-based counter for stateless XPUB using short `flock()` during increment.

### Phase 5: Store Address Source & Database Migration
- [ ] **Database Migration (`migrations/20260905_add_xpub_and_address_source.sql`)**:
  - Add `address_source` ('xpub', 'electrum'), `xpub`, `xpub_script_type`, `xpub_derivation_path`, `xpub_last_index` to `stores`.
  - Add `address_source`, `address_index`, `derivation_path` to `invoices`.
  - Add `UNIQUE KEY uq_store_xpub_index (store_id, address_index)`.
- [ ] **`BtcInvoiceManager.php`**:
  - Integrate `AddressGeneratorFactory`.
  - Never fallback from XPUB to Electrum if XPUB is configured and fails.
  - Store `address_source`, `address_index`, and `derivation_path` with the invoice.
  - Preserve all existing public methods.

### Phase 6: Payment Monitoring & Observation Decoupling
- [ ] **`BlockchainProviderInterface.php`**:
  - Methods: `observeAddress(string $address, int $expectedSats): AddressPaymentObservation`.
- [ ] **`ElectrumBlockchainProvider.php`**:
  - Uses walletless `getaddressbalance` and `getaddresshistory` (or `blockchain.address.get_history`) without loading wallets.
- [ ] **`AddressPaymentObservation.php`**:
  - Financial amounts represented strictly in integer satoshis.
  - Inspects history so spent funds cannot revert a Settled invoice.

### Phase 7: Background Payment Worker & Database Checkout Decoupling
- [ ] **`PaymentWorker.php`**:
  - Atomic claim of due invoices (`SKIP LOCKED` or lease token).
  - Monotonic status transition (CAS: New -> Processing -> Settled; Expired).
  - Webhook dispatch on status change.
  - Adaptive polling with jitter.
- [ ] **`DatabaseCheckoutService.php`**:
  - Decouple checkout polling: HTTP requests read only from the database, never invoking Electrum RPC directly.

### Phase 8: Stateless Status Cache & Single-Flight Coalescing
- [ ] **`StatelessPaymentCoalescer.php`**:
  - Per-address single-flight barrier (using file or memory mutex) so 100 concurrent checks for the same address perform only 1 Electrum RPC call.
  - Short-lived TTL cache for address observations.

### Phase 9: Idempotency & Webhook Idempotency
- [ ] **`IdempotencyService.php`**:
  - Support `Idempotency-Key` header scoped per store `(store_id, idempotency_key)`.
  - Returns existing invoice before triggering new address generation.
- [ ] Verify webhook delivery worker consistency.

---

## 3. Progress Tracking
- [x] Initial Repository & Git Branch Setup (`googleAIstudio`)
- [x] Refactor Plan Documentation
- [ ] Phase 1: Electrum Multi-Wallet & RPC Compatibility Layer
- [ ] Phase 2: Per-Wallet Exclusive Locking
- [ ] Phase 3: Address Generation & XPUB Derivation Engine
- [ ] Phase 4: Atomic Address Index Store
- [ ] Phase 5: Store Address Source & Database Migration
- [ ] Phase 6: Blockchain Provider & Satoshis Payment Observation
- [ ] Phase 7: Background Payment Worker & HTTP Read Decoupling
- [ ] Phase 8: Stateless Single-Flight & Observation Cache
- [ ] Phase 9: Idempotency & System Hardening
