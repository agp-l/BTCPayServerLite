<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use RuntimeException;

final class PdoAdminManagementRepository implements AdminManagementRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function fetchClients(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, email, status FROM users WHERE role = 'client' ORDER BY email, id"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
        ], $this->rows($rows));
    }

    public function fetchSummary(?int $userId): array
    {
        [$where, $params] = $this->userWhere($userId, 's');
        $statement = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT s.id) AS total_stores,
                    COUNT(i.id) AS total_invoices,
                    COALESCE(SUM(i.status = 'Settled'), 0) AS settled_invoices,
                    COALESCE(SUM(CASE WHEN i.status = 'Settled' THEN i.amount ELSE 0 END), 0) AS total_btc_volume
             FROM stores s
             LEFT JOIN invoices i ON i.store_id = s.id{$where}"
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Admin management summary could not be loaded.');
        }

        return [
            'total_stores' => (int) $row['total_stores'],
            'total_invoices' => (int) $row['total_invoices'],
            'settled_invoices' => (int) $row['settled_invoices'],
            'total_btc_volume' => number_format((float) $row['total_btc_volume'], 8, '.', ''),
        ];
    }

    public function fetchInvoices(?int $userId, ?string $storeId, ?string $status, int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new RuntimeException('Admin invoice limit is invalid.');
        }
        [$conditions, $params] = $this->conditionsForUser($userId, 's');
        if ($storeId !== null) {
            $conditions[] = 's.id = :store_id';
            $params['store_id'] = $storeId;
        }
        if ($status !== null) {
            $conditions[] = 'i.status = :invoice_status';
            $params['invoice_status'] = $status;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $statement = $this->pdo->prepare(
            "SELECT i.id, i.store_id, s.name AS store_name, s.user_id,
                    COALESCE(u.email, 'Systém / bez klienta') AS client_email,
                    i.amount, i.status, i.created_at, i.expires_at
             FROM invoices i
             INNER JOIN stores s ON s.id = i.store_id
             LEFT JOIN users u ON u.id = s.user_id{$where}
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT :row_limit"
        );
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fetchStores(?int $userId): array
    {
        [$where, $params] = $this->userWhere($userId, 's');
        $statement = $this->pdo->prepare(
            "SELECT s.id, s.name, s.api_key, s.wallet_path, s.user_id,
                    COALESCE(u.email, 'Systém / bez klienta') AS client_email,
                    (SELECT COUNT(*) FROM invoices i WHERE i.store_id = s.id) AS invoice_count,
                    (SELECT COUNT(*) FROM webhooks w WHERE w.store_id = s.id) AS webhook_count,
                    (SELECT COUNT(*) FROM payouts p WHERE p.store_id = s.id) AS payout_count
             FROM stores s
             LEFT JOIN users u ON u.id = s.user_id{$where}
             ORDER BY client_email, s.name, s.id"
        );
        $statement->execute($params);
        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fetchWebhooks(?int $userId, ?string $storeId): array
    {
        [$conditions, $params] = $this->conditionsForUser($userId, 's');
        if ($storeId !== null) {
            $conditions[] = 's.id = :store_id';
            $params['store_id'] = $storeId;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $statement = $this->pdo->prepare(
            "SELECT w.id, w.store_id, s.name AS store_name, s.user_id,
                    COALESCE(u.email, 'Systém / bez klienta') AS client_email,
                    w.url, w.secret, w.created_at,
                    (SELECT MAX(COALESCE(wd.delivered_at, wd.created_at))
                       FROM webhook_deliveries wd WHERE wd.webhook_id = w.id) AS last_delivery_at
             FROM webhooks w
             INNER JOIN stores s ON s.id = w.store_id
             LEFT JOIN users u ON u.id = s.user_id{$where}
             ORDER BY w.created_at DESC, w.id DESC"
        );
        $statement->execute($params);
        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{0:string,1:array<string,int>} */
    private function userWhere(?int $userId, string $alias): array
    {
        [$conditions, $params] = $this->conditionsForUser($userId, $alias);
        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return array{0:list<string>,1:array<string,int>} */
    private function conditionsForUser(?int $userId, string $alias): array
    {
        if ($userId === null) {
            return [[], []];
        }
        if ($userId === 0) {
            return [["{$alias}.user_id IS NULL"], []];
        }
        return [["{$alias}.user_id = :user_id"], ['user_id' => $userId]];
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new RuntimeException('Admin management returned an invalid row set.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Admin management returned an invalid row.');
            }
        }
        return $rows;
    }
}
