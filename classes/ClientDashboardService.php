<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use Throwable;

final class ClientDashboardService
{
    private const INVOICE_LIMIT = 30;

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
     *   webhooks:list<array{id:string,store_id:string,store_name:string,url:string,secret:string,created_at:int}>
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
        ];
    }

    /** @return array{id:string,name:string,api_key:string,wallet_path:string} */
    public function createStore(int $userId, string $name): array
    {
        $userId = $this->userId($userId);
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new ClientDashboardException('Název obchodu musí obsahovat 1 až 100 platných znaků.');
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
                        ? 'Účet nemá přiřazenou peněženku. Kontaktujte administrátora.'
                        : 'Účet má více historických peněženek. Administrátor musí zvolit jednu.',
                    409
                );
            }
            $walletPath = (string) array_key_first($walletPaths);
            try {
                $assignedAt = ($this->clock)();
                if (!is_int($assignedAt) || $assignedAt < 1) {
                    throw new ClientDashboardException('Čas aplikace není dostupný.', 500);
                }
                $this->repository->assignWallet($userId, $walletPath, $assignedAt);
            } catch (\Throwable $exception) {
                throw new ClientDashboardException(
                    'Přiřazení peněženky se nyní nepodařilo ověřit.',
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
                'Obchod se nyní nepodařilo vytvořit. Zkuste to prosím později.',
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
        $storeId = $this->identifier($storeId, 'Obchod');
        if (!$this->repository->ownsStore($userId, $storeId)) {
            throw new ClientDashboardException('Vybraný obchod není dostupný.', 404);
        }

        try {
            $endpoint = $this->webhookPolicy->inspect($url);
            $now = ($this->clock)();
            if (!is_int($now) || $now < 1) {
                throw new ClientDashboardException('Čas aplikace není dostupný.', 500);
            }

            return $this->repository->findOrCreateWebhook($storeId, $endpoint['url'], $now);
        } catch (ClientDashboardException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ClientDashboardException(
                'Webhook URL není bezpečná nebo ji nyní nelze ověřit.',
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
            throw new ClientDashboardException('Webhook nebyl nalezen.', 404);
        }
    }

    private function userId(int $userId): int
    {
        if ($userId < 1) {
            throw new ClientDashboardException('Přihlášený uživatel není platný.', 401);
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
            throw new ClientDashboardException($field . ' má neplatný identifikátor.');
        }

        return $value;
    }
}
