<?php

declare(strict_types=1);

namespace BtcPayLite;

interface ClientDashboardRepository
{
    /** @return array{total_stores:int,total_invoices:int,paid_invoices:int} */
    public function fetchSummary(int $userId): array;

    /** @return list<array{id:string,name:string,api_key:string,wallet_path:string}> */
    public function fetchStores(int $userId): array;

    public function findAssignedWallet(int $userId): ?string;

    public function assignWallet(int $userId, string $walletPath, int $assignedAt): void;

    /** @return list<array{id:string,store_id:string,store_name:string,amount:string,status:string,created_at:int}> */
    public function fetchInvoices(int $userId, int $limit): array;

    /** @return list<array{id:string,store_id:string,store_name:string,url:string,secret:string,created_at:int}> */
    public function fetchWebhooks(int $userId): array;

    /** @return list<array<string,mixed>> */
    public function fetchPayouts(int $userId, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function fetchIntegrations(int $userId): array;

    /** @return list<array<string,mixed>> */
    public function fetchRequests(int $userId, int $limit): array;

    public function createStore(int $userId, string $id, string $name, string $apiKey, string $walletPath): void;

    public function ownsStore(int $userId, string $storeId): bool;

    /** @return array{id:string,url:string,secret:string} */
    public function findOrCreateWebhook(string $storeId, string $url, int $createdAt): array;

    public function deleteWebhook(int $userId, string $webhookId): bool;

    public function updateStoreName(int $userId, string $storeId, string $name): bool;

    public function rotateStoreApiKey(int $userId, string $storeId, string $apiKey): bool;

    public function deleteEmptyStore(int $userId, string $storeId): bool;

    public function updateWebhookUrl(int $userId, string $webhookId, string $url): bool;

    public function rotateWebhookSecret(int $userId, string $webhookId, string $secret): bool;
}
