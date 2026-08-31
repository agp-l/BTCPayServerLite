<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use RuntimeException;

final class PdoAdminDashboardRepository implements AdminDashboardRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchSummary(): array
    {
        $row = $this->pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM stores) AS total_stores,
                COUNT(*) AS total_invoices,
                COALESCE(SUM(status = 'Settled'), 0) AS settled_invoices,
                COALESCE(SUM(CASE WHEN status = 'Settled' THEN amount ELSE 0 END), 0) AS total_btc_volume
             FROM invoices"
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('Dashboard summary could not be loaded.');
        }

        return [
            'total_stores' => $this->toNonNegativeInt($row['total_stores'] ?? null),
            'total_invoices' => $this->toNonNegativeInt($row['total_invoices'] ?? null),
            'settled_invoices' => $this->toNonNegativeInt($row['settled_invoices'] ?? null),
            'total_btc_volume' => $this->normalizeBitcoinAmount($row['total_btc_volume'] ?? null),
        ];
    }

    public function fetchRecentInvoices(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Dashboard invoice limit is outside the allowed range.');
        }

        $statement = $this->pdo->prepare(
            'SELECT
                i.id,
                i.store_id,
                COALESCE(s.name, \'Deleted store\') AS store_name,
                i.amount,
                i.status,
                i.created_at
             FROM invoices i
             LEFT JOIN stores s ON s.id = i.store_id
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT :invoice_limit'
        );
        $statement->bindValue(':invoice_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw new RuntimeException('Recent invoices could not be loaded.');
        }

        $invoices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $this->requiredString($row['id'] ?? null, 'invoice id');
            $storeId = $this->requiredString($row['store_id'] ?? null, 'store id');

            $invoices[] = [
                'id' => $id,
                'store_id' => $storeId,
                'store_name' => $this->requiredString($row['store_name'] ?? null, 'store name'),
                'amount' => $this->normalizeBitcoinAmount($row['amount'] ?? null),
                'status' => $this->requiredString($row['status'] ?? null, 'invoice status'),
                'created_at' => $this->toNonNegativeInt($row['created_at'] ?? null),
            ];
        }

        return $invoices;
    }

    private function toNonNegativeInt(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Dashboard returned an invalid integer value.');
        }

        $normalized = (int) $value;
        if ($normalized < 0) {
            throw new RuntimeException('Dashboard returned a negative integer value.');
        }

        return $normalized;
    }

    private function normalizeBitcoinAmount(mixed $value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Dashboard returned an invalid Bitcoin amount.');
        }

        $raw = (string) $value;
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/', $raw)) {
            throw new RuntimeException('Dashboard returned an invalid Bitcoin amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');

        return $whole . '.' . str_pad($fraction, 8, '0');
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Dashboard returned an invalid ' . $field . '.');
        }

        return $value;
    }
}
