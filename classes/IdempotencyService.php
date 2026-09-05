<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use InvalidArgumentException;
use JsonException;
use PDO;
use Throwable;

/**
 * Ensures strict idempotency for Greenfield API mutations (such as invoice creation).
 *
 * Prevents double-creation, double index derivation, and duplicate transactions
 * when clients retry HTTP requests due to transient network issues.
 */
class IdempotencyService
{
    private Database $database;
    private Closure $clock;

    public function __construct(Database $database, ?callable $clock = null)
    {
        $this->database = $database;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    /**
     * Executes the given operation under an idempotency key.
     *
     * @param string $storeId
     * @param string $idempotencyKey
     * @param array<string, mixed> $payload
     * @param callable(): array<string, mixed> $operation
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function execute(
        string $storeId,
        string $idempotencyKey,
        array $payload,
        callable $operation
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            // No idempotency requested; execute directly
            $result = $operation();
            return [
                'status_code' => 200,
                'body' => $result,
            ];
        }

        if (strlen($idempotencyKey) > 128 || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $idempotencyKey)) {
            throw new GreenfieldApiException(
                'Idempotency-Key must contain between 1 and 128 alphanumeric characters, dashes, or underscores.',
                'validate_idempotency_key',
                400
            );
        }

        $requestJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $requestHash = hash('sha256', (string) $requestJson, true);

        // Check for existing record
        $stmt = $this->database->getPdo()->prepare(
            'SELECT request_hash, response_code, response_body
               FROM api_idempotency_keys
              WHERE store_id = ? AND idempotency_key = ?
              LIMIT 1'
        );
        $stmt->execute([$storeId, $idempotencyKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            if ($existing['request_hash'] !== $requestHash) {
                throw new GreenfieldApiException(
                    'Idempotency key was already used for a different request payload.',
                    'idempotency_conflict',
                    409
                );
            }

            try {
                $savedBody = json_decode((string) $existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
                return [
                    'status_code' => (int) $existing['response_code'],
                    'body' => is_array($savedBody) ? $savedBody : [],
                ];
            } catch (JsonException) {
                // Fall through to re-execute if stored JSON was somehow corrupted
            }
        }

        // Execute operation
        $responseBody = $operation();
        $responseCode = 200;
        $encodedBody = json_encode($responseBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Store idempotency record
        try {
            $insert = $this->database->getPdo()->prepare(
                'INSERT INTO api_idempotency_keys
                    (store_id, idempotency_key, request_hash, response_code, response_body, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE response_body = response_body'
            );
            $insert->execute([
                $storeId,
                $idempotencyKey,
                $requestHash,
                $responseCode,
                $encodedBody,
                ($this->clock)(),
            ]);
        } catch (Throwable) {
            // Database insert failure for idempotency logging is non-fatal to the executed operation
        }

        return [
            'status_code' => $responseCode,
            'body' => $responseBody,
        ];
    }
}
