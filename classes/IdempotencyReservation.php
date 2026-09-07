<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use PDO;

/** A durable create-invoice resource claim, used only while its key lock is owned. */
final class IdempotencyReservation
{
    private Closure $responseFactory;

    public function __construct(
        private Database $database,
        private string $storeId,
        private string $key,
        private string $resourceId,
        callable $responseFactory
    ) {
        $this->responseFactory = Closure::fromCallable($responseFactory);
    }

    public function resourceId(): string { return $this->resourceId; }

    /** Reserve XPUB index + immutable creation data atomically; Electrum RPC stays outside DB transactions. */
    public function reserveResource(callable $allocate, bool $offline): array
    {
        $select = $this->database->getPdo()->prepare(
            'SELECT resource_data FROM api_idempotency_keys WHERE store_id = ? AND idempotency_key = ?'
        );
        $select->execute([$this->storeId, $this->key]);
        $saved = $select->fetchColumn();
        if (is_string($saved) && $saved !== '') {
            return json_decode($saved, true, 32, JSON_THROW_ON_ERROR);
        }
        $resource = $offline ? null : $allocate();
        return $this->database->transactional(function (PDO $pdo) use ($allocate, $resource): array {
            $data = $resource ?? $allocate();
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $update = $pdo->prepare(
                'UPDATE api_idempotency_keys SET resource_data = ? WHERE store_id = ? AND idempotency_key = ? AND state = \'Pending\''
            );
            $update->execute([$encoded, $this->storeId, $this->key]);
            if ($update->rowCount() !== 1) {
                throw new \LogicException('Invoice resource reservation is no longer pending.');
            }
            return $data;
        });
    }

    /** Must commit with the invoice INSERT, on the exact same PDO connection. */
    public function completeInvoice(PDO $pdo, array $invoice): void
    {
        if ($pdo !== $this->database->getPdo() || !$pdo->inTransaction() || $invoice['id'] !== $this->resourceId) {
            throw new \LogicException('Invoice completion requires its resource transaction.');
        }
        $this->complete(($this->responseFactory)($invoice));
    }

    public function complete(array $body, int $code = 200, string $state = 'Completed'): void
    {
        if (!$this->database->getPdo()->inTransaction()) {
            throw new \LogicException('Idempotency completion requires a transaction.');
        }
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $this->database->getPdo()->prepare(
            'UPDATE api_idempotency_keys SET state = ?, response_code = ?, response_body = ?
              WHERE store_id = ? AND idempotency_key = ? AND state = \'Pending\''
        );
        $stmt->execute([$state, $code, $encoded, $this->storeId, $this->key]);
        if ($stmt->rowCount() !== 1) {
            throw new \LogicException('Idempotency reservation has already completed.');
        }
    }
}
