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
            'SELECT id, name, api_key, wallet_path,
                    (SELECT COUNT(*) FROM invoices i WHERE i.store_id = stores.id) AS invoice_count,
                    (SELECT COUNT(*) FROM webhooks w WHERE w.store_id = stores.id) AS webhook_count,
                    (SELECT COUNT(*) FROM payouts p WHERE p.store_id = stores.id) AS payout_count
             FROM stores WHERE user_id = ? ORDER BY name, id'
        );
        $statement->execute([$userId]);

        return array_map(
            fn (array $row): array => [
                'id' => $this->string($row['id'] ?? null, 'store id'),
                'name' => $this->string($row['name'] ?? null, 'store name'),
                'api_key' => $this->string($row['api_key'] ?? null, 'store API key'),
                'wallet_path' => $this->string($row['wallet_path'] ?? null, 'store wallet path'),
                'invoice_count' => $this->nonNegativeInt($row['invoice_count'] ?? null),
                'webhook_count' => $this->nonNegativeInt($row['webhook_count'] ?? null),
                'payout_count' => $this->nonNegativeInt($row['payout_count'] ?? null),
            ],
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function findAssignedWallet(int $userId): ?string
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT wallet_path FROM client_wallets WHERE user_id = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $walletPath = $statement->fetchColumn();

        return $walletPath === false
            ? null
            : $this->string($walletPath, 'assigned wallet path');
    }

    public function assignWallet(int $userId, string $walletPath, int $assignedAt): void
    {
        $statement = $this->database->getPdo()->prepare(
            "INSERT INTO client_wallets (user_id, wallet_path, created_at, updated_at)
             SELECT id, ?, ?, ? FROM users
             WHERE id = ? AND role = 'client' AND status = 'active'"
        );
        $statement->execute([$walletPath, $assignedAt, $assignedAt, $userId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Client wallet could not be assigned.');
        }
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

    public function fetchPayouts(int $userId, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Client payout limit is outside the allowed range.');
        }
        $statement = $this->database->getPdo()->prepare(
            'SELECT p.id, p.destination, p.payout_amount, p.exchange_fee, p.state, p.txid,
                    p.created_at, p.updated_at, s.id AS store_id, s.name AS store_name
             FROM payouts p
             INNER JOIN stores s ON s.id = p.store_id
             WHERE s.user_id = :user_id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :payout_limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':payout_limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fetchIntegrations(int $userId): array
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT si.name, si.version, si.shop_origin, si.first_seen_at, si.last_seen_at,
                    s.id AS store_id, s.name AS store_name
             FROM store_integrations si
             INNER JOIN stores s ON s.id = si.store_id
             WHERE s.user_id = ?
             ORDER BY si.last_seen_at DESC, si.id DESC'
        );
        $statement->execute([$userId]);
        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fetchRequests(int $userId, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Client request limit is outside the allowed range.');
        }
        $statement = $this->database->getPdo()->prepare(
            'SELECT ar.method, ar.request_path, ar.http_status, ar.duration_ms, ar.created_at,
                    s.id AS store_id, s.name AS store_name
             FROM api_request_log ar
             INNER JOIN stores s ON s.id = ar.store_id
             WHERE s.user_id = :user_id
             ORDER BY ar.created_at DESC, ar.id DESC
             LIMIT :request_limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':request_limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function createStore(int $userId, string $id, string $name, string $apiKey, string $walletPath): void
    {
        $statement = $this->database->getPdo()->prepare(
            'INSERT INTO stores (id, name, api_key, wallet_path, user_id)
             SELECT ?, ?, ?, wallet_path, user_id
             FROM client_wallets
             WHERE user_id = ? AND wallet_path = ?'
        );
        $statement->execute([$id, $name, $apiKey, $userId, $walletPath]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Store wallet ownership could not be verified.');
        }
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

    public function updateStoreName(int $userId, string $storeId, string $name): bool
    {
        $statement = $this->database->getPdo()->prepare(
            'UPDATE stores SET name = ? WHERE id = ? AND user_id = ?'
        );
        $statement->execute([$name, $storeId, $userId]);
        return $statement->rowCount() === 1 || $this->ownsStore($userId, $storeId);
    }

    public function rotateStoreApiKey(int $userId, string $storeId, string $apiKey): bool
    {
        $statement = $this->database->getPdo()->prepare(
            'UPDATE stores SET api_key = ? WHERE id = ? AND user_id = ?'
        );
        $statement->execute([$apiKey, $storeId, $userId]);
        return $statement->rowCount() === 1;
    }

    public function deleteEmptyStore(int $userId, string $storeId): bool
    {
        $statement = $this->database->getPdo()->prepare(
            'DELETE FROM stores
             WHERE id = ? AND user_id = ?
               AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.store_id = stores.id)
               AND NOT EXISTS (SELECT 1 FROM payouts p WHERE p.store_id = stores.id)'
        );
        $statement->execute([$storeId, $userId]);
        return $statement->rowCount() === 1;
    }

    public function updateWebhookUrl(int $userId, string $webhookId, string $url): bool
    {
        $statement = $this->database->getPdo()->prepare(
            'UPDATE webhooks SET url = ?
             WHERE id = ? AND store_id IN (SELECT id FROM stores WHERE user_id = ?)'
        );
        $statement->execute([$url, $webhookId, $userId]);
        return $statement->rowCount() === 1 || $this->ownsWebhook($userId, $webhookId);
    }

    public function rotateWebhookSecret(int $userId, string $webhookId, string $secret): bool
    {
        $statement = $this->database->getPdo()->prepare(
            'UPDATE webhooks SET secret = ?
             WHERE id = ? AND store_id IN (SELECT id FROM stores WHERE user_id = ?)'
        );
        $statement->execute([$secret, $webhookId, $userId]);
        return $statement->rowCount() === 1;
    }

    private function ownsWebhook(int $userId, string $webhookId): bool
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT 1 FROM webhooks w
             INNER JOIN stores s ON s.id = w.store_id
             WHERE w.id = ? AND s.user_id = ? LIMIT 1'
        );
        $statement->execute([$webhookId, $userId]);
        return $statement->fetchColumn() !== false;
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
