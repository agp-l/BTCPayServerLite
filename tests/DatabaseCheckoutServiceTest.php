<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BtcPayLite\CheckoutException;
use BtcPayLite\CheckoutRepository;
use BtcPayLite\DatabaseCheckoutController;
use BtcPayLite\DatabaseCheckoutService;

final class CheckoutTestRepository implements CheckoutRepository
{
    public int $calls = 0;
    public function __construct(public ?array $row) {}
    public function findInvoice(string $invoiceId): ?array { ++$this->calls; return $this->row; }
}
function checkoutSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) { throw new RuntimeException($message . ': ' . var_export($actual, true)); }
}
$row = ['id' => 'inv_checkout123', 'store_id' => 'store_1', 'btc_address' => 'bc1qcheckouttestaddress000000000000000000000',
    'amount' => '0.00000001', 'status' => 'New', 'metadata' => ['orderId' => '<ORDER-42>'],
    'created_at' => 2000, 'expires_at' => 2900, 'confirmed_balance_sats' => 0, 'mempool_delta_sats' => 0];
$repository = new CheckoutTestRepository($row);
$service = new DatabaseCheckoutService($repository, static fn (): int => 2600);
$model = $service->load(' inv_checkout123 ');
checkoutSame('0.00000001', $model['amount'], 'Exact amount');
checkoutSame('Objednávka <ORDER-42>', $model['title'], 'Title');
checkoutSame(300, $model['seconds_remaining'], 'Remaining time');
checkoutSame(1, $repository->calls, 'Only one repository read');
$past = new DatabaseCheckoutService($repository, static fn (): int => 4000);
checkoutSame('New', $past->load('inv_checkout123')['status'], 'Checkout must not invent an Expired transition');
$repository->row['status'] = 'Processing';
checkoutSame('Processing', $past->load('inv_checkout123')['status'], 'Processing must remain visible with zero current balance');
$repository->row['status'] = 'Settled';
checkoutSame('0.00000000', $past->load('inv_checkout123')['missing_amount'], 'Settled survives outgoing spend');
$repository->row = array_replace($row, ['amount' => '0.00000002', 'status' => 'Processing',
    'confirmed_balance_sats' => 1, 'payment_observed_at' => 2800]);
$partial = $past->load('inv_checkout123');
checkoutSame('PaidPartial', $partial['additional_status'], 'Persisted partial observation remains visible after expiry');
checkoutSame('0.00000001', $partial['missing_amount'], 'Observed current balance is projected exactly');
$repository->row = $row;
$before = $repository->calls;
try { $service->load('../config.php'); throw new RuntimeException('Unsafe ID accepted'); }
catch (CheckoutException $e) { checkoutSame(400, $e->getHttpStatus(), 'Invalid ID HTTP status'); }
checkoutSame($before, $repository->calls, 'Invalid ID must not read DB');
$repository->row = null;
try { $service->load('inv_missing'); throw new RuntimeException('Missing invoice accepted'); }
catch (CheckoutException $e) { checkoutSame(404, $e->getHttpStatus(), 'Missing invoice HTTP status'); }
$repository->row = $row;
$controller = new DatabaseCheckoutController($service);
$json = $controller->handle('GET', ['id' => $row['id'], 'action' => 'check']);
checkoutSame(200, $json['status_code'], 'GET checkout');
checkoutSame('New', $json['data']['status'], 'Persisted status');
checkoutSame(false, isset($json['data']['address']), 'Minimal polling response');
checkoutSame(405, $controller->handle('POST', ['id' => $row['id']])['status_code'], 'Read-only HTTP boundary');
echo "Checkout snapshot and HTTP contracts passed.\n";
