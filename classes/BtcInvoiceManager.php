<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Throwable;

/**
 * Creates database-backed Bitcoin invoices and reads their persisted state.
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

    private ?ElectrumWallet $wallet;
    private ?BtcStatelessInvoiceManager $statelessManager;
    private ?Database $db;
    private Closure $clock;
    private ?AddressGeneratorFactory $addressGeneratorFactory;
    private ?AddressGeneratorInterface $addressGenerator;

    public function __construct(
        ?ElectrumWallet $wallet = null,
        string $secretKey = '',
        ?Database $db = null,
        ?callable $clock = null,
        ?AddressGeneratorFactory $addressGeneratorFactory = null,
        ?AddressGeneratorInterface $addressGenerator = null,
        ?BlockchainProviderInterface $blockchainProvider = null
    ) {
        $this->wallet = $wallet;
        $this->statelessManager = $wallet !== null ? new BtcStatelessInvoiceManager($wallet, $secretKey, $clock, $blockchainProvider) : null;
        $this->db = $db;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
        $this->addressGeneratorFactory = $addressGeneratorFactory;
        $this->addressGenerator = $addressGenerator;
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
        ?AddressGeneratorInterface $addressGenerator = null,
        ?IdempotencyReservation $reservation = null
    ): array {
        $database = $this->requireDatabase();
        $storeId = $this->validateIdentifier($storeId, 'Store ID', 50);
        $amount = $this->requirePositiveAmount($amountBtc);
        $expirationSeconds = $this->expirationSeconds($expirationMinutes);
        $invoiceId = $reservation?->resourceId() ?? 'inv_' . bin2hex(random_bytes(16));
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

        $allocate = function () use ($generator, $context, $invoiceId, $storeId, $amount, $metadata, $now, $expiresAt): array {
            $generated = $generator->generateAddress($context);
            return [
                'id' => $invoiceId,
                'store_id' => $storeId,
                'address' => $generated->getAddress(),
                'amount' => $amount->toBtcString(),
                'status' => 'New',
                'metadata' => $metadata,
                'created_at' => $now,
                'expires_at' => $expiresAt,
                'bip21_uri' => $this->generateBip21Uri($generated->getAddress(), $amount->toBtcString(), 'Faktura ' . $invoiceId),
                'address_source' => $generated->getSource(),
                'address_index' => $generated->getIndex(),
                'derivation_path' => $generated->getDerivationPath(),
            ];
        };
        // XPUB index + creation snapshot commit together. Retry reuses this data,
        // including its rate/amount/timestamps, even if the INSERT/response failed.
        $invoice = $reservation === null ? $allocate()
            : $reservation->reserveResource($allocate, $generator instanceof XpubAddressGenerator);

        try {
            $database->transactional(function (\PDO $pdo) use ($invoice, $reservation): void {
                $statement = $pdo->prepare(
                    "INSERT INTO invoices
                        (id, store_id, btc_address, address_source, address_index, derivation_path, amount, status, metadata, created_at, expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'New', ?, ?, ?)"
                );
                $statement->execute([
                    $invoice['id'], $invoice['store_id'], $invoice['address'], $invoice['address_source'],
                    $invoice['address_index'], $invoice['derivation_path'], $invoice['amount'],
                    $this->encodeJson($invoice['metadata'], 'invoice metadata', self::MAX_METADATA_BYTES),
                    $invoice['created_at'], $invoice['expires_at'],
                ]);
                $reservation?->completeInvoice($pdo, $invoice);
            });
        } catch (Throwable $exception) {
            throw new BtcInvoiceManagerException('Database invoice could not be stored.', 'create_database_invoice', previous: $exception);
        }
        return $invoice;
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

    /** Read-only compatibility projection. PaymentWorker is the only status writer. */
    public function getCachedDatabasePaymentStatus(string $invoiceId): array
    {
        return InvoicePaymentPresentation::fromInvoice($this->loadDatabaseInvoice($invoiceId));
    }

    /** @deprecated Online monitoring was removed. Schedule PaymentWorker; this is DB-only. */
    public function checkDatabasePaymentStatus(string $invoiceId): array
    {
        return $this->getCachedDatabasePaymentStatus($invoiceId);
    }

    /**
     * @param array<string, mixed> $customData
     * @return array{token: string, bip21_uri: string}
     */
    public function canObserveWithoutWallet(): bool
    {
        return $this->statelessManager?->canObserveWithoutWallet() ?? false;
    }

    public function createStatelessInvoice(
        int|float|string $amountBtc,
        string $description,
        array $customData = [],
        int $expirationMinutes = 15,
        ?string $walletPath = null
    ): array {
        return $this->requireStatelessManager()->createStatelessInvoice(
            $amountBtc,
            $description,
            $customData,
            $expirationMinutes,
            $walletPath ?? $this->wallet?->getActiveWalletPath()
        );
    }

    /**
     * Verifies and decodes both current and legacy stateless tokens.
     *
     * @return array<string, mixed>
     */
    public function decodeStatelessToken(string $token): array
    {
        return $this->requireStatelessManager()->decodeStatelessToken($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatelessPaymentStatus(string $token, ?string $walletPath = null): array
    {
        return $this->requireStatelessManager()->checkStatelessPaymentStatus($token, $walletPath ?? $this->wallet?->getActiveWalletPath());
    }

    private function requireStatelessManager(): BtcStatelessInvoiceManager
    {
        if ($this->statelessManager === null) {
            throw new LogicException('Stateless invoice operations are not configured for this invoice manager.');
        }

        return $this->statelessManager;
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
