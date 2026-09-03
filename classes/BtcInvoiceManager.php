<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Throwable;

/**
 * Creates and evaluates database-backed Bitcoin invoices.
 *
 * All monetary comparisons are performed in integer satoshis. Supports both XPUB
 * key-derivation and daemon-based address generation, as well as stateless invoices.
 * Monitoring and status evaluation operate without loading Electrum wallets.
 */
class BtcInvoiceManager implements BtcStatelessInvoiceGateway
{
    private const MAX_EXPIRATION_MINUTES = 43_200;
    private const MAX_METADATA_BYTES = 16_384;
    private const INTERNAL_REQUEST_ID_KEY = '_btcpaylite_electrum_request_id';

    private const ELECTRUM_STATUS_EXPIRED = 1;
    private const ELECTRUM_STATUS_PAID = 3;
    private const ELECTRUM_STATUS_UNCONFIRMED = 7;

    private ?ElectrumWallet $wallet;
    private BtcStatelessInvoiceManager $statelessManager;
    private ?Database $db;
    private Closure $clock;
    private ?AddressGeneratorInterface $addressGenerator;
    private ?BlockchainProviderInterface $blockchainProvider;
    private ?IdempotencyService $idempotencyService;

    public function __construct(
        ?ElectrumWallet $wallet,
        string $secretKey,
        ?Database $db = null,
        ?callable $clock = null,
        ?AddressGeneratorInterface $addressGenerator = null,
        ?BlockchainProviderInterface $blockchainProvider = null,
        ?IdempotencyService $idempotencyService = null
    ) {
        $this->wallet = $wallet;
        $this->db = $db;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);

        $this->addressGenerator = $addressGenerator;
        $this->blockchainProvider = $blockchainProvider;
        $this->idempotencyService = $idempotencyService ?? ($db !== null ? new IdempotencyService($db) : null);

        // Stateless manager can operate with wallet or without it if blockchainProvider is provided
        $this->statelessManager = new BtcStatelessInvoiceManager(
            $wallet,
            $secretKey,
            $clock,
            $addressGenerator,
            $blockchainProvider
        );
    }

    public function setAddressGenerator(AddressGeneratorInterface $generator): void
    {
        $this->addressGenerator = $generator;
    }

    public function setBlockchainProvider(BlockchainProviderInterface $provider): void
    {
        $this->blockchainProvider = $provider;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function createDatabaseInvoice(
        string $storeId,
        int|float|string $amountBtc,
        array $metadata = [],
        int $expirationMinutes = 15,
        ?string $idempotencyKey = null,
        ?string $preferredSource = null
    ): array {
        $database = $this->requireDatabase();
        $storeId = $this->validateIdentifier($storeId, 'Store ID', 50);
        $amount = $this->requirePositiveAmount($amountBtc);
        $expirationSeconds = $this->expirationSeconds($expirationMinutes);
        $now = $this->now();
        $expiresAt = $now + $expirationSeconds;

        $this->encodeJson($metadata, 'invoice metadata', self::MAX_METADATA_BYTES);

        // 1. Check idempotency: If an invoice with this idempotency key already exists for this store, return it
        if ($idempotencyKey !== null && $idempotencyKey !== '' && $this->idempotencyService !== null) {
            $existing = $this->idempotencyService->getExistingInvoice($storeId, $idempotencyKey);
            if ($existing !== null) {
                return $this->formatDatabaseInvoiceOutput($existing);
            }
        }

        $invoiceId = 'inv_' . bin2hex(random_bytes(16));

        // 2. Obtain address using AddressGeneratorInterface or fallback to Electrum reservePaymentRequest
        $address = '';
        $addressSource = 'electrum';
        $addressIndex = null;
        $derivationPath = null;
        $requestId = null;

        if ($this->addressGenerator !== null) {
            $context = new AddressGenerationContext(
                $storeId,
                $invoiceId,
                $amount->toSatoshis(),
                $expirationSeconds,
                $preferredSource
            );
            $generated = $this->addressGenerator->generate($context);
            $address = $generated->getAddress();
            $addressSource = $generated->getSource();
            $addressIndex = $generated->getIndex();
            $derivationPath = $generated->getDerivationPath();
        } elseif ($this->wallet !== null) {
            $request = $this->reservePaymentRequest(
                $amount,
                'Faktura ' . $invoiceId,
                $expirationSeconds
            );
            $address = $request['address'];
            $requestId = $request['request_id'];
            $addressSource = 'electrum';
        } else {
            throw new LogicException('No address generator or Electrum wallet available for invoice creation.');
        }

        $storedMetadata = $metadata;
        if ($requestId !== null) {
            $storedMetadata[self::INTERNAL_REQUEST_ID_KEY] = $requestId;
        }

        $jsonMetadata = $this->encodeJson(
            $storedMetadata,
            'invoice metadata',
            self::MAX_METADATA_BYTES
        );

        $amountSats = $amount->toSatoshis();
        $nextCheckAt = $now + 5; // initial check in 5 seconds

        try {
            $statement = $database->getPdo()->prepare(
                "INSERT INTO invoices
                    (id, store_id, btc_address, address_source, address_index, derivation_path, idempotency_key, amount, amount_sats, status, metadata, created_at, expires_at, next_check_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'New', ?, ?, ?, ?)"
            );
            $statement->execute([
                $invoiceId,
                $storeId,
                $address,
                $addressSource,
                $addressIndex,
                $derivationPath,
                $idempotencyKey,
                $amount->toBtcString(),
                $amountSats,
                $jsonMetadata,
                $now,
                $expiresAt,
                $nextCheckAt,
            ]);
        } catch (Throwable $exception) {
            if ($requestId !== null) {
                $this->removePaymentRequestQuietly($requestId);
            }

            // Check if duplicate key violation was due to idempotency key race
            if ($idempotencyKey !== null && $this->idempotencyService !== null) {
                $existing = $this->idempotencyService->getExistingInvoice($storeId, $idempotencyKey);
                if ($existing !== null) {
                    return $this->formatDatabaseInvoiceOutput($existing);
                }
            }

            throw new BtcInvoiceManagerException(
                'Database invoice could not be stored: ' . $exception->getMessage(),
                'create_database_invoice',
                previous: $exception
            );
        }

        return [
            'id' => $invoiceId,
            'address' => $address,
            'amount' => $amount->toBtcString(),
            'amount_sats' => $amountSats,
            'address_source' => $addressSource,
            'address_index' => $addressIndex,
            'status' => 'New',
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'bip21_uri' => $this->generateBip21Uri(
                $address,
                $amount->toBtcString(),
                'Faktura ' . $invoiceId
            ),
        ];
    }

    /**
     * Loads an invoice without contacting Electrum.
     *
     * @return array<string, mixed>
     */
    public function getDatabaseInvoice(string $invoiceId): array
    {
        $invoice = $this->loadDatabaseInvoice($invoiceId);
        unset($invoice['electrum_request_id']);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDatabaseInvoice(string $invoiceId): array
    {
        $database = $this->requireDatabase();
        $invoiceId = $this->validateIdentifier($invoiceId, 'Invoice ID', 50);

        $statement = $database->getPdo()->prepare('SELECT * FROM invoices WHERE id = ?');
        $statement->execute([$invoiceId]);
        $invoice = $statement->fetch();

        if (!is_array($invoice)) {
            throw new BtcInvoiceManagerException(
                'Invoice was not found.',
                'get_database_invoice',
                404
            );
        }

        $metadata = $this->decodeDatabaseMetadata($invoice['metadata'] ?? null);
        $requestId = $metadata[self::INTERNAL_REQUEST_ID_KEY] ?? null;
        unset($metadata[self::INTERNAL_REQUEST_ID_KEY]);

        $address = $this->validateStoredString(
            (string) ($invoice['btc_address'] ?? ''),
            'Invoice address',
            100
        );
        $amount = $this->decodeStoredAmount($invoice['amount'] ?? null);

        $invoice['id'] = $invoiceId;
        $invoice['btc_address'] = $address;
        $invoice['amount'] = $amount->toBtcString();
        $invoice['metadata'] = $metadata;
        if ($requestId !== null && !is_string($requestId)) {
            throw new BtcInvoiceManagerException(
                'Stored Electrum payment request ID is invalid.',
                'get_database_invoice'
            );
        }
        $invoice['electrum_request_id'] = $requestId === null || $requestId === ''
            ? null
            : $this->validateStoredString($requestId, 'Electrum payment request ID', 128);
        $invoice['created_at'] = $this->decodeStoredTimestamp(
            $invoice['created_at'] ?? null,
            'invoice creation time'
        );
        $invoice['expires_at'] = $this->decodeStoredTimestamp(
            $invoice['expires_at'] ?? null,
            'invoice expiration time'
        );
        if ($invoice['expires_at'] <= $invoice['created_at']) {
            throw new BtcInvoiceManagerException(
                'Stored invoice expiration time is invalid.',
                'get_database_invoice'
            );
        }
        $invoice['bip21_uri'] = $this->generateBip21Uri(
            $address,
            $amount->toBtcString(),
            'Faktura ' . $invoiceId
        );

        return $invoice;
    }

    /**
     * Checks blockchain/daemon and updates the persisted invoice status.
     * Never calls loadWallet().
     *
     * @return array<string, mixed>
     */
    public function checkDatabasePaymentStatus(string $invoiceId): array
    {
        $database = $this->requireDatabase();
        $invoice = $this->loadDatabaseInvoice($invoiceId);
        $expected = $this->requirePositiveAmount((string) $invoice['amount']);
        $currentStatus = (string) ($invoice['status'] ?? 'New');

        if ($currentStatus === 'Settled') {
            return $this->databaseStatusResult(
                $invoice,
                'Settled',
                $expected,
                $expected,
                'None'
            );
        }

        $now = $this->now();
        $observation = $this->observePayment(
            (string) $invoice['btc_address'],
            $invoice['electrum_request_id'],
            $expected
        );
        $isExpired = $now >= (int) $invoice['expires_at'];
        $newStatus = $this->databaseStatus(
            $observation['electrum_status'],
            $observation['confirmed'],
            $observation['received'],
            $expected,
            $isExpired
        );
        $additionalStatus = $observation['received']->isPositive()
            && $observation['received']->compare($expected) < 0
                ? 'PaidPartial'
                : 'None';

        $confirmedSats = $observation['confirmed']->toSatoshis();
        $unconfirmedSats = $observation['received']->subtract($observation['confirmed'])->toSatoshis();
        if ($unconfirmedSats < 0) {
            $unconfirmedSats = 0;
        }

        // Calculate next check backoff interval
        $nextCheckAt = $this->calculateNextCheck($newStatus, $now, (int) $invoice['expires_at']);

        // Update database record
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $statement = $database->getPdo()->prepare(
                'UPDATE invoices
                 SET status = ?,
                     confirmed_received_sats = ?,
                     unconfirmed_received_sats = ?,
                     last_checked_at = ?,
                     next_check_at = ?
                 WHERE id = ? AND (status != ? OR status != "Settled")'
            );
            $statement->execute([
                $newStatus,
                $confirmedSats,
                $unconfirmedSats,
                $now,
                $nextCheckAt,
                $invoiceId,
                $newStatus,
            ]);

            if ($statement->rowCount() >= 0) {
                $invoice['status'] = $newStatus;
                break;
            }
        }

        if ($newStatus === 'Settled') {
            $observation['received'] = BitcoinAmount::max($observation['received'], $expected);
            $additionalStatus = 'None';
        }

        return $this->databaseStatusResult(
            $invoice,
            $newStatus,
            $expected,
            $observation['received'],
            $additionalStatus
        );
    }

    private function calculateNextCheck(string $status, int $now, int $expiresAt): ?int
    {
        if ($status === 'Settled' || $status === 'Expired' || $status === 'Invalid') {
            return null; // terminal
        }

        if ($status === 'Processing') {
            return $now + 10;
        }

        $age = $now - ($expiresAt - 900); // approximate creation time
        if ($age < 300) {
            return $now + 5;
        }
        if ($age < 900) {
            return $now + 15;
        }

        return $now + 30;
    }

    /**
     * @param array<string, mixed> $customData
     * @return array{token: string, bip21_uri: string}
     */
    public function createStatelessInvoice(
        int|float|string $amountBtc,
        string $description,
        array $customData = [],
        int $expirationMinutes = 15
    ): array {
        return $this->statelessManager->createStatelessInvoice(
            $amountBtc,
            $description,
            $customData,
            $expirationMinutes
        );
    }

    public function decodeStatelessToken(string $token): array
    {
        return $this->statelessManager->decodeStatelessToken($token);
    }

    public function checkStatelessPaymentStatus(string $token): array
    {
        return $this->statelessManager->checkStatelessPaymentStatus($token);
    }

    /**
     * @return array{address: string, request_id: string}
     */
    private function reservePaymentRequest(
        BitcoinAmount $amount,
        string $memo,
        int $expirationSeconds
    ): array {
        if ($this->wallet === null) {
            throw new LogicException('Electrum wallet is not configured for payment request reservation.');
        }

        $request = $this->wallet->createPaymentRequest(
            $amount->toBtcString(),
            $memo,
            $expirationSeconds
        );
        $address = $this->validateNonEmptyString(
            (string) ($request['address'] ?? ''),
            'Electrum payment request address',
            100
        );
        $requestId = $this->validateNonEmptyString(
            (string) ($request['request_id'] ?? ''),
            'Electrum payment request ID',
            128
        );

        return ['address' => $address, 'request_id' => $requestId];
    }

    /**
     * @return array{
     *   electrum_status: int|null,
     *   confirmed: BitcoinAmount,
     *   received: BitcoinAmount
     * }
     */
    private function observePayment(
        string $address,
        ?string $requestId,
        BitcoinAmount $expected
    ): array {
        $electrumStatus = null;

        // If blockchainProvider is available, use it (walletless and lockless!)
        if ($this->blockchainProvider !== null) {
            try {
                $observation = $this->blockchainProvider->getAddressObservation($address);
                $confirmed = BitcoinAmount::fromSatoshis($observation->getConfirmedSats());
                $unconfirmed = BitcoinAmount::fromSatoshis($observation->getUnconfirmedSats());
                $received = $confirmed->add($unconfirmed);

                return [
                    'electrum_status' => null,
                    'confirmed' => $confirmed,
                    'received' => $received,
                ];
            } catch (Throwable $_e) {
                // fall through to wallet query if provider encounters an error
            }
        }

        if ($this->wallet !== null) {
            if ($requestId !== null && $requestId !== '') {
                try {
                    $request = $this->wallet->getPaymentRequest($requestId);
                    $rawStatus = $request['status'] ?? null;
                    if (is_int($rawStatus)) {
                        $electrumStatus = $rawStatus;
                    } elseif (is_string($rawStatus) && ctype_digit($rawStatus)) {
                        $electrumStatus = (int) $rawStatus;
                    }
                } catch (Throwable $_ignored) {
                }
            }

            try {
                $balance = $this->wallet->getAddressBalanceExact($address);
                $confirmed = BitcoinAmount::fromBtc($balance['confirmed'] ?? '0');
                $unconfirmed = BitcoinAmount::fromBtc($balance['unconfirmed'] ?? '0');
            } catch (Throwable $exception) {
                throw new BtcInvoiceManagerException(
                    'Electrum returned an invalid address balance: ' . $exception->getMessage(),
                    'observe_payment',
                    previous: $exception
                );
            }

            $zero = BitcoinAmount::fromSatoshis(0);
            $confirmed = BitcoinAmount::max($zero, $confirmed);
            $received = BitcoinAmount::max($zero, $confirmed->add($unconfirmed));

            if ($electrumStatus === self::ELECTRUM_STATUS_PAID) {
                $confirmed = BitcoinAmount::max($confirmed, $expected);
                $received = BitcoinAmount::max($received, $expected);
            } elseif ($electrumStatus === self::ELECTRUM_STATUS_UNCONFIRMED) {
                $received = BitcoinAmount::max($received, $expected);
            }

            return [
                'electrum_status' => $electrumStatus,
                'confirmed' => $confirmed,
                'received' => $received,
            ];
        }

        return [
            'electrum_status' => null,
            'confirmed' => BitcoinAmount::fromSatoshis(0),
            'received' => BitcoinAmount::fromSatoshis(0),
        ];
    }

    private function databaseStatus(
        ?int $electrumStatus,
        BitcoinAmount $confirmed,
        BitcoinAmount $received,
        BitcoinAmount $expected,
        bool $isExpired
    ): string {
        if ($electrumStatus === self::ELECTRUM_STATUS_PAID || $confirmed->compare($expected) >= 0) {
            return 'Settled';
        }

        if ($electrumStatus === self::ELECTRUM_STATUS_UNCONFIRMED || $received->compare($expected) >= 0) {
            return 'Processing';
        }

        if ($isExpired) {
            return 'Expired';
        }

        return 'New';
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private function databaseStatusResult(
        array $invoice,
        string $status,
        BitcoinAmount $expected,
        BitcoinAmount $received,
        string $additionalStatus
    ): array {
        $now = $this->now();
        $isExpired = $status === 'Expired' || $now >= (int) $invoice['expires_at'];
        $missing = BitcoinAmount::max(
            BitcoinAmount::fromSatoshis(0),
            $expected->subtract($received)
        );

        return [
            'id' => $invoice['id'],
            'status' => $status,
            'additional_status' => $additionalStatus,
            'is_expired' => $isExpired,
            'seconds_remaining' => $isExpired ? 0 : max(0, (int) $invoice['expires_at'] - $now),
            'btc_address' => $invoice['btc_address'],
            'amount' => $expected->toBtcString(),
            'amount_sats' => $expected->toSatoshis(),
            'total_paid' => $received->toBtcString(),
            'total_paid_sats' => $received->toSatoshis(),
            'missing_amount' => $missing->toBtcString(),
            'missing_amount_sats' => $missing->toSatoshis(),
            'metadata' => $invoice['metadata'] ?? [],
            'created_at' => $invoice['created_at'],
            'expires_at' => $invoice['expires_at'],
            'bip21_uri' => $invoice['bip21_uri'],
        ];
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private function formatDatabaseInvoiceOutput(array $invoice): array
    {
        $amount = (string) $invoice['amount'];
        $address = (string) $invoice['btc_address'];
        $invoiceId = (string) $invoice['id'];

        return [
            'id' => $invoiceId,
            'address' => $address,
            'amount' => $amount,
            'amount_sats' => $invoice['amount_sats'] ?? BitcoinAmount::fromBtc($amount)->toSatoshis(),
            'address_source' => $invoice['address_source'] ?? 'electrum',
            'address_index' => $invoice['address_index'] ?? null,
            'status' => (string) ($invoice['status'] ?? 'New'),
            'created_at' => (int) $invoice['created_at'],
            'expires_at' => (int) $invoice['expires_at'],
            'bip21_uri' => $this->generateBip21Uri($address, $amount, 'Faktura ' . $invoiceId),
        ];
    }

    private function removePaymentRequestQuietly(string $requestId): void
    {
        if ($this->wallet === null) {
            return;
        }

        try {
            $this->wallet->removePaymentRequest($requestId);
        } catch (Throwable) {
        }
    }

    private function requirePositiveAmount(mixed $amount): BitcoinAmount
    {
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            throw new InvalidArgumentException('Invoice amount must be an integer, float, or string.');
        }

        $parsed = BitcoinAmount::fromBtc($amount);
        if (!$parsed->isPositive()) {
            throw new InvalidArgumentException('Invoice amount must be positive.');
        }

        return $parsed;
    }

    private function decodeStoredAmount(mixed $amount): BitcoinAmount
    {
        try {
            return $this->requirePositiveAmount($amount);
        } catch (InvalidArgumentException $exception) {
            throw new BtcInvoiceManagerException(
                'Stored invoice amount is invalid.',
                'get_database_invoice',
                previous: $exception
            );
        }
    }

    private function expirationSeconds(int $expirationMinutes): int
    {
        if ($expirationMinutes < 1 || $expirationMinutes > self::MAX_EXPIRATION_MINUTES) {
            throw new InvalidArgumentException('Invoice expiration must be between 1 minute and 30 days.');
        }

        return $expirationMinutes * 60;
    }

    private function requireDatabase(): Database
    {
        if ($this->db === null) {
            throw new LogicException('Database mode is not configured for this invoice manager.');
        }

        return $this->db;
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 1) {
            throw new LogicException('Invoice clock must return a positive Unix timestamp.');
        }

        return $now;
    }

    private function validateIdentifier(string $value, string $field, int $maxBytes): string
    {
        return $this->validateNonEmptyString($value, $field, $maxBytes);
    }

    private function validateNonEmptyString(string $value, string $field, int $maxBytes): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0") || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException("{$field} is invalid.");
        }

        return $value;
    }

    private function validateStoredString(string $value, string $field, int $maxBytes): string
    {
        try {
            return $this->validateNonEmptyString($value, $field, $maxBytes);
        } catch (InvalidArgumentException $exception) {
            throw new BtcInvoiceManagerException(
                "Stored {$field} is invalid.",
                'get_database_invoice',
                previous: $exception
            );
        }
    }

    private function decodeStoredTimestamp(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $timestamp = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            $timestamp = filter_var($value, FILTER_VALIDATE_INT);
            if ($timestamp === false) {
                $timestamp = 0;
            }
        } else {
            $timestamp = 0;
        }

        if ($timestamp < 1) {
            throw new BtcInvoiceManagerException(
                "Stored {$field} is invalid.",
                'get_database_invoice'
            );
        }

        return $timestamp;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDatabaseMetadata(mixed $metadata): array
    {
        if ($metadata === null || $metadata === '') {
            return [];
        }
        if (!is_string($metadata)) {
            throw new BtcInvoiceManagerException(
                'Stored invoice metadata is invalid.',
                'decode_database_metadata'
            );
        }

        try {
            $decoded = json_decode($metadata, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BtcInvoiceManagerException(
                'Stored invoice metadata is invalid.',
                'decode_database_metadata',
                previous: $exception
            );
        }

        if (!is_array($decoded)) {
            throw new BtcInvoiceManagerException(
                'Stored invoice metadata must be a JSON object.',
                'decode_database_metadata'
            );
        }

        return $decoded;
    }

    private function encodeJson(mixed $value, string $field, int $maxBytes): string
    {
        try {
            $json = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new BtcInvoiceManagerException(
                "Unable to encode {$field}.",
                'encode_json',
                400,
                $exception
            );
        }

        if (strlen($json) > $maxBytes) {
            throw new BtcInvoiceManagerException(
                ucfirst($field) . ' is too large.',
                'encode_json',
                400
            );
        }

        return $json;
    }

    private function generateBip21Uri(string $address, string $amount, string $message): string
    {
        $query = ['amount' => $amount];
        if ($message !== '') {
            $query['message'] = $message;
        }

        return 'bitcoin:' . $address . '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }
}
