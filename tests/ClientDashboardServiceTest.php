<?php

declare(strict_types=1);

use BtcPayLite\ClientDashboardRepository;
use BtcPayLite\ClientDashboardService;
use BtcPayLite\WebhookEndpointPolicy;

require_once __DIR__ . '/../vendor/autoload.php';

final class ClientRepositoryFixture implements ClientDashboardRepository
{
    public ?array $createdStore = null;
    public ?array $createdWebhook = null;
    public ?string $deletedWebhook = null;
    public bool $failStoreCreation = false;
    public ?string $assignedWallet = '/wallet';

    public function fetchSummary(int $userId): array { return ['total_stores' => 1, 'total_invoices' => 2, 'paid_invoices' => 1]; }
    public function fetchStores(int $userId): array { return [['id' => 'store_owned', 'name' => 'Store', 'api_key' => 'secret', 'wallet_path' => '/wallet']]; }
    public function findAssignedWallet(int $userId): ?string { return $this->assignedWallet; }
    public function assignWallet(int $userId, string $walletPath, int $assignedAt): void
    {
        $this->assignedWallet = $walletPath;
    }
    public function fetchInvoices(int $userId, int $limit): array
    {
        if ($limit !== 30) throw new RuntimeException('Unexpected invoice limit.');
        return [];
    }
    public function fetchWebhooks(int $userId): array { return []; }
    public function createStore(int $userId, string $id, string $name, string $apiKey, string $walletPath): void
    {
        if ($this->failStoreCreation) throw new RuntimeException('Simulated store persistence failure.');
        $this->createdStore = compact('userId', 'id', 'name', 'apiKey', 'walletPath');
    }
    public function ownsStore(int $userId, string $storeId): bool { return $userId === 7 && $storeId === 'store_owned'; }
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

$repository = new ClientRepositoryFixture();
$service = new ClientDashboardService(
    $repository,
    new WebhookEndpointPolicy(static fn (string $host): array => ['93.184.216.34']),
    static fn (): int => 1788160000
);

$data = $service->load(7);
if ($data['summary']['paid_invoices'] !== 1 || count($data['stores']) !== 1) {
    throw new RuntimeException('Client dashboard data was not composed.');
}
echo "[PASS] composes client dashboard data through the repository\n";

$store = $service->createStore(7, ' Profesionální obchod ');
if ($repository->createdStore === null || $store['name'] !== 'Profesionální obchod' || $store['wallet_path'] !== '/wallet' || strlen($store['api_key']) !== 64) {
    throw new RuntimeException('Store creation was not normalized.');
}
echo "[PASS] reuses the client's assigned wallet for a normalized store\n";

$repository->assignedWallet = null;
$repository->createdStore = null;
$adopted = $service->createStore(7, 'Historický účet');
if ($adopted['wallet_path'] !== '/wallet' || $repository->assignedWallet !== '/wallet') {
    throw new RuntimeException('A single legacy wallet was not safely adopted.');
}
echo "[PASS] adopts one unambiguous legacy wallet\n";

$repository->failStoreCreation = true;
try {
    $service->createStore(7, 'Selhávající obchod');
    throw new RuntimeException('Store persistence failure was accepted.');
} catch (\BtcPayLite\ClientDashboardException $exception) {
    if ($exception->getHttpStatus() !== 503) {
        throw new RuntimeException('Store persistence failure returned an unexpected status.');
    }
}
$repository->failStoreCreation = false;
echo "[PASS] reports store persistence failure without changing wallet ownership\n";

$webhook = $service->createWebhook(7, 'store_owned', 'https://example.com/hook');
if ($webhook['id'] !== 'wh_test' || $repository->createdWebhook === null || $repository->createdWebhook['createdAt'] !== 1788160000) {
    throw new RuntimeException('Webhook creation did not use the validated boundary.');
}
echo "[PASS] validates ownership and endpoint policy before storing a webhook\n";

$service->deleteWebhook(7, 'wh_test');
if ($repository->deletedWebhook !== 'wh_test') throw new RuntimeException('Webhook deletion was not scoped to the client.');
echo "[PASS] deletes a webhook through the scoped repository\n";

echo "6 client dashboard service tests passed.\n";
