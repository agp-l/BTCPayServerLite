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
 * when clients retry HTTP requests due to transient network issues or when concurrent
 * requests arrive with identical Idempotency-Keys.
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
        $pdo = $this->database->getPdo();

        // 1. Check for existing completed or pending record
        $stmt = $pdo->prepare(
            'SELECT request_hash, response_code, response_body
               FROM api_idempotency_keys
              WHERE store_id = ? AND idempotency_key = ?
              LIMIT 1'
        );
        $stmt->execute([$storeId, $idempotencyKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            return $this->handleExistingRecord($storeId, $idempotencyKey, $requestHash, $existing, $operation);
        }

        // 2. No record exists yet: attempt atomic reservation
        $ownsExecution = false;
        try {
            $insert = $pdo->prepare(
                'INSERT INTO api_idempotency_keys
                    (store_id, idempotency_key, request_hash, response_code, response_body, created_at)
                 VALUES (?, ?, ?, 0, \'\', ?)'
            );
            $insert->execute([
                $storeId,
                $idempotencyKey,
                $requestHash,
                ($this->clock)(),
            ]);
            $ownsExecution = true;
        } catch (Throwable) {
            // Another concurrent thread inserted the key first
            $ownsExecution = false;
        }

        if (!$ownsExecution) {
            // Another worker won the insert race; wait for its completion
            return $this->waitForCompletion($storeId, $idempotencyKey, $requestHash, $operation);
        }

        // 3. This worker owns execution: run operation and update idempotency record
        try {
            $responseBody = $operation();
            $responseCode = 200;
            $encodedBody = json_encode($responseBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $update = $pdo->prepare(
                'UPDATE api_idempotency_keys
                    SET response_code = ?, response_body = ?
                  WHERE store_id = ? AND idempotency_key = ?'
            );
            $update->execute([$responseCode, $encodedBody, $storeId, $idempotencyKey]);

            return [
                'status_code' => $responseCode,
                'body' => $responseBody,
            ];
        } catch (Throwable $e) {
            // Clean up reservation so future retry attempts can proceed
            try {
                $delete = $pdo->prepare(
                    'DELETE FROM api_idempotency_keys
                      WHERE store_id = ? AND idempotency_key = ? AND response_code = 0'
                );
                $delete->execute([$storeId, $idempotencyKey]);
            } catch (Throwable) {
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $existing
     * @return array{status_code: int, body: array<string, mixed>}
     */
    private function handleExistingRecord(
        string $storeId,
        string $idempotencyKey,
        string $requestHash,
        array $existing,
        callable $operation
    ): array {
        if ($existing['request_hash'] !== $requestHash) {
            throw new GreenfieldApiException(
                'Idempotency key was already used for a different request payload.',
                'idempotency_conflict',
                409
            );
        }

        $code = (int) ($existing['response_code'] ?? 0);
        if ($code > 0) {
            try {
                $savedBody = json_decode((string) $existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
                return [
                    'status_code' => $code,
                    'body' => is_array($savedBody) ? $savedBody : [],
                ];
            } catch (JsonException) {
                // Fallback to re-execution if stored body was somehow unparseable
            }
        }

        // If response_code is 0, execution is currently pending by another process
        return $this->waitForCompletion($storeId, $idempotencyKey, $requestHash, $operation);
    }

    /**
     * Waits for an in-flight operation to complete and returns its cached response.
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    private function waitForCompletion(
        string $storeId,
        string $idempotencyKey,
        string $requestHash,
        callable $operation
    ): array {
        $pdo = $this->database->getPdo();
        $attempts = 40; // 40 * 50ms = 2.0s timeout

        for ($i = 0; $i < $attempts; $i++) {
            usleep(50000); // 50ms

            $stmt = $pdo->prepare(
                'SELECT request_hash, response_code, response_body
                   FROM api_idempotency_keys
                  WHERE store_id = ? AND idempotency_key = ?
                  LIMIT 1'
            );
            $stmt->execute([$storeId, $idempotencyKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                // Reservation was deleted (e.g. prior execution failed), we can break and run
                break;
            }

            if ($row['request_hash'] !== $requestHash) {
                throw new GreenfieldApiException(
                    'Idempotency key was already used for a different request payload.',
                    'idempotency_conflict',
                    409
                );
            }

            $code = (int) ($row['response_code'] ?? 0);
            if ($code > 0) {
                try {
                    $savedBody = json_decode((string) $row['response_body'], true, 512, JSON_THROW_ON_ERROR);
                    return [
                        'status_code' => $code,
                        'body' => is_array($savedBody) ? $savedBody : [],
                    ];
                } catch (JsonException) {
                    break;
                }
            }
        }

        // If timed out waiting for in-flight operation, report conflict or retry
        throw new GreenfieldApiException(
            'Concurrent request under the same idempotency key is still being processed. Please retry shortly.',
            'idempotency_in_flight',
            409
        );
    }
}
