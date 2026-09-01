<?php

declare(strict_types=1);

use BtcPayLite\AdminManagementRepository;
use BtcPayLite\AdminManagementService;

require_once __DIR__ . '/../vendor/autoload.php';

final class AdminManagementRepositoryFixture implements AdminManagementRepository
{
    public array $lastInvoiceFilter = [];
    public array $mutations = [];

    public function fetchClients(): array
    {
        return [['id' => 7, 'email' => 'client@example.test', 'status' => 'active']];
    }

    public function fetchSummary(?int $userId): array
    {
        return ['total_stores' => 2, 'total_invoices' => 4, 'settled_invoices' => 3, 'total_btc_volume' => '1.00000000'];
    }

    public function fetchInvoices(?int $userId, ?string $storeId, ?string $status, int $limit): array
    {
        $this->lastInvoiceFilter = compact('userId', 'storeId', 'status', 'limit');
        return [];
    }

    public function fetchStores(?int $userId): array
    {
        return [];
    }

    public function fetchWebhooks(?int $userId, ?string $storeId): array
    {
        return [];
    }

    public function updateStoreName(string $storeId, string $name): bool
    {
        $this->mutations[] = ['rename', $storeId, $name];
        return true;
    }

    public function rotateStoreApiKey(string $storeId, string $apiKey): bool
    {
        $this->mutations[] = ['rotate_store', $storeId, $apiKey];
        return true;
    }

    public function deleteEmptyStore(string $storeId): bool
    {
        $this->mutations[] = ['delete_store', $storeId];
        return true;
    }

    public function updateInvoiceStatus(string $invoiceId, string $status): bool
    {
        $this->mutations[] = ['invoice_status', $invoiceId, $status];
        return true;
    }

    public function updateWebhookUrl(string $webhookId, string $url): bool
    {
        $this->mutations[] = ['webhook_url', $webhookId, $url];
        return true;
    }

    public function rotateWebhookSecret(string $webhookId, string $secret): bool
    {
        $this->mutations[] = ['webhook_secret', $webhookId, $secret];
        return true;
    }
}

$repository = new AdminManagementRepositoryFixture();
$service = new AdminManagementService($repository);
$dashboard = $service->dashboard(7);
if ($dashboard['summary']['settlement_rate'] !== 75 || $repository->lastInvoiceFilter['limit'] !== 20) {
    throw new RuntimeException('Filtered dashboard summary is invalid.');
}
echo "[PASS] composes a customer-filtered dashboard\n";

$service->invoices(7, 'store_valid', 'Settled');
if ($repository->lastInvoiceFilter !== [
    'userId' => 7,
    'storeId' => 'store_valid',
    'status' => 'Settled',
    'limit' => 200,
]) {
    throw new RuntimeException('Invoice filters were not passed to the repository.');
}
echo "[PASS] validates and forwards invoice filters\n";

try {
    $service->invoices(null, null, 'Deleted');
    throw new RuntimeException('Unknown invoice status was accepted.');
} catch (\BtcPayLite\AdminOperationsException) {
    echo "[PASS] rejects unknown invoice states\n";
}

$service->renameStore('store_valid', ' Nový název ');
$service->rotateStoreApiKey('store_valid');
$service->changeInvoiceStatus('inv_valid', 'Expired');
if (
    $repository->mutations[0] !== ['rename', 'store_valid', 'Nový název']
    || $repository->mutations[1][0] !== 'rotate_store'
    || strlen($repository->mutations[1][2]) !== 64
    || $repository->mutations[2] !== ['invoice_status', 'inv_valid', 'Expired']
) {
    throw new RuntimeException('Validated admin mutations were not forwarded.');
}
echo "[PASS] validates reversible management mutations\n";

try {
    $service->changeInvoiceStatus('inv_valid', 'Settled');
    throw new RuntimeException('Manual settlement was accepted.');
} catch (\BtcPayLite\AdminOperationsException) {
    echo "[PASS] keeps settlement state under payment processing control\n";
}

echo "5 admin management service tests passed.\n";
