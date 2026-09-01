<?php

declare(strict_types=1);

use BtcPayLite\AdminOperationsRepository;
use BtcPayLite\AdminOperationsService;
use BtcPayLite\StoreWalletProvisioner;
use BtcPayLite\WebhookEndpointPolicy;

require_once __DIR__ . '/../vendor/autoload.php';

final class AdminOperationsRepositoryFixture implements AdminOperationsRepository
{
    public ?array $createdStore = null;
    public ?array $createdWebhook = null;
    public ?string $deletedWebhook = null;
    public bool $failStoreCreation = false;
    public ?string $clientWallet = '/wallets/client';

    public function fetchStores(): array
    {
        return [['id' => 'store_owned', 'name' => 'Store', 'api_key' => 'secret', 'wallet_path' => '/wallet']];
    }

    public function fetchDefaultStore(): ?array
    {
        return ['id' => 'store_owned', 'wallet_path' => '/wallet'];
    }

    public function fetchStore(string $storeId): ?array
    {
        return $storeId === 'store_owned'
            ? ['id' => 'store_owned', 'wallet_path' => '/wallet']
            : null;
    }

    public function createStore(string $id, string $name, string $apiKey, string $walletPath): void
    {
        if ($this->failStoreCreation) {
            throw new RuntimeException('Simulated store persistence failure.');
        }
        $this->createdStore = compact('id', 'name', 'apiKey', 'walletPath');
    }

    public function fetchClientWallet(int $userId): ?string
    {
        return $userId === 7 ? $this->clientWallet : null;
    }

    public function createClientStore(int $userId, string $id, string $name, string $apiKey, string $proposedWalletPath, int $createdAt): ?string
    {
        if ($userId !== 7) return null;
        $this->createdStore = compact('id', 'name', 'apiKey', 'proposedWalletPath', 'userId', 'createdAt');
        return $proposedWalletPath;
    }

    public function storeExists(string $storeId): bool
    {
        return $storeId === 'store_owned';
    }

    public function fetchWebhooks(): array
    {
        return [];
    }

    public function findOrCreateWebhook(string $storeId, string $url, int $createdAt): array
    {
        $this->createdWebhook = compact('storeId', 'url', 'createdAt');
        return ['id' => 'wh_test', 'url' => $url, 'secret' => 'webhook-secret'];
    }

    public function deleteWebhook(string $webhookId): bool
    {
        $this->deletedWebhook = $webhookId;
        return $webhookId === 'wh_test';
    }
}

final class AdminWalletProvisionerFixture implements StoreWalletProvisioner
{
    public ?string $discarded = null;

    public function provision(string $storeId): string
    {
        return '/wallets/' . $storeId . '_wallet';
    }

    public function discard(string $walletPath): void
    {
        $this->discarded = $walletPath;
    }
}

$repository = new AdminOperationsRepositoryFixture();
$wallets = new AdminWalletProvisionerFixture();
$service = new AdminOperationsService(
    $repository,
    $wallets,
    new WebhookEndpointPolicy(static fn (string $host): array => ['93.184.216.34']),
    static fn (): int => 1788160000
);

if (count($service->stores()) !== 1 || $service->defaultStore()['id'] !== 'store_owned') {
    throw new RuntimeException('Admin store data was not composed.');
}
echo "[PASS] loads admin stores through the repository\n";

$store = $service->createStore(' Profesionální obchod ');
if (
    $repository->createdStore === null
    || $store['name'] !== 'Profesionální obchod'
    || !str_starts_with($store['wallet_path'], '/wallets/store_')
    || strlen($store['api_key']) !== 64
) {
    throw new RuntimeException('Admin store creation was not normalized.');
}
echo "[PASS] provisions a wallet before persisting an admin store\n";

$clientStore = $service->createClientStore(7, 'Klientský obchod');
if (
    $clientStore['wallet_path'] !== '/wallets/client'
    || $repository->createdStore['userId'] !== 7
    || $repository->createdStore['createdAt'] !== 1788160000
) {
    throw new RuntimeException('Client store did not reuse the canonical wallet.');
}
echo "[PASS] creates a client store with its canonical wallet\n";

$repository->failStoreCreation = true;
try {
    $service->createStore('Selhávající obchod');
    throw new RuntimeException('Admin store persistence failure was accepted.');
} catch (\BtcPayLite\AdminOperationsException $exception) {
    if (
        $exception->getHttpStatus() !== 503
        || $wallets->discarded === null
        || !str_contains($wallets->discarded, 'store_')
    ) {
        throw new RuntimeException('Unused admin wallet was not safely discarded.');
    }
}
$repository->failStoreCreation = false;
echo "[PASS] discards an unused admin wallet after persistence failure\n";

$webhook = $service->createWebhook('store_owned', 'https://example.com/hook');
if (
    $webhook['id'] !== 'wh_test'
    || $repository->createdWebhook === null
    || $repository->createdWebhook['createdAt'] !== 1788160000
) {
    throw new RuntimeException('Admin webhook creation did not use the validated boundary.');
}
echo "[PASS] validates store and endpoint before persisting an admin webhook\n";

$service->deleteWebhook('wh_test');
if ($repository->deletedWebhook !== 'wh_test') {
    throw new RuntimeException('Admin webhook was not deleted through the repository.');
}
echo "[PASS] deletes an admin webhook through the repository\n";

try {
    $service->createWebhook('store_missing', 'https://example.com/hook');
    throw new RuntimeException('Missing store was accepted.');
} catch (\BtcPayLite\AdminOperationsException $exception) {
    if ($exception->getHttpStatus() !== 404) {
        throw new RuntimeException('Missing store did not retain its HTTP status.');
    }
}
echo "[PASS] rejects a webhook for an unknown store\n";

echo "7 admin operations service tests passed.\n";
