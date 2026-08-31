<?php

declare(strict_types=1);

namespace BtcPayLite;

interface AdminOperationsRepository
{
    /** @return list<array{id:string,name:string,api_key:string,wallet_path:string}> */
    public function fetchStores(): array;

    /** @return array{id:string,wallet_path:string}|null */
    public function fetchDefaultStore(): ?array;

    public function createStore(string $id, string $name, string $apiKey, string $walletPath): void;

    public function storeExists(string $storeId): bool;

    /** @return list<array{id:string,store_id:string,store_name:string,url:string,secret:string,created_at:int}> */
    public function fetchWebhooks(): array;

    /** @return array{id:string,url:string,secret:string} */
    public function findOrCreateWebhook(string $storeId, string $url, int $createdAt): array;

    public function deleteWebhook(string $webhookId): bool;
}
