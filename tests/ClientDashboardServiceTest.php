<?php

declare(strict_types=1);

use BtcPayLite\ClientDashboardRepository;
use BtcPayLite\ClientDashboardService;
use BtcPayLite\StoreWalletProvisioner;
use BtcPayLite\WebhookEndpointPolicy;

require_once __DIR__ . '/../vendor/autoload.php';

final class ClientRepositoryFixture implements ClientDashboardRepository
{
    public ?array $createdStore = null;
    public ?array $createdWebhook = null;
    public ?string $deletedWebhook = null;

    public function fetchSummary(int $userId): array
    {
        return ['total_stores' => 1, 'total_invoices' => 2, 'paid_invoices' => 1];
    }

    public function fetchStores(int $userId): array
    {
        return [['id' => 'store_owned', 'name' => 'Store', 'api_key' => 'secret', 'wallet_path' => '/wallet']];
    }

    public function fetchInvoices(int $userId, int $limit): array
    {
        if ($limit !== 30) throw new RuntimeException('Unexpected invoice limit.');
        return [];
    }

    public function fetchWebhooks(int $userId): array
    {
        return [];
    }

    public function createStore(int $userId, string $id, string $name, string $apiKey, string $walletPath): void
    {
        $this->createdStore = compact('userId', 'id', 'name', 'apiKey', 'walletPath');
    }

    public function ownsStore(int $userId, string $storeId): bool
    {
        return $userId === 7 && $storeId === 'store_owned';
    }

    public function findOrCreateWebhook(string $storeId, string $url, int $createdAt): array
    {
        $this->createdWebhook = compact('storeId', 'url', 'createdAt');
        return ['id' => 'wh_test', 'url' => $url, 'secret' => 'webhook-secret'];
    }

    public function deleteWebhook(int $userId, string $webhookId): bool
    {
        $this->deletedWebhook = $webhookId;
        return $userId === 7 && $webhookId === 'wh_test';
    }
}

final class WalletProvisionerFixture implements StoreWalletProvisioner
{
    public function provision(string $storeId): string
    {
        return '/wallets/' . $storeId . '_wallet';
    }
}

$repository = new ClientRepositoryFixture();
$service = new ClientDashboardService(
    $repository,
    new WalletProvisionerFixture(),
    new WebhookEndpointPolicy(static fn (string $host): array => ['93.184.216.34']),
    static fn (): int => 1788160000
);

$data = $service->load(7);
if ($data['summary']['paid_invoices'] !== 1 || count($data['stores']) !== 1) {
    throw new RuntimeException('Client dashboard data was not composed.');
}
echo "[PASS] composes client dashboard data through the repository\n";

$store = $service->createStore(7, ' Profesionální obchod ');
if (
    $repository->createdStore === null
    || $store['name'] !== 'Profesionální obchod'
    || !str_starts_with($store['wallet_path'], '/wallets/store_')
    || strlen($store['api_key']) !== 64
) {
    throw new RuntimeException('Store creation was not normalized.');
}
echo "[PASS] provisions a wallet before persisting a normalized store\n";

$webhook = $service->createWebhook(7, 'store_owned', 'https://example.com/hook');
if (
    $webhook['id'] !== 'wh_test'
    || $repository->createdWebhook === null
    || $repository->createdWebhook['createdAt'] !== 1788160000
) {
    throw new RuntimeException('Webhook creation did not use the validated boundary.');
}
echo "[PASS] validates ownership and endpoint policy before storing a webhook\n";

$service->deleteWebhook(7, 'wh_test');
if ($repository->deletedWebhook !== 'wh_test') {
    throw new RuntimeException('Webhook deletion was not scoped to the client.');
}
echo "[PASS] deletes a webhook through the scoped repository\n";

echo "4 client dashboard service tests passed.\n";
