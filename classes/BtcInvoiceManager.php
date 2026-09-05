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
 * All monetary comparisons are performed in integer satoshis. The three
 * stateless methods remain as a backwards-compatible facade and delegate to
 * BtcStatelessInvoiceManager; new standalone integrations should use that
 * database-free class directly.
 */
class BtcInvoiceManager implements BtcStatelessInvoiceGateway
{
    private const MAX_EXPIRATION_MINUTES = 43_200;
    private const MAX_METADATA_BYTES = 16_384;
    private const INTERNAL_REQUEST_ID_KEY = '_btcpaylite_electrum_request_id';

    private const ELECTRUM_STATUS_EXPIRED = 1;
    private const ELECTRUM_STATUS_PAID = 3;
    private const ELECTRUM_STATUS_UNCONFIRMED = 7;

    private ElectrumWallet $wallet;
    private BtcStatelessInvoiceManager $statelessManager;
    private ?Database $db;
    private Closure $clock;
    private ?AddressGeneratorFactory $addressGeneratorFactory;
    private ?AddressGeneratorInterface $addressGenerator;
    private ?BlockchainProviderInterface $blockchainProvider;

    public function __construct(
        ElectrumWallet $wallet,
        string $secretKey,
        ?Database $db = null,
        ?callable $clock = null,
        ?AddressGeneratorFactory $addressGeneratorFactory = null,
        ?AddressGeneratorInterface $addressGenerator = null,
        ?BlockchainProviderInterface $blockchainProvider = null
    ) {
        $this->wallet = $wallet;
        $this->statelessManager = new BtcStatelessInvoiceManager($wallet, $secretKey, $clock);
        $this->db = $db;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
        $this->addressGeneratorFactory = $addressGeneratorFactory;
        $this->addressGenerator = $addressGenerator;
        $this->blockchainProvider = $blockchainProvider;
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
        ?AddressGeneratorInterface $addressGenerator = null
    ): array {
        $database = $this->requireDatabase();
        $storeId = $this->validateIdentifier($storeId, 'Store ID', 50);
        $amount = $this->requirePositiveAmount($amountBtc);
        $expirationSeconds = $this->expirationSeconds($expirationMinutes);
        $invoiceId = 'inv_' . bin2hex(random_bytes(16));
        $now = $this->now();
        $expiresAt = $now + $expirationSeconds;

        $this->encodeJson($metadata, 'invoice metadata', self::MAX_METADATA_BYTES);

        // Fetch store configuration to determine address source and settings
        $storeStmt = $database->getPdo()->prepare('SELECT * FROM stores WHERE id = ?');
        $storeStmt->execute([$storeId]);
        $store = $storeStmt->fetch();
        if (!is_array($store)) {
            throw new BtcInvoiceManagerException("Store '{$storeId}' not found.", 'create_database_invoice', 404);
        }

        $generator = $addressGenerator;
        if ($generator === null) {
            if ($this->addressGeneratorFactory !== null) {
                $generator = $this->addressGeneratorFactory->forStore($store);
            } elseif ($this->addressGenerator !== null) {
                $generator = $this->addressGenerator;
            } else {
                $factory = new AddressGeneratorFactory($this->wallet, $database);
                $generator = $factory->forStore($store);
            }
        }

        $context = new AddressGenerationContext(
            $storeId,
            !empty($store['wallet_path']) ? (string) $store['wallet_path'] : null,
            'Faktura ' . $invoiceId
        );

        $generated = $generator->generateAddress($context);
        $address = $generated->getAddress();
        $source = $generated->getSource();
        $index = $generated->getIndex();
        $derivationPath = $generated->getDerivationPath();

        $storedMetadata = $metadata;
        $jsonMetadata = $this->encodeJson(
            $storedMetadata,
            'invoice metadata',
            self::MAX_METADATA_BYTES
        );

        try {
            $statement = $database->getPdo()->prepare(
                "INSERT INTO invoices
                    (id, store_id, btc_address, address_source, address_index, derivation_path, amount, status, metadata, created_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'New', ?, ?, ?)"
            );
            $statement->execute([
                $invoiceId,
                $storeId,
                $address,
                $source,
                $index,
                $derivationPath,
                $amount->toBtcString(),
                $jsonMetadata,
                $now,
                $expiresAt,
            ]);
        } catch (Throwable $exception) {
            throw new BtcInvoiceManagerException(
                'Database invoice could not be stored.',
                'create_database_invoice',
                previous: $exception
            );
        }

        return [
            'id' => $invoiceId,
            'address' => $address,
            'amount' => $amount->toBtcString(),
            'status' => 'New',
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'bip21_uri' => $this->generateBip21Uri(
                $address,
                $amount->toBtcString(),
                'Faktura ' . $invoiceId
            ),
            'address_source' => $source,
            'address_index' => $index,
            'derivation_path' => $derivationPath,
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
        $invoice['address_source'] = (string) ($invoice['address_source'] ?? GeneratedAddress::SOURCE_ELECTRUM);
        $invoice['address_index'] = isset($invoice['address_index']) && $invoice['address_index'] !== null ? (int) $invoice['address_index'] : null;
        $invoice['derivation_path'] = isset($invoice['derivation_path']) && $invoice['derivation_path'] !== null ? (string) $invoice['derivation_path'] : null;

        $invoice['bip21_uri'] = $this->generateBip21Uri(
            $address,
            $amount->toBtcString(),
            'Faktura ' . $invoiceId
        );

        return $invoice;
    }

    /**
     * Fast, database-only payment status check without contacting Electrum.
     * Updates to 'Expired' if expires_at is in the past.
     *
     * @return array<string, mixed>
     */
    public function getCachedDatabasePaymentStatus(string $invoiceId): array
    {
        $database = $this->requireDatabase();
        $invoice = $this->loadDatabaseInvoice($invoiceId);
        $expected = $this->requirePositiveAmount((string) $invoice['amount']);
        $currentStatus = (string) ($invoice['status'] ?? 'New');
        $now = $this->now();
        $isExpired = $now >= (int) $invoice['expires_at'];

        if ($currentStatus === 'New' && $isExpired) {
            $statement = $database->getPdo()->prepare(
                "UPDATE invoices SET status = 'Expired' WHERE id = ? AND status = 'New'"
            );
            $statement->execute([$invoiceId]);
            if ($statement->rowCount() === 1) {
                $currentStatus = 'Expired';
                $invoice['status'] = 'Expired';
            }
        }

        $confirmed = $currentStatus === 'Settled' ? $expected : BitcoinAmount::fromSatoshis(0);
        $received = $currentStatus === 'Settled' ? $expected : BitcoinAmount::fromSatoshis(0);

        return $this->databaseStatusResult(
            $invoice,
            $currentStatus,
            $expected,
            $received,
            'None'
        );
    }

    /**
     * Checks Electrum and updates the persisted invoice status.
     *
     * @return array<string, mixed>
     */
    public function checkDatabasePaymentStatus(string $invoiceId): array
    {
        $database = $this->requireDatabase();
        $invoice = $this->loadDatabaseInvoice($invoiceId);
        $expected = $this->requirePositiveAmount((string) $invoice['amount']);
        $currentStatus = (string) ($invoice['status'] ?? 'New');

        // A confirmed invoice is terminal in the local state machine. This also
        // prevents a later wallet spend from making it look unpaid again.
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

        // Retry a conditional write if another checker updated the same invoice
        // between our read and write. Settled is terminal and always wins.
        for ($attempt = 0; $attempt < 3 && $newStatus !== $currentStatus; ++$attempt) {
            $statement = $database->getPdo()->prepare(
                'UPDATE invoices SET status = ? WHERE id = ? AND status = ?'
            );
            $statement->execute([$newStatus, $invoiceId, $currentStatus]);

            if ($statement->rowCount() === 1) {
                $invoice['status'] = $newStatus;
                $currentStatus = $newStatus;
                break;
            }

            $invoice = $this->loadDatabaseInvoice($invoiceId);
            $currentStatus = (string) $invoice['status'];
            if ($currentStatus === 'Settled') {
                $newStatus = 'Settled';
            }
        }

        if ($newStatus !== $currentStatus) {
            $newStatus = $currentStatus;
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

    /**
     * Verifies and decodes both current and legacy stateless tokens.
     *
     * @return array<string, mixed>
     */
    public function decodeStatelessToken(string $token): array
    {
        return $this->statelessManager->decodeStatelessToken($token);
    }

    /**
     * @return array<string, mixed>
     */
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
        if ($requestId !== null && $requestId !== '') {
            $request = $this->wallet->getPaymentRequest($requestId);
            $rawStatus = $request['status'] ?? null;
            if (is_int($rawStatus)) {
                $electrumStatus = $rawStatus;
            } elseif (is_string($rawStatus) && ctype_digit($rawStatus)) {
                $electrumStatus = (int) $rawStatus;
            } else {
                throw new BtcInvoiceManagerException(
                    'Electrum payment request returned an invalid status.',
                    'observe_payment'
                );
            }
        }

        if ($this->blockchainProvider !== null) {
            $observation = $this->blockchainProvider->observeAddress($address, $expected->toSatoshis());
            $amounts = $observation->toAmountArray();
            $confirmed = $amounts['confirmed'];
            $received = $amounts['received'];
        } else {
            $balance = $this->wallet->getAddressBalanceExact($address);

            try {
                $confirmed = BitcoinAmount::fromBtc($balance['confirmed'] ?? '0');
                $unconfirmed = BitcoinAmount::fromBtc($balance['unconfirmed'] ?? '0');
            } catch (InvalidArgumentException $exception) {
                throw new BtcInvoiceManagerException(
                    'Electrum returned an invalid address balance.',
                    'observe_payment',
                    previous: $exception
                );
            }
            $zero = BitcoinAmount::fromSatoshis(0);
            $confirmed = BitcoinAmount::max($zero, $confirmed);
            $received = BitcoinAmount::max($zero, $confirmed->add($unconfirmed));
        }

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

    private function databaseStatus(
        ?int $electrumStatus,
        BitcoinAmount $confirmed,
        BitcoinAmount $received,
        BitcoinAmount $expected,
        bool $isExpired
    ): string {
        if ($electrumStatus === self::ELECTRUM_STATUS_PAID) {
            return 'Settled';
        }
        if ($electrumStatus === self::ELECTRUM_STATUS_UNCONFIRMED) {
            return 'Processing';
        }
        if ($confirmed->compare($expected) >= 0) {
            return 'Settled';
        }
        if ($received->compare($expected) >= 0) {
            return 'Processing';
        }

        return $isExpired || $electrumStatus === self::ELECTRUM_STATUS_EXPIRED
            ? 'Expired'
            : 'New';
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
        $missing = BitcoinAmount::max(
            BitcoinAmount::fromSatoshis(0),
            $expected->subtract($received)
        );
        $invoice['status'] = $status;
        unset($invoice['electrum_request_id']);

        return [
            'id' => (string) $invoice['id'],
            'status' => $status,
            'additional_status' => $additionalStatus,
            'invoice' => $invoice,
            'payment' => [
                'total_received' => $received->toBtcString(),
                'missing_amount' => $missing->toBtcString(),
            ],
        ];
    }

    private function removePaymentRequestQuietly(string $requestId): void
    {
        try {
            $this->wallet->deletePaymentRequest($requestId);
        } catch (Throwable) {
            // Preserve the original failure. The orphaned request is harmless
            // and can be removed by a maintenance task later.
        }
    }

    private function requirePositiveAmount(int|float|string $amount): BitcoinAmount
    {
        $bitcoinAmount = BitcoinAmount::fromBtc($amount);
        if (!$bitcoinAmount->isPositive()) {
            throw new InvalidArgumentException('Invoice amount must be greater than zero.');
        }

        return $bitcoinAmount;
    }

    private function decodeStoredAmount(mixed $amount): BitcoinAmount
    {
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            throw new BtcInvoiceManagerException(
                'Stored invoice amount is invalid.',
                'get_database_invoice'
            );
        }

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
