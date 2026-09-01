<?php

declare(strict_types=1);

use BtcPayLite\AdminManagementRepository;
use BtcPayLite\AdminManagementService;

require_once __DIR__ . '/../vendor/autoload.php';

final class AdminManagementRepositoryFixture implements AdminManagementRepository
{
    public array $lastInvoiceFilter = [];

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

echo "3 admin management service tests passed.\n";
