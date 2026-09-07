<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Loads the invoice checkout snapshot in one read. No wallet or store join.
 */
final class PdoCheckoutRepository implements CheckoutRepository
{
    private PDO $pdo;

    public function __construct(Database|PDO $database)
    {
        $this->pdo = $database instanceof Database ? $database->getPdo() : $database;
    }

    public function findInvoice(string $invoiceId): ?array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT i.id, i.store_id, i.btc_address, i.amount, i.status, i.metadata,
                        i.created_at, i.expires_at, i.confirmed_balance_sats, i.mempool_delta_sats, i.payment_observed_at
                 FROM invoices AS i
                 WHERE i.id = ?
                 LIMIT 1'
            );
            $statement->execute([$invoiceId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            throw new CheckoutException(
                'Platební údaje nyní nelze načíst.',
                503,
                'load_invoice',
                $exception
            );
        }

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = $this->storedString($row['id'] ?? null, 'invoice ID', 50);
        $row['store_id'] = $this->storedString($row['store_id'] ?? null, 'store ID', 50);
        return $row;
    }

    private function storedString(mixed $value, string $field, int $maxBytes): string
    {
        if (!is_string($value)) {
            throw new CheckoutException(
                'Uložené platební údaje jsou neplatné.',
                500,
                'validate_' . str_replace(' ', '_', $field)
            );
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes || str_contains($value, "\0")) {
            throw new CheckoutException(
                'Uložené platební údaje jsou neplatné.',
                500,
                'validate_' . str_replace(' ', '_', $field)
            );
        }

        return $value;
    }
}
