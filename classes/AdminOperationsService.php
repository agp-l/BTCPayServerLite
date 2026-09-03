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
            throw new AdminOperationsException('Nejprve vytvořte alespoň jeden obchod.', 404);
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
                'Obchod a jeho peněženku se nyní nepodařilo vytvořit.',
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
            throw new AdminOperationsException('Vybraný klient není platný.');
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
                throw new AdminOperationsException('Čas aplikace není dostupný.', 500);
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
                throw new AdminOperationsException('Aktivní klient nebyl nalezen.', 404);
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
                'Klientský obchod se nyní nepodařilo vytvořit.',
                503,
                $exception
            );
        }

        return $store;
    }

    /** @return array{id:string,url:string,secret:string} */
    public function createWebhook(string $storeId, string $url): array
    {
        $storeId = $this->identifier($storeId, 'Obchod');
        if (!$this->repository->storeExists($storeId)) {
            throw new AdminOperationsException('Vybraný obchod nebyl nalezen.', 404);
        }

        try {
            $endpoint = $this->webhookPolicy->inspect($url);
            $now = ($this->clock)();
            if (!is_int($now) || $now < 1) {
                throw new AdminOperationsException('Čas aplikace není dostupný.', 500);
            }

            return $this->repository->findOrCreateWebhook($storeId, $endpoint['url'], $now);
        } catch (AdminOperationsException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AdminOperationsException(
                'Webhook URL není bezpečná nebo ji nyní nelze ověřit.',
                400,
                $exception
            );
        }
    }

    public function deleteWebhook(string $webhookId): void
    {
        $webhookId = $this->identifier($webhookId, 'Webhook');
        if (!$this->repository->deleteWebhook($webhookId)) {
            throw new AdminOperationsException('Webhook nebyl nalezen.', 404);
        }
    }

    private function identifier(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 50 || !preg_match('/\A[a-zA-Z0-9_-]+\z/D', $value)) {
            throw new AdminOperationsException($field . ' má neplatný identifikátor.');
        }

        return $value;
    }

    private function storeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new AdminOperationsException('Název obchodu musí obsahovat 1 až 100 platných znaků.');
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
