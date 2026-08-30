<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use PDOException;
use Throwable;

/**
 * Persistence boundary for stores and Greenfield webhook registrations.
 */
class GreenfieldApiRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * @return array{id: string, name: string, api_key: string, wallet_path: string}|null
     */
    public function findStore(string $storeId): ?array
    {
        try {
            $statement = $this->database->getPdo()->prepare(
                'SELECT id, name, api_key, wallet_path FROM stores WHERE id = ? LIMIT 1'
            );
            $statement->execute([$storeId]);
            $store = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new GreenfieldApiException(
                'Store could not be loaded.',
                'find_store',
                500,
                $exception
            );
        }

        if ($store === false) {
            return null;
        }
        if (
            !is_array($store)
            || !is_string($store['id'] ?? null)
            || !is_string($store['name'] ?? null)
            || !is_string($store['api_key'] ?? null)
            || !is_string($store['wallet_path'] ?? null)
        ) {
            throw new GreenfieldApiException('Stored store data is invalid.', 'find_store');
        }

        return [
            'id' => $store['id'],
            'name' => $store['name'],
            'api_key' => $store['api_key'],
            'wallet_path' => $store['wallet_path'],
        ];
    }

    /**
     * Creates a webhook or returns the existing registration for the same URL.
     *
     * @return array{id: string, url: string, secret: string}
     */
    public function findOrCreateWebhook(string $storeId, string $url): array
    {
        try {
            return $this->database->transactional(function (PDO $pdo) use ($storeId, $url): array {
                $statement = $pdo->prepare(
                    'SELECT id, url, secret FROM webhooks
                     WHERE store_id = ? AND url = ? LIMIT 1 FOR UPDATE'
                );
                $statement->execute([$storeId, $url]);
                $existing = $statement->fetch(PDO::FETCH_ASSOC);
                if (is_array($existing)) {
                    return $this->normalizeWebhook($existing);
                }

                $webhook = [
                    'id' => 'wh_' . bin2hex(random_bytes(16)),
                    'url' => $url,
                    'secret' => bin2hex(random_bytes(32)),
                ];
                $statement = $pdo->prepare(
                    'INSERT INTO webhooks (id, store_id, url, secret) VALUES (?, ?, ?, ?)'
                );
                $statement->execute([
                    $webhook['id'],
                    $storeId,
                    $webhook['url'],
                    $webhook['secret'],
                ]);

                return $webhook;
            });
        } catch (GreenfieldApiException $exception) {
            throw $exception;
        } catch (PDOException $exception) {
            throw new GreenfieldApiException(
                'Webhook could not be stored.',
                'create_webhook',
                500,
                $exception
            );
        } catch (Throwable $exception) {
            throw new GreenfieldApiException(
                'Webhook could not be stored.',
                'create_webhook',
                500,
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $webhook
     * @return array{id: string, url: string, secret: string}
     */
    private function normalizeWebhook(array $webhook): array
    {
        if (
            !is_string($webhook['id'] ?? null)
            || !is_string($webhook['url'] ?? null)
            || !is_string($webhook['secret'] ?? null)
            || $webhook['id'] === ''
            || $webhook['url'] === ''
            || $webhook['secret'] === ''
        ) {
            throw new GreenfieldApiException('Stored webhook data is invalid.', 'find_webhook');
        }

        return [
            'id' => $webhook['id'],
            'url' => $webhook['url'],
            'secret' => $webhook['secret'],
        ];
    }
}
