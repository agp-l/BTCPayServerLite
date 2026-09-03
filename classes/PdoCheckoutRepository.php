<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Loads only the invoice ownership and wallet data required to check payment.
 */
final class PdoCheckoutRepository implements CheckoutRepository
{
    private PDO $pdo;

    public function __construct(Database|PDO $database)
    {
        $this->pdo = $database instanceof Database ? $database->getPdo() : $database;
    }

    public function findInvoiceWallet(string $invoiceId): ?array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT i.id, i.store_id, s.wallet_path
                 FROM invoices AS i
                 INNER JOIN stores AS s ON s.id = i.store_id
                 WHERE i.id = ?
                 LIMIT 1'
            );
            $statement->execute([$invoiceId]);
            $row = $statement->fetch();
        } catch (Throwable $exception) {
            throw new CheckoutException(
                'Payment details cannot be loaded at this time.',
                503,
                'load_invoice_wallet',
                $exception
            );
        }

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => $this->storedString($row['id'] ?? null, 'invoice ID', 50),
            'store_id' => $this->storedString($row['store_id'] ?? null, 'store ID', 50),
            'wallet_path' => $this->storedString($row['wallet_path'] ?? null, 'wallet path', 4096),
        ];
    }

    private function storedString(mixed $value, string $field, int $maxBytes): string
    {
        if (!is_string($value)) {
            throw new CheckoutException(
                'Stored payment details are invalid.',
                500,
                'validate_' . str_replace(' ', '_', $field)
            );
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes || str_contains($value, "\0")) {
            throw new CheckoutException(
                'Stored payment details are invalid.',
                500,
                'validate_' . str_replace(' ', '_', $field)
            );
        }

        return $value;
    }
}
