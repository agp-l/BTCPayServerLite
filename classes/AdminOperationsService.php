<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use Throwable;

final class AdminOperationsService
{
    private AdminOperationsRepository $repository;
    private StoreWalletProvisioner $walletProvisioner;
    private WebhookEndpointPolicy $webhookPolicy;
    private Closure $clock;

    public function __construct(
        AdminOperationsRepository $repository,
        StoreWalletProvisioner $walletProvisioner,
        WebhookEndpointPolicy $webhookPolicy,
        ?callable $clock = null
    ) {
        $this->repository = $repository;
        $this->walletProvisioner = $walletProvisioner;
        $this->webhookPolicy = $webhookPolicy;
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    /** @return list<array{id:string,name:string,api_key:string,wallet_path:string}> */
    public function stores(): array
    {
        return $this->repository->fetchStores();
    }

    /** @return list<array{id:string,store_id:string,store_name:string,url:string,secret:string,created_at:int}> */
    public function webhooks(): array
    {
        return $this->repository->fetchWebhooks();
    }

    /** @return array{id:string,wallet_path:string} */
    public function defaultStore(): array
    {
        $store = $this->repository->fetchDefaultStore();
        if ($store === null) {
            throw new AdminOperationsException('Create at least one store first.', 404);
        }

        return $store;
    }

    /** @return array{id:string,name:string,api_key:string,wallet_path:string} */
    public function createStore(string $name): array
    {
        $name = $this->storeName($name);

        $store = [
            'id' => 'store_' . bin2hex(random_bytes(16)),
            'name' => $name,
            'api_key' => bin2hex(random_bytes(32)),
            'wallet_path' => '',
        ];

        try {
            $store['wallet_path'] = $this->walletProvisioner->provision($store['id']);
            $this->repository->createStore(
                $store['id'],
                $store['name'],
                $store['api_key'],
                $store['wallet_path']
            );
        } catch (Throwable $exception) {
            if ($store['wallet_path'] !== '') {
                try {
                    $this->walletProvisioner->discard($store['wallet_path']);
                } catch (Throwable $cleanupException) {
                    error_log('Unused admin wallet cleanup failed: ' . $cleanupException::class);
                }
            }
            throw new AdminOperationsException(
                'Could not create store and its wallet at this time.',
                503,
                $exception
            );
        }

        return $store;
    }

    /** @return array{id:string,name:string,api_key:string,wallet_path:string} */
    public function createClientStore(int $userId, string $name): array
    {
        if ($userId < 1) {
            throw new AdminOperationsException('Selected client is invalid.');
        }
        $name = $this->storeName($name);
        $store = [
            'id' => 'store_' . bin2hex(random_bytes(16)),
            'name' => $name,
            'api_key' => bin2hex(random_bytes(32)),
            'wallet_path' => '',
        ];
        $provisionedWallet = null;

        try {
            $knownWallet = $this->repository->fetchClientWallet($userId);
            if ($knownWallet === null) {
                $provisionedWallet = $this->walletProvisioner->provision($store['id']);
            }
            $createdAt = ($this->clock)();
            if (!is_int($createdAt) || $createdAt < 1) {
                throw new AdminOperationsException('Application clock is not available.', 500);
            }
            $walletPath = $this->repository->createClientStore(
                $userId,
                $store['id'],
                $store['name'],
                $store['api_key'],
                $knownWallet ?? $provisionedWallet ?? '',
                $createdAt
            );
            if ($walletPath === null) {
                throw new AdminOperationsException('Active client was not found.', 404);
            }
            $store['wallet_path'] = $walletPath;
            if ($provisionedWallet !== null && $provisionedWallet !== $walletPath) {
                $this->walletProvisioner->discard($provisionedWallet);
                $provisionedWallet = null;
            }
        } catch (AdminOperationsException $exception) {
            $this->discardProvisionedWallet($provisionedWallet);
            throw $exception;
        } catch (Throwable $exception) {
            $this->discardProvisionedWallet($provisionedWallet);
            throw new AdminOperationsException(
                'Could not create client store at this time.',
                503,
                $exception
            );
        }

        return $store;
    }

    /** @return array{id:string,url:string,secret:string} */
    public function createWebhook(string $storeId, string $url): array
    {
        $storeId = $this->identifier($storeId, 'Store');
        if (!$this->repository->storeExists($storeId)) {
            throw new AdminOperationsException('Selected store was not found.', 404);
        }

        try {
            $endpoint = $this->webhookPolicy->inspect($url);
            $now = ($this->clock)();
            if (!is_int($now) || $now < 1) {
                throw new AdminOperationsException('Application clock is not available.', 500);
            }

            return $this->repository->findOrCreateWebhook($storeId, $endpoint['url'], $now);
        } catch (AdminOperationsException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AdminOperationsException(
                'Webhook URL is not secure or cannot be verified at this time.',
                400,
                $exception
            );
        }
    }

    public function deleteWebhook(string $webhookId): void
    {
        $webhookId = $this->identifier($webhookId, 'Webhook');
        if (!$this->repository->deleteWebhook($webhookId)) {
            throw new AdminOperationsException('Webhook was not found.', 404);
        }
    }

    private function identifier(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 50 || !preg_match('/\A[a-zA-Z0-9_-]+\z/D', $value)) {
            throw new AdminOperationsException($field . ' has an invalid identifier.');
        }

        return $value;
    }

    private function storeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new AdminOperationsException('Store name must contain 1 to 100 valid characters.');
        }
        return $name;
    }

    private function discardProvisionedWallet(?string $walletPath): void
    {
        if ($walletPath === null) {
            return;
        }
        try {
            $this->walletProvisioner->discard($walletPath);
        } catch (Throwable $exception) {
            error_log('Unused client wallet cleanup failed: ' . $exception::class);
        }
    }
}
