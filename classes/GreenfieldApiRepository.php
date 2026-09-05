<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use PDOException;
use Throwable;

/** Persistence boundary for Greenfield store and webhook operations. */
class GreenfieldApiRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /** @return array{id:string,name:string,api_key:string,wallet_path:string}|null */
    public function findStore(string $storeId): ?array
    {
        return $this->findStoreBy('id', $storeId);
    }

    /** @return array{id:string,name:string,api_key:string,wallet_path:string}|null */
    public function findStoreByApiKey(string $apiKey): ?array
    {
        return $this->findStoreBy('api_key', $apiKey);
    }

    /** @return list<array{id:string,url:string,secret:string}> */
    public function listWebhooks(string $storeId): array
    {
        try {
            $statement = $this->database->getPdo()->prepare(
                'SELECT id, url, secret FROM webhooks WHERE store_id = ? ORDER BY created_at, id LIMIT 200'
            );
            $statement->execute([$storeId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new GreenfieldApiException('Webhooks could not be loaded.', 'list_webhooks', 500, $exception);
        }

        if (!is_array($rows)) {
            throw new GreenfieldApiException('Stored webhook list is invalid.', 'list_webhooks');
        }

        $webhooks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new GreenfieldApiException('Stored webhook data is invalid.', 'list_webhooks');
            }
            $webhooks[] = $this->normalizeWebhook($row);
        }
        return $webhooks;
    }

    /** @return array{id:string,url:string,secret:string} */
    public function findOrCreateWebhook(string $storeId, string $url, ?string $requestedSecret = null): array
    {
        try {
            return $this->database->transactional(function (PDO $pdo) use ($storeId, $url, $requestedSecret): array {
                $statement = $pdo->prepare(
                    'SELECT id, url, secret FROM webhooks WHERE store_id = ? AND url = ? LIMIT 1 FOR UPDATE'
                );
                $statement->execute([$storeId, $url]);
                $existing = $statement->fetch(PDO::FETCH_ASSOC);
                if (is_array($existing)) {
                    return $this->normalizeWebhook($existing);
                }

                $webhook = [
                    'id' => 'wh_' . bin2hex(random_bytes(16)),
                    'url' => $url,
                    'secret' => $requestedSecret ?? bin2hex(random_bytes(32)),
                ];
                $insert = $pdo->prepare(
                    'INSERT INTO webhooks (id, store_id, url, secret, created_at) VALUES (?, ?, ?, ?, ?)'
                );
                $insert->execute([$webhook['id'], $storeId, $webhook['url'], $webhook['secret'], time()]);
                return $webhook;
            });
        } catch (GreenfieldApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new GreenfieldApiException('Webhook could not be stored.', 'create_webhook', 500, $exception);
        }
    }

    /** @return array{id:string,name:string,api_key:string,wallet_path:string}|null */
    private function findStoreBy(string $column, string $value): ?array
    {
        if (!in_array($column, ['id', 'api_key'], true)) {
            throw new GreenfieldApiException('Store lookup is invalid.', 'find_store');
        }
        try {
            $statement = $this->database->getPdo()->prepare(
                "SELECT id, name, api_key, wallet_path, address_source, xpub, xpub_script_type, xpub_last_index FROM stores WHERE {$column} = ? LIMIT 1"
            );
            $statement->execute([$value]);
            $store = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new GreenfieldApiException('Store could not be loaded.', 'find_store', 500, $exception);
        }

        if ($store === false) {
            return null;
        }
        if (!is_array($store)
            || !is_string($store['id'] ?? null)
            || !is_string($store['name'] ?? null)
            || !is_string($store['api_key'] ?? null)
        ) {
            throw new GreenfieldApiException('Stored store data is invalid.', 'find_store');
        }
        return [
            'id' => $store['id'],
            'name' => $store['name'],
            'api_key' => $store['api_key'],
            'wallet_path' => (string) ($store['wallet_path'] ?? ''),
            'address_source' => (string) ($store['address_source'] ?? 'electrum'),
            'xpub' => (string) ($store['xpub'] ?? ''),
            'xpub_script_type' => (string) ($store['xpub_script_type'] ?? 'p2wpkh'),
            'xpub_last_index' => (int) ($store['xpub_last_index'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $webhook @return array{id:string,url:string,secret:string} */
    private function normalizeWebhook(array $webhook): array
    {
        if (!is_string($webhook['id'] ?? null)
            || !is_string($webhook['url'] ?? null)
            || !is_string($webhook['secret'] ?? null)
            || $webhook['id'] === ''
            || $webhook['url'] === ''
            || $webhook['secret'] === ''
        ) {
            throw new GreenfieldApiException('Stored webhook data is invalid.', 'find_webhook');
        }
        return ['id' => $webhook['id'], 'url' => $webhook['url'], 'secret' => $webhook['secret']];
    }
}
