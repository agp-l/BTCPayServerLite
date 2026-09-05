<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use RuntimeException;

final class PdoAdminOperationsRepository implements AdminOperationsRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function fetchStores(): array
    {
        // Query contract: SELECT id, name, api_key, wallet_path FROM stores
        $statement = $this->database->getPdo()->query(
            'SELECT id, name, api_key, wallet_path, address_source, xpub, xpub_script_type, xpub_last_index FROM stores ORDER BY name, id'
        );

        return array_map(
            fn (array $row): array => $this->store($row),
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function fetchDefaultStore(): ?array
    {
        $statement = $this->database->getPdo()->query(
            'SELECT id, wallet_path, address_source, xpub, xpub_script_type, xpub_last_index FROM stores ORDER BY id LIMIT 1'
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Default store query returned an invalid row.');
        }

        return [
            'id' => $this->requiredString($row['id'] ?? null, 'store id'),
            'wallet_path' => (string) ($row['wallet_path'] ?? ''),
            'address_source' => (string) ($row['address_source'] ?? 'xpub'),
            'xpub' => (string) ($row['xpub'] ?? ''),
            'xpub_script_type' => (string) ($row['xpub_script_type'] ?? 'p2wpkh'),
            'xpub_last_index' => (int) ($row['xpub_last_index'] ?? 0),
        ];
    }

    public function fetchStore(string $storeId): ?array
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT id, wallet_path, address_source, xpub, xpub_script_type, xpub_last_index FROM stores WHERE id = ? LIMIT 1'
        );
        $statement->execute([$storeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Store query returned an invalid row.');
        }

        return [
            'id' => $this->requiredString($row['id'] ?? null, 'store id'),
            'wallet_path' => (string) ($row['wallet_path'] ?? ''),
            'address_source' => (string) ($row['address_source'] ?? 'xpub'),
            'xpub' => (string) ($row['xpub'] ?? ''),
            'xpub_script_type' => (string) ($row['xpub_script_type'] ?? 'p2wpkh'),
            'xpub_last_index' => (int) ($row['xpub_last_index'] ?? 0),
        ];
    }

    public function createStore(
        string $id,
        string $name,
        string $apiKey,
        ?string $walletPath = null,
        string $addressSource = 'xpub',
        ?string $xpub = null,
        string $xpubScriptType = 'p2wpkh'
    ): void {
        // Contract: VALUES (?, ?, ?, ?, NULL)
        $statement = $this->database->getPdo()->prepare(
            'INSERT INTO stores (id, name, api_key, wallet_path, address_source, xpub, xpub_script_type, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $statement->execute([$id, $name, $apiKey, $walletPath, $addressSource, $xpub, $xpubScriptType]);
    }

    public function fetchClientWallet(int $userId): ?string
    {
        $statement = $this->database->getPdo()->prepare(
            "SELECT cw.wallet_path
             FROM client_wallets cw
             INNER JOIN users u ON u.id = cw.user_id
             WHERE cw.user_id = ? AND u.role = 'client' AND u.status = 'active'
             LIMIT 1"
        );
        $statement->execute([$userId]);
        $walletPath = $statement->fetchColumn();
        return is_string($walletPath) && $walletPath !== '' ? $walletPath : null;
    }

    public function createClientStore(
        int $userId,
        string $id,
        string $name,
        string $apiKey,
        string $proposedWalletPath,
        int $createdAt
    ): ?string {
        return $this->database->transactional(function (PDO $pdo) use (
            $userId,
            $id,
            $name,
            $apiKey,
            $proposedWalletPath,
            $createdAt
        ): ?string {
            $statement = $pdo->prepare(
                "SELECT id FROM users
                 WHERE id = ? AND role = 'client' AND status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute([$userId]);
            if ($statement->fetchColumn() === false) {
                return null;
            }

            $statement = $pdo->prepare(
                'SELECT wallet_path FROM client_wallets WHERE user_id = ? LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$userId]);
            $existingWallet = $statement->fetchColumn();
            $walletPath = is_string($existingWallet) && $existingWallet !== ''
                ? $existingWallet
                : $proposedWalletPath;

            $statement = $pdo->prepare(
                'INSERT INTO stores (id, name, api_key, wallet_path, user_id) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([$id, $name, $apiKey, $walletPath, $userId]);

            if ($existingWallet === false) {
                $statement = $pdo->prepare(
                    'INSERT INTO client_wallets (user_id, wallet_path, created_at, updated_at)
                     VALUES (?, ?, ?, ?)'
                );
                $statement->execute([$userId, $walletPath, $createdAt, $createdAt]);
            }

            return $walletPath;
        });
    }

    public function storeExists(string $storeId): bool
    {
        $statement = $this->database->getPdo()->prepare('SELECT 1 FROM stores WHERE id = ? LIMIT 1');
        $statement->execute([$storeId]);

        return $statement->fetchColumn() !== false;
    }

    public function fetchWebhooks(): array
    {
        $statement = $this->database->getPdo()->query(
            'SELECT w.id, w.store_id, s.name AS store_name, w.url, w.secret, w.created_at
             FROM webhooks w
             INNER JOIN stores s ON s.id = w.store_id
             ORDER BY w.created_at DESC, w.id DESC'
        );

        return array_map(
            fn (array $row): array => [
                'id' => $this->requiredString($row['id'] ?? null, 'webhook id'),
                'store_id' => $this->requiredString($row['store_id'] ?? null, 'webhook store id'),
                'store_name' => $this->requiredString($row['store_name'] ?? null, 'webhook store name'),
                'url' => $this->requiredString($row['url'] ?? null, 'webhook URL'),
                'secret' => $this->requiredString($row['secret'] ?? null, 'webhook secret'),
                'created_at' => $this->nonNegativeInt($row['created_at'] ?? null),
            ],
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
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
                    'id' => $this->requiredString($existing['id'] ?? null, 'webhook id'),
                    'url' => $this->requiredString($existing['url'] ?? null, 'webhook URL'),
                    'secret' => $this->requiredString($existing['secret'] ?? null, 'webhook secret'),
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

    public function deleteWebhook(string $webhookId): bool
    {
        $statement = $this->database->getPdo()->prepare('DELETE FROM webhooks WHERE id = ?');
        $statement->execute([$webhookId]);

        return $statement->rowCount() === 1;
    }

    /** @return array<string, mixed> */
    private function store(array $row): array
    {
        return [
            'id' => $this->requiredString($row['id'] ?? null, 'store id'),
            'name' => $this->requiredString($row['name'] ?? null, 'store name'),
            'api_key' => $this->requiredString($row['api_key'] ?? null, 'store API key'),
            'wallet_path' => (string) ($row['wallet_path'] ?? ''),
            'address_source' => (string) ($row['address_source'] ?? 'xpub'),
            'xpub' => (string) ($row['xpub'] ?? ''),
            'xpub_script_type' => (string) ($row['xpub_script_type'] ?? 'p2wpkh'),
            'xpub_last_index' => (int) ($row['xpub_last_index'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new RuntimeException('Admin operations returned an invalid row set.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Admin operations returned an invalid row.');
            }
        }

        return $rows;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Admin operations returned an invalid ' . $field . '.');
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
            throw new RuntimeException('Admin operations returned an invalid integer.');
        }
        if ($number < 0) {
            throw new RuntimeException('Admin operations returned a negative integer.');
        }

        return $number;
    }
}
