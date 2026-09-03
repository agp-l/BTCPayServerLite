<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/BitcoinAmount.php';
require_once __DIR__ . '/../classes/BtcInvoiceManagerException.php';
require_once __DIR__ . '/../classes/CheckoutException.php';
require_once __DIR__ . '/../classes/CheckoutRepository.php';
require_once __DIR__ . '/../classes/DatabaseCheckoutService.php';
require_once __DIR__ . '/../classes/DatabaseCheckoutController.php';

use BtcPayLite\CheckoutException;
use BtcPayLite\CheckoutRepository;
use BtcPayLite\DatabaseCheckoutController;
use BtcPayLite\DatabaseCheckoutService;

final class CheckoutTestRepository implements CheckoutRepository
{
    /** @var array{id:string,store_id:string,wallet_path:string}|null */
    private ?array $row;
    public int $calls = 0;

    /** @param array{id:string,store_id:string,wallet_path:string}|null $row */
    public function __construct(?array $row)
    {
        $this->row = $row;
    }

    public function findInvoiceWallet(string $invoiceId): ?array
    {
        ++$this->calls;
        return $this->row;
    }
}

function checkoutSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

/** @return array<string,mixed> */
function checkoutStatus(string $status = 'New'): array
{
    return [
        'id' => 'inv_checkout123',
        'status' => $status,
        'additional_status' => 'None',
        'invoice' => [
            'id' => 'inv_checkout123',
            'btc_address' => 'bc1qcheckouttestaddress000000000000000000000',
            'amount' => '0.00000001',
            'metadata' => ['orderId' => '<ORDER-42>'],
            'created_at' => 2_000,
            'expires_at' => 2_900,
            'bip21_uri' => 'bitcoin:bc1qcheckouttestaddress000000000000000000000'
                . '?amount=0.00000001&message=Faktura%20inv_checkout123',
        ],
        'payment' => [
            'total_received' => '0.00000000',
            'missing_amount' => '0.00000001',
        ],
    ];
}

/** @var list<string> $passes */
$passes = [];
$repository = new CheckoutTestRepository([
    'id' => 'inv_checkout123',
    'store_id' => 'store_1',
    'wallet_path' => '/wallets/store_1',
]);
$service = new DatabaseCheckoutService(
    $repository,
    static function (string $invoiceId, string $walletPath): array {
        checkoutSame('inv_checkout123', $invoiceId, 'Loader invoice ID mismatch');
        checkoutSame('/wallets/store_1', $walletPath, 'Loader wallet path mismatch');
        return checkoutStatus();
    },
    static fn (): int => 2_600
);

$model = $service->load(' inv_checkout123 ');
checkoutSame('0.00000001', $model['amount'], 'Satoshi amount was not preserved');
checkoutSame('Order <ORDER-42>', $model['title'], 'Order title mismatch');
checkoutSame(300, $model['seconds_remaining'], 'Remaining time mismatch');
checkoutSame('store_1', $model['store_id'], 'Store ID mismatch');
$passes[] = 'builds an exact validated checkout view model';

$missing = new DatabaseCheckoutService(
    new CheckoutTestRepository(null),
    static fn (): array => []
);
try {
    $missing->load('inv_missing');
    throw new RuntimeException('Missing invoice was accepted');
} catch (CheckoutException $exception) {
    checkoutSame(404, $exception->getHttpStatus(), 'Missing invoice HTTP status mismatch');
}
$passes[] = 'returns a safe 404 for a missing invoice';

$invalidRepository = new CheckoutTestRepository(null);
$invalidService = new DatabaseCheckoutService(
    $invalidRepository,
    static fn (): array => []
);
try {
    $invalidService->load('../config.php');
    throw new RuntimeException('Unsafe invoice ID was accepted');
} catch (CheckoutException $exception) {
    checkoutSame(400, $exception->getHttpStatus(), 'Unsafe invoice HTTP status mismatch');
    checkoutSame(0, $invalidRepository->calls, 'Repository ran for unsafe invoice ID');
}
$passes[] = 'rejects an unsafe invoice ID before persistence';

$corrupt = new DatabaseCheckoutService(
    new CheckoutTestRepository([
        'id' => 'inv_checkout123',
        'store_id' => 'store_1',
        'wallet_path' => '/wallets/store_1',
    ]),
    static function (): array {
        $result = checkoutStatus('Hacked');
        return $result;
    }
);
try {
    $corrupt->load('inv_checkout123');
    throw new RuntimeException('Unknown invoice status was accepted');
} catch (CheckoutException $exception) {
    checkoutSame(500, $exception->getHttpStatus(), 'Corrupt status HTTP code mismatch');
}
$passes[] = 'rejects an unknown persisted payment status';

$unavailable = new DatabaseCheckoutService(
    new CheckoutTestRepository([
        'id' => 'inv_checkout123',
        'store_id' => 'store_1',
        'wallet_path' => '/wallets/store_1',
    ]),
    static function (): array {
        throw new RuntimeException('rpc password should not leave the boundary');
    }
);
try {
    $unavailable->load('inv_checkout123');
    throw new RuntimeException('RPC failure was accepted');
} catch (CheckoutException $exception) {
    checkoutSame(503, $exception->getHttpStatus(), 'RPC failure HTTP status mismatch');
    checkoutSame(
        false,
        str_contains($exception->getMessage(), 'password'),
        'Internal RPC error leaked'
    );
}
$passes[] = 'maps infrastructure failures to a safe retryable response';

$controller = new DatabaseCheckoutController($service);
$json = $controller->handle('GET', [
    'id' => 'inv_checkout123',
    'action' => 'check',
]);
checkoutSame(200, $json['status_code'], 'Status endpoint HTTP code mismatch');
checkoutSame('json', $json['mode'], 'Status endpoint mode mismatch');
checkoutSame('New', $json['data']['status'] ?? null, 'Status endpoint payload mismatch');
checkoutSame(false, isset($json['data']['address']), 'Status endpoint exposed static address');

$methodError = $controller->handle('POST', ['id' => 'inv_checkout123']);
checkoutSame(405, $methodError['status_code'], 'POST checkout HTTP code mismatch');
checkoutSame(['GET', 'HEAD'], $methodError['allowed_methods'], 'Checkout Allow mismatch');
$passes[] = 'keeps the checkout HTTP and JSON boundary minimal';

foreach ($passes as $pass) {
    echo '[PASS] ' . $pass . PHP_EOL;
}
echo count($passes) . ' database checkout tests passed.' . PHP_EOL;
