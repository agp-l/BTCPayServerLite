<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use Throwable;

final class ClientDashboardService
{
    private const INVOICE_LIMIT = 30;
    private const PAYOUT_LIMIT = 30;
    private const REQUEST_LIMIT = 50;

    private ClientDashboardRepository $repository;
    private WebhookEndpointPolicy $webhookPolicy;
    private Closure $clock;

    public function __construct(
        ClientDashboardRepository $repository,
        WebhookEndpointPolicy $webhookPolicy,
        ?callable $clock = null
    ) {
        $this->repository = $repository;
        $this->webhookPolicy = $webhookPolicy;
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    /**
     * @return array{
     *   summary:array{total_stores:int,total_invoices:int,paid_invoices:int},
     *   stores:list<array{id:string,name:string,api_key:string,wallet_path:string}>,
     *   invoices:list<array{id:string,store_id:string,store_name:string,amount:string,status:string,created_at:int}>,
     *   webhooks:list<array{id:string,store_id:string,store_name:string,url:string,secret:string,created_at:int}>,
     *   payouts:list<array<string,mixed>>,
     *   integrations:list<array<string,mixed>>,
     *   requests:list<array<string,mixed>>
     * }
     */
    public function load(int $userId): array
    {
        $userId = $this->userId($userId);

        return [
            'summary' => $this->repository->fetchSummary($userId),
            'stores' => $this->repository->fetchStores($userId),
            'invoices' => $this->repository->fetchInvoices($userId, self::INVOICE_LIMIT),
            'webhooks' => $this->repository->fetchWebhooks($userId),
            'payouts' => $this->repository->fetchPayouts($userId, self::PAYOUT_LIMIT),
            'integrations' => $this->repository->fetchIntegrations($userId),
            'requests' => $this->repository->fetchRequests($userId, self::REQUEST_LIMIT),
        ];
    }

    /** @return array{id:string,name:string,api_key:string,wallet_path:string} */
    public function createStore(int $userId, string $name): array
    {
        $userId = $this->userId($userId);
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new ClientDashboardException('Store name must contain 1 to 100 valid characters.');
        }

        $walletPath = $this->repository->findAssignedWallet($userId);
        if ($walletPath === null) {
            $walletPaths = [];
            foreach ($this->repository->fetchStores($userId) as $existingStore) {
                $walletPaths[$existingStore['wallet_path']] = true;
            }
            if (count($walletPaths) !== 1) {
                throw new ClientDashboardException(
                    $walletPaths === []
                        ? 'Account does not have an assigned wallet. Contact administrator.'
                        : 'Account has multiple historical wallets. Administrator must choose one.',
                    409
                );
            }
            $walletPath = (string) array_key_first($walletPaths);
            try {
                $assignedAt = ($this->clock)();
                if (!is_int($assignedAt) || $assignedAt < 1) {
                    throw new ClientDashboardException('Application clock is not available.', 500);
                }
                $this->repository->assignWallet($userId, $walletPath, $assignedAt);
            } catch (\Throwable $exception) {
                throw new ClientDashboardException(
                    'Wallet assignment could not be verified at this time.',
                    409,
                    $exception
                );
            }
        }

        $store = [
            'id' => 'store_' . bin2hex(random_bytes(16)),
            'name' => $name,
            'api_key' => bin2hex(random_bytes(32)),
            'wallet_path' => $walletPath,
        ];

        try {
            $this->repository->createStore(
                $userId,
                $store['id'],
                $store['name'],
                $store['api_key'],
                $store['wallet_path']
            );
        } catch (Throwable $exception) {
            throw new ClientDashboardException(
                'Could not create store at this time. Please try again later.',
                503,
                $exception
            );
        }

        return $store;
    }

    /** @return array{id:string,url:string,secret:string} */
    public function createWebhook(int $userId, string $storeId, string $url): array
    {
        $userId = $this->userId($userId);
        $storeId = $this->identifier($storeId, 'Store');
        if (!$this->repository->ownsStore($userId, $storeId)) {
            throw new ClientDashboardException('Selected store is not available.', 404);
        }

        try {
            $endpoint = $this->webhookPolicy->inspect($url);
            $now = ($this->clock)();
            if (!is_int($now) || $now < 1) {
                throw new ClientDashboardException('Application clock is not available.', 500);
            }

            return $this->repository->findOrCreateWebhook($storeId, $endpoint['url'], $now);
        } catch (ClientDashboardException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ClientDashboardException(
                'Webhook URL is not secure or cannot be verified at this time.',
                400,
                $exception
            );
        }
    }

    public function deleteWebhook(int $userId, string $webhookId): void
    {
        $userId = $this->userId($userId);
        $webhookId = $this->identifier($webhookId, 'Webhook');
        if (!$this->repository->deleteWebhook($userId, $webhookId)) {
            throw new ClientDashboardException('Webhook was not found.', 404);
        }
    }

    public function renameStore(int $userId, string $storeId, string $name): void
    {
        $userId = $this->userId($userId);
        $storeId = $this->identifier($storeId, 'Store');
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new ClientDashboardException('Store name must contain 1 to 100 valid characters.');
        }
        if (!$this->repository->updateStoreName($userId, $storeId, $name)) {
            throw new ClientDashboardException('Store was not found.', 404);
        }
    }

    public function rotateStoreApiKey(int $userId, string $storeId): void
    {
        $userId = $this->userId($userId);
        $storeId = $this->identifier($storeId, 'Store');
        if (!$this->repository->rotateStoreApiKey($userId, $storeId, bin2hex(random_bytes(32)))) {
            throw new ClientDashboardException('Store was not found.', 404);
        }
    }

    public function deleteStore(int $userId, string $storeId): void
    {
        $userId = $this->userId($userId);
        $storeId = $this->identifier($storeId, 'Store');
        if (!$this->repository->deleteEmptyStore($userId, $storeId)) {
            throw new ClientDashboardException(
                'Store cannot be deleted. Stores with invoices or payouts must remain in history.',
                409
            );
        }
    }

    public function updateWebhook(int $userId, string $webhookId, string $url): void
    {
        $userId = $this->userId($userId);
        $webhookId = $this->identifier($webhookId, 'Webhook');
        try {
            $endpoint = $this->webhookPolicy->inspect($url);
        } catch (Throwable $exception) {
            throw new ClientDashboardException(
                'Webhook URL is not secure or cannot be verified at this time.',
                400,
                $exception
            );
        }
        if (!$this->repository->updateWebhookUrl($userId, $webhookId, $endpoint['url'])) {
            throw new ClientDashboardException('Webhook was not found.', 404);
        }
    }

    public function rotateWebhookSecret(int $userId, string $webhookId): void
    {
        $userId = $this->userId($userId);
        $webhookId = $this->identifier($webhookId, 'Webhook');
        if (!$this->repository->rotateWebhookSecret(
            $userId,
            $webhookId,
            bin2hex(random_bytes(32))
        )) {
            throw new ClientDashboardException('Webhook was not found.', 404);
        }
    }

    private function userId(int $userId): int
    {
        if ($userId < 1) {
            throw new ClientDashboardException('Authenticated user is invalid.', 401);
        }

        return $userId;
    }

    private function identifier(string $value, string $field): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > 50
            || !preg_match('/\A[a-zA-Z0-9_-]+\z/D', $value)
        ) {
            throw new ClientDashboardException($field . ' has an invalid identifier.');
        }

        return $value;
    }
}
