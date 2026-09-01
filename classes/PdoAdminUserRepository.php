<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use RuntimeException;

final class PdoAdminUserRepository implements AdminUserRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function listClients(int $limit): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new RuntimeException('Client list limit is invalid.');
        }
        $statement = $this->database->getPdo()->prepare(
            $this->clientSelect()
            . " WHERE u.role = 'client'
                ORDER BY COALESCE(u.last_seen_at, u.last_login_at, 0) DESC, u.id DESC
                LIMIT :client_limit"
        );
        $statement->bindValue(':client_limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(fn (array $row): array => $this->client($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findClient(int $userId): ?array
    {
        $statement = $this->database->getPdo()->prepare(
            $this->clientSelect() . " WHERE u.role = 'client' AND u.id = ? LIMIT 1"
        );
        $statement->execute([$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->client($row) : null;
    }

    public function listStores(int $userId): array
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT s.id, s.name, s.wallet_path,
                    COUNT(DISTINCT i.id) AS invoice_count,
                    MAX(i.created_at) AS last_invoice_at,
                    COUNT(DISTINCT w.id) AS webhook_count
             FROM stores s
             LEFT JOIN invoices i ON i.store_id = s.id
             LEFT JOIN webhooks w ON w.store_id = s.id
             WHERE s.user_id = ?
             GROUP BY s.id, s.name, s.wallet_path
             ORDER BY s.name, s.id'
        );
        $statement->execute([$userId]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'wallet_path' => (string) $row['wallet_path'],
            'invoice_count' => (int) $row['invoice_count'],
            'last_invoice_at' => $row['last_invoice_at'] === null ? null : (int) $row['last_invoice_at'],
            'webhook_count' => (int) $row['webhook_count'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listIntegrations(int $userId): array
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
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listRequests(int $userId, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->database->getPdo()->prepare(
            'SELECT ar.method, ar.request_path, ar.http_status, ar.duration_ms, ar.client_ip,
                    ar.integration_name, ar.integration_version, ar.shop_origin, ar.created_at,
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
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listPayouts(int $userId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->database->getPdo()->prepare(
            'SELECT p.id, p.destination, p.payout_amount, p.exchange_fee, p.state,
                    p.txid, p.created_at, p.updated_at, s.id AS store_id, s.name AS store_name
             FROM payouts p
             INNER JOIN stores s ON s.id = p.store_id
             WHERE s.user_id = :user_id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :payout_limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':payout_limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setClientStatus(int $userId, string $status): bool
    {
        $statement = $this->database->getPdo()->prepare(
            "UPDATE users
             SET status = ?, session_version = session_version + 1
             WHERE id = ? AND role = 'client' AND status <> ?"
        );
        $statement->execute([$status, $userId, $status]);
        if ($statement->rowCount() === 1) {
            return true;
        }
        $statement = $this->database->getPdo()->prepare(
            "SELECT 1 FROM users WHERE id = ? AND role = 'client' AND status = ? LIMIT 1"
        );
        $statement->execute([$userId, $status]);
        return $statement->fetchColumn() !== false;
    }

    public function adoptSingleWallet(int $userId, int $assignedAt): bool
    {
        return $this->database->transactional(function (PDO $pdo) use ($userId, $assignedAt): bool {
            $statement = $pdo->prepare(
                'SELECT wallet_path FROM client_wallets WHERE user_id = ? LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$userId]);
            if ($statement->fetchColumn() !== false) {
                return true;
            }

            $statement = $pdo->prepare(
                "SELECT s.wallet_path
                 FROM stores s
                 INNER JOIN users u ON u.id = s.user_id
                 WHERE s.user_id = ? AND u.role = 'client'
                 FOR UPDATE"
            );
            $statement->execute([$userId]);
            $paths = [];
            $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($rows)) {
                return false;
            }
            foreach ($rows as $walletPath) {
                if (is_string($walletPath) && $walletPath !== '') {
                    $paths[$walletPath] = true;
                }
            }
            if (count($paths) !== 1) {
                return false;
            }

            $walletPath = (string) array_key_first($paths);
            $statement = $pdo->prepare(
                'INSERT INTO client_wallets (user_id, wallet_path, created_at, updated_at)
                 VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$userId, $walletPath, $assignedAt, $assignedAt]);
            return $statement->rowCount() === 1;
        });
    }

    public function setClientWallet(int $userId, string $walletPath, int $assignedAt): bool
    {
        return $this->database->transactional(function (PDO $pdo) use ($userId, $walletPath, $assignedAt): bool {
            $statement = $pdo->prepare(
                "SELECT 1 FROM stores s
                 INNER JOIN users u ON u.id = s.user_id
                 WHERE s.user_id = ? AND s.wallet_path = ? AND u.role = 'client'
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute([$userId, $walletPath]);
            if ($statement->fetchColumn() === false) {
                return false;
            }
            $statement = $pdo->prepare(
                'INSERT INTO client_wallets (user_id, wallet_path, created_at, updated_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE wallet_path = VALUES(wallet_path), updated_at = VALUES(updated_at)'
            );
            $statement->execute([$userId, $walletPath, $assignedAt, $assignedAt]);
            return true;
        });
    }

    private function clientSelect(): string
    {
        return "SELECT
                    u.id, u.email, u.status, u.created_at, u.last_login_at, u.last_login_ip,
                    u.last_seen_at, u.last_seen_ip, cw.wallet_path,
                    (SELECT COUNT(*) FROM stores s WHERE s.user_id = u.id) AS store_count,
                    (SELECT COUNT(DISTINCT s.wallet_path) FROM stores s WHERE s.user_id = u.id) AS wallet_count,
                    (SELECT COUNT(*) FROM invoices i INNER JOIN stores s ON s.id = i.store_id
                        WHERE s.user_id = u.id) AS invoice_count,
                    (SELECT MAX(i.created_at) FROM invoices i INNER JOIN stores s ON s.id = i.store_id
                        WHERE s.user_id = u.id) AS last_invoice_at,
                    (SELECT COUNT(*) FROM payouts p INNER JOIN stores s ON s.id = p.store_id
                        WHERE s.user_id = u.id) AS payout_count,
                    (SELECT COUNT(*) FROM webhooks w INNER JOIN stores s ON s.id = w.store_id
                        WHERE s.user_id = u.id) AS webhook_count,
                    (SELECT COUNT(*) FROM store_integrations si INNER JOIN stores s ON s.id = si.store_id
                        WHERE s.user_id = u.id) AS integration_count,
                    (SELECT COUNT(*) FROM api_request_log ar INNER JOIN stores s ON s.id = ar.store_id
                        WHERE s.user_id = u.id) AS request_count,
                    (SELECT MAX(ar.created_at) FROM api_request_log ar INNER JOIN stores s ON s.id = ar.store_id
                        WHERE s.user_id = u.id) AS last_request_at
                FROM users u
                LEFT JOIN client_wallets cw ON cw.user_id = u.id";
    }

    /** @param array<string,mixed> $row */
    private function client(array $row): array
    {
        foreach (['id', 'store_count', 'wallet_count', 'invoice_count', 'payout_count', 'webhook_count', 'integration_count', 'request_count'] as $field) {
            $row[$field] = (int) ($row[$field] ?? 0);
        }
        foreach (['last_login_at', 'last_seen_at', 'last_invoice_at', 'last_request_at'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['wallet_path'] = is_string($row['wallet_path'] ?? null) ? $row['wallet_path'] : null;
        return $row;
    }
}
