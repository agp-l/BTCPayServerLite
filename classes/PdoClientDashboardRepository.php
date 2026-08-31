<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use RuntimeException;

final class PdoClientDashboardRepository implements ClientDashboardRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function fetchSummary(int $userId): array
    {
        $statement = $this->database->getPdo()->prepare(
            "SELECT
                COUNT(DISTINCT s.id) AS total_stores,
                COUNT(i.id) AS total_invoices,
                COALESCE(SUM(i.status = 'Settled'), 0) AS paid_invoices
             FROM stores s
             LEFT JOIN invoices i ON i.store_id = s.id
             WHERE s.user_id = ?"
        );
        $statement->execute([$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Client dashboard summary could not be loaded.');
        }

        return [
            'total_stores' => $this->nonNegativeInt($row['total_stores'] ?? null),
            'total_invoices' => $this->nonNegativeInt($row['total_invoices'] ?? null),
            'paid_invoices' => $this->nonNegativeInt($row['paid_invoices'] ?? null),
        ];
    }

    public function fetchStores(int $userId): array
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT id, name, api_key, wallet_path FROM stores WHERE user_id = ? ORDER BY name, id'
        );
        $statement->execute([$userId]);

        return array_map(
            fn (array $row): array => [
                'id' => $this->string($row['id'] ?? null, 'store id'),
                'name' => $this->string($row['name'] ?? null, 'store name'),
                'api_key' => $this->string($row['api_key'] ?? null, 'store API key'),
                'wallet_path' => $this->string($row['wallet_path'] ?? null, 'store wallet path'),
            ],
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function fetchInvoices(int $userId, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Client invoice limit is outside the allowed range.');
        }

        $statement = $this->database->getPdo()->prepare(
            'SELECT i.id, i.store_id, s.name AS store_name, i.amount, i.status, i.created_at
             FROM invoices i
             INNER JOIN stores s ON s.id = i.store_id
             WHERE s.user_id = :user_id
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT :invoice_limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':invoice_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            fn (array $row): array => [
                'id' => $this->string($row['id'] ?? null, 'invoice id'),
                'store_id' => $this->string($row['store_id'] ?? null, 'invoice store id'),
                'store_name' => $this->string($row['store_name'] ?? null, 'invoice store name'),
                'amount' => $this->bitcoinAmount($row['amount'] ?? null),
                'status' => $this->string($row['status'] ?? null, 'invoice status'),
                'created_at' => $this->nonNegativeInt($row['created_at'] ?? null),
            ],
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function fetchWebhooks(int $userId): array
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT w.id, w.store_id, s.name AS store_name, w.url, w.secret, w.created_at
             FROM webhooks w
             INNER JOIN stores s ON s.id = w.store_id
             WHERE s.user_id = ?
             ORDER BY w.created_at DESC, w.id DESC'
        );
        $statement->execute([$userId]);

        return array_map(
            fn (array $row): array => [
                'id' => $this->string($row['id'] ?? null, 'webhook id'),
                'store_id' => $this->string($row['store_id'] ?? null, 'webhook store id'),
                'store_name' => $this->string($row['store_name'] ?? null, 'webhook store name'),
                'url' => $this->string($row['url'] ?? null, 'webhook URL'),
                'secret' => $this->string($row['secret'] ?? null, 'webhook secret'),
                'created_at' => $this->nonNegativeInt($row['created_at'] ?? null),
            ],
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function createStore(int $userId, string $id, string $name, string $apiKey, string $walletPath): void
    {
        $statement = $this->database->getPdo()->prepare(
            'INSERT INTO stores (id, name, api_key, wallet_path, user_id) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$id, $name, $apiKey, $walletPath, $userId]);
    }

    public function ownsStore(int $userId, string $storeId): bool
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT 1 FROM stores WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $statement->execute([$storeId, $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function findOrCreateWebhook(string $storeId, string $url, int $createdAt): array
    {
        return $this->database->transactional(function (PDO $pdo) use ($storeId, $url, $createdAt): array {
            $statement = $pdo->prepare(
                'SELECT id, url, secret FROM webhooks
                 WHERE store_id = ? AND url = ? LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$storeId, $url]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                return [
                    'id' => $this->string($existing['id'] ?? null, 'webhook id'),
                    'url' => $this->string($existing['url'] ?? null, 'webhook URL'),
                    'secret' => $this->string($existing['secret'] ?? null, 'webhook secret'),
                ];
            }

            $webhook = [
                'id' => 'wh_' . bin2hex(random_bytes(16)),
                'url' => $url,
                'secret' => bin2hex(random_bytes(32)),
            ];
            $statement = $pdo->prepare(
                'INSERT INTO webhooks (id, store_id, url, secret, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $webhook['id'],
                $storeId,
                $webhook['url'],
                $webhook['secret'],
                $createdAt,
            ]);

            return $webhook;
        });
    }

    public function deleteWebhook(int $userId, string $webhookId): bool
    {
        $statement = $this->database->getPdo()->prepare(
            'DELETE FROM webhooks
             WHERE id = ?
               AND store_id IN (SELECT id FROM stores WHERE user_id = ?)'
        );
        $statement->execute([$webhookId, $userId]);

        return $statement->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new RuntimeException('Client dashboard returned an invalid row set.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Client dashboard returned an invalid row.');
            }
        }

        return $rows;
    }

    private function string(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Client dashboard returned an invalid ' . $field . '.');
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            throw new RuntimeException('Client dashboard returned an invalid integer.');
        }
        if ($number < 0) {
            throw new RuntimeException('Client dashboard returned a negative integer.');
        }

        return $number;
    }

    private function bitcoinAmount(mixed $value): string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new RuntimeException('Client dashboard returned an invalid Bitcoin amount.');
        }

        return BitcoinAmount::fromBtc($value)->toBtcString();
    }
}
