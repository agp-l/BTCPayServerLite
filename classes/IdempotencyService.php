<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Ensures identical POST invoice creation requests return the existing invoice
 * without creating duplicate Bitcoin addresses or consuming additional derivation indices.
 */
class IdempotencyService
{
    private ?Database $database;

    public function __construct(?Database $database = null)
    {
        $this->database = $database;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findExistingInvoice(string $storeId, ?string $idempotencyKey): ?array
    {
        if ($this->database === null || $idempotencyKey === null || trim($idempotencyKey) === '') {
            return null;
        }

        $idempotencyKey = trim($idempotencyKey);
        $pdo = $this->database->getPdo();

        try {
            $statement = $pdo->prepare(
                'SELECT id, store_id, btc_address, amount, status, metadata, created_at, expires_at,
                        address_source, address_index, derivation_path
                 FROM invoices
                 WHERE store_id = ? AND idempotency_key = ?
                 LIMIT 1'
            );
            $statement->execute([$storeId, $idempotencyKey]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return null;
            }

            return $row;
        } catch (Throwable) {
            // If column doesn't exist yet before migration, degrade gracefully
            return null;
        }
    }

    public function recordIdempotency(string $invoiceId, string $storeId, ?string $idempotencyKey): void
    {
        if ($this->database === null || $idempotencyKey === null || trim($idempotencyKey) === '') {
            return;
        }

        $idempotencyKey = trim($idempotencyKey);
        $pdo = $this->database->getPdo();

        try {
            $statement = $pdo->prepare(
                'UPDATE invoices SET idempotency_key = ? WHERE id = ? AND store_id = ?'
            );
            $statement->execute([$idempotencyKey, $invoiceId, $storeId]);
        } catch (Throwable) {
            // Ignore if column doesn't exist yet
        }
    }
}
