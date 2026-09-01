<?php

declare(strict_types=1);

use BtcPayLite\AdminInvoiceService;
use BtcPayLite\AdminOperationsException;
use BtcPayLite\AdminOperationsRepository;

require_once __DIR__ . '/../vendor/autoload.php';

final class AdminInvoiceRepositoryFixture implements AdminOperationsRepository
{
    public function fetchStores(): array { return []; }
    public function fetchDefaultStore(): ?array { return ['id' => 'store_test', 'wallet_path' => '/wallet']; }
    public function fetchStore(string $storeId): ?array
    {
        return $storeId === 'store_0123456789abcdef0123456789abcdef'
            ? ['id' => $storeId, 'wallet_path' => '/selected-wallet']
            : null;
    }
    public function createStore(string $id, string $name, string $apiKey, string $walletPath): void {}
    public function storeExists(string $storeId): bool { return true; }
    public function fetchWebhooks(): array { return []; }
    public function findOrCreateWebhook(string $storeId, string $url, int $createdAt): array { return []; }
    public function deleteWebhook(string $webhookId): bool { return false; }
}

$captured = null;
$service = new AdminInvoiceService(
    new AdminInvoiceRepositoryFixture(),
    static function (array $store, string $amount, array $metadata) use (&$captured): array {
        $captured = compact('store', 'amount', 'metadata');
        return [
            'id' => 'inv_0123456789abcdef0123456789abcdef',
            'amount' => $amount,
            'created_at' => 1788160000,
        ];
    }
);

$invoice = $service->create(
    '0.00000001',
    ' Konzultace ',
    ' ORD-1 ',
    'store_0123456789abcdef0123456789abcdef'
);
if (
    $invoice['amount'] !== '0.00000001'
    || $invoice['description'] !== 'Konzultace'
    || $captured['amount'] !== '0.00000001'
    || $captured['store']['wallet_path'] !== '/selected-wallet'
    || $captured['metadata']['orderId'] !== 'ORD-1'
) {
    throw new RuntimeException('Exact admin invoice data was not preserved.');
}
echo "[PASS] creates an exact normalized admin invoice for the selected store\n";

foreach (['1e-8', '0.000000001', '0'] as $invalidAmount) {
    try {
        $service->create($invalidAmount, 'Invoice', '');
        throw new RuntimeException('Invalid amount was accepted: ' . $invalidAmount);
    } catch (AdminOperationsException $exception) {
    }
}
echo "[PASS] rejects imprecise or non-positive admin invoice amounts\n";

try {
    $service->create('0.1', "Invalid\nDescription", '');
    throw new RuntimeException('Control characters were accepted.');
} catch (AdminOperationsException $exception) {
}
echo "[PASS] rejects unsafe invoice metadata\n";

try {
    $service->create('0.1', 'Invoice', '', 'store_ffffffffffffffffffffffffffffffff');
    throw new RuntimeException('Missing selected store was accepted.');
} catch (AdminOperationsException $exception) {
    if ($exception->getHttpStatus() !== 404) {
        throw new RuntimeException('Missing selected store did not retain its HTTP status.');
    }
}
echo "[PASS] rejects an unknown selected store\n";

echo "4 admin invoice service tests passed.\n";
