<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use PDO;
use Throwable;

/** Resource reservation + exact response replay for Greenfield invoice creation. */
class IdempotencyService
{
    private Closure $clock;

    public function __construct(private Database $database, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    /**
     * Caller must authenticate before lookup/replay. The callback may receive an
     * IdempotencyReservation; invoice creation completes it atomically with INSERT.
     * No DB transaction is held across the whole operation.
     */
    public function execute(string $storeId, string $idempotencyKey, array $payload, callable $operation, ?callable $responseFactory = null): array
    {
        if ($idempotencyKey === '') {
            return ['status_code' => 200, 'body' => $operation()];
        }
        if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $idempotencyKey)) {
            throw new GreenfieldApiException('Idempotency-Key must contain 1–128 alphanumeric characters, dashes or underscores.', 'validate_idempotency_key', 400);
        }
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true);
        $existing = $this->find($storeId, $idempotencyKey, $hash);
        if ($existing !== null && $existing['state'] !== 'Pending') {
            return $this->replay($existing);
        }
        // Connection-owned lock is released on a process crash, unlike an anonymous
        // response_code=0 claim. The resource ID/data remain durable for recovery.
        $lockName = 'idem_' . substr(hash('sha256', $storeId . "\0" . $idempotencyKey), 0, 56);
        try {
            return $this->database->withNamedLock($lockName, 2, function () use ($storeId, $idempotencyKey, $hash, $operation, $responseFactory): array {
                $row = $this->find($storeId, $idempotencyKey, $hash);
                if ($row === null) {
                    $resourceId = 'inv_' . bin2hex(random_bytes(16));
                    $stmt = $this->database->getPdo()->prepare(
                        "INSERT INTO api_idempotency_keys
                            (store_id, idempotency_key, request_hash, state, resource_id, response_code, response_body, created_at)
                         VALUES (?, ?, ?, 'Pending', ?, 0, '', ?)"
                    );
                    $stmt->execute([$storeId, $idempotencyKey, $hash, $resourceId, ($this->clock)()]);
                    $row = $this->find($storeId, $idempotencyKey, $hash);
                }
                if ($row['state'] !== 'Pending') {
                    return $this->replay($row);
                }
                $reservation = new IdempotencyReservation($this->database, $storeId, $idempotencyKey,
                    (string) $row['resource_id'], $responseFactory ?? static fn (array $invoice): array => $invoice);
                try {
                    $body = $operation($reservation);
                    $saved = $this->find($storeId, $idempotencyKey, $hash);
                    if ($saved['state'] === 'Pending') {
                        // For side-effect-free callbacks; create-invoice already completed
                        // inside its INSERT transaction and cannot reach this branch.
                        $this->database->transactional(fn () => $reservation->complete($body));
                        $saved = $this->find($storeId, $idempotencyKey, $hash);
                    }
                    return $this->replay($saved);
                } catch (Throwable $exception) {
                    $saved = $this->find($storeId, $idempotencyKey, $hash);
                    if ($saved !== null && $saved['state'] !== 'Pending') {
                        return $this->replay($saved);
                    }
                    // Safe deterministic client failures are replayed with their original
                    // HTTP status/body. Transient/unknown failures keep recoverable data.
                    if ($exception instanceof GreenfieldApiException && $exception->getHttpStatus() < 500) {
                        $body = ['message' => $exception->getMessage()];
                        $this->database->transactional(fn () => $reservation->complete($body, $exception->getHttpStatus(), 'Failed'));
                        return ['status_code' => $exception->getHttpStatus(), 'body' => $body];
                    }
                    throw $exception;
                }
            });
        } catch (DatabaseException $exception) {
            throw new GreenfieldApiException('Invoice reservation is busy. Retry with the same key.', 'idempotency_reservation', 503, $exception);
        }
    }

    private function find(string $storeId, string $key, string $hash): ?array
    {
        $stmt = $this->database->getPdo()->prepare(
            'SELECT request_hash, state, resource_id, response_code, response_body
               FROM api_idempotency_keys WHERE store_id = ? AND idempotency_key = ? LIMIT 1'
        );
        $stmt->execute([$storeId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (!hash_equals((string) $row['request_hash'], $hash)) {
            throw new GreenfieldApiException('Idempotency key was already used for a different request payload.', 'idempotency_conflict', 409);
        }
        return $row;
    }

    private function replay(array $row): array
    {
        $body = json_decode((string) $row['response_body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($body) || (int) $row['response_code'] < 100) {
            throw new GreenfieldApiException('Saved response is unavailable. The resource will not be recreated.', 'idempotency_replay', 503);
        }
        return ['status_code' => (int) $row['response_code'], 'body' => $body];
    }
}
