<?php

declare(strict_types=1);

use BtcPayLite\AdminDashboardRepository;
use BtcPayLite\AdminDashboardService;

require_once __DIR__ . '/../classes/AdminDashboardRepository.php';
require_once __DIR__ . '/../classes/AdminDashboardService.php';

final class FakeAdminDashboardRepository implements AdminDashboardRepository
{
    public int $requestedLimit = 0;

    public function fetchSummary(): array
    {
        return [
            'total_stores' => 3,
            'total_invoices' => 8,
            'settled_invoices' => 6,
            'total_btc_volume' => '1.25000000',
        ];
    }

    public function fetchRecentInvoices(int $limit): array
    {
        $this->requestedLimit = $limit;

        return [[
            'id' => 'inv_test',
            'store_id' => 'store_test',
            'store_name' => 'Test store',
            'amount' => '0.01000000',
            'status' => 'Settled',
            'created_at' => 1788160000,
        ]];
    }
}

$repository = new FakeAdminDashboardRepository();
$dashboard = (new AdminDashboardService($repository))->load();

if ($dashboard['summary']['settlement_rate'] !== 75) {
    throw new RuntimeException('Settlement rate was not calculated correctly.');
}
echo "[PASS] calculates the settlement rate without presentation logic\n";

if ($repository->requestedLimit !== 20 || count($dashboard['invoices']) !== 1) {
    throw new RuntimeException('Recent invoice loading contract changed.');
}
echo "[PASS] requests a bounded recent invoice list\n";

echo "2 admin dashboard service tests passed.\n";
