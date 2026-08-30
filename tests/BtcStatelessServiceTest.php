<?php

declare(strict_types=1);

use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\BtcStatelessAjaxController;
use BtcPayLite\BtcStatelessService;
use BtcPayLite\BtcStatelessServiceException;
use BtcPayLite\ElectrumWallet;

require dirname(__DIR__) . '/vendor/autoload.php';

final class StatelessTestWallet extends ElectrumWallet
{
    /** @var list<string> */
    public array $loadedWalletPaths = [];

    public function __construct()
    {
    }

    public function loadWallet(string $walletPath, ?string $password = null): void
    {
        $this->loadedWalletPaths[] = $walletPath;
    }
}

final class StatelessTestInvoiceManager extends BtcInvoiceManager
{
    /** @var list<array{amount: int|float|string, description: string, custom_data: array, expiration: int}> */
    public array $createdInvoices = [];

    /** @var array<string, mixed> */
    public array $decodedInvoice = [];

    /** @var array<string, mixed> */
    public array $statusResult = [];

    public function __construct()
    {
    }

    public function createStatelessInvoice(
        int|float|string $amountBtc,
        string $description,
        array $customData = [],
        int $expirationMinutes = 15
    ): array {
        $this->createdInvoices[] = [
            'amount' => $amountBtc,
            'description' => $description,
            'custom_data' => $customData,
            'expiration' => $expirationMinutes,
        ];

        return ['token' => 'test-token', 'bip21_uri' => 'bitcoin:bc1qtest'];
    }

    public function decodeStatelessToken(string $token): array
    {
        return $this->decodedInvoice;
    }

    public function checkStatelessPaymentStatus(string $token): array
    {
        return $this->statusResult;
    }
}

final class StatelessControllerTestService extends BtcStatelessService
{
    /** @var array<string, mixed> */
    public array $statusResult = [];

    /** @var array<string, mixed> */
    public array $createResult = [];

    public function __construct()
    {
    }

    public function createInvoiceAsAdmin(array $input, string $walletName): array
    {
        return $this->createResult;
    }

    public function checkStatus(string $token): array
    {
        return $this->statusResult;
    }
}

function statelessAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function statelessAssertThrows(string $expectedClass, callable $callback, string $message): Throwable
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $expectedClass) {
            return $throwable;
        }

        throw new RuntimeException(
            $message . ' Unexpected exception: ' . $throwable::class,
            0,
            $throwable
        );
    }

    throw new RuntimeException($message . ' No exception was thrown.');
}

/** @return array{0: BtcStatelessService, 1: StatelessTestWallet, 2: StatelessTestInvoiceManager} */
function newStatelessTestService(string $walletDirectory): array
{
    $wallet = new StatelessTestWallet();
    $manager = new StatelessTestInvoiceManager();
    $config = [
        'wallet_path' => $walletDirectory . DIRECTORY_SEPARATOR . 'default_wallet',
        'api_clients' => ['api-key' => 'store_wallet'],
    ];

    return [new BtcStatelessService($config, $wallet, $manager), $wallet, $manager];
}

$walletDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'btcpaylite-stateless-' . bin2hex(random_bytes(6));
if (!mkdir($walletDirectory, 0700) && !is_dir($walletDirectory)) {
    throw new RuntimeException('Could not create the test wallet directory.');
}
touch($walletDirectory . DIRECTORY_SEPARATOR . 'default_wallet');
touch($walletDirectory . DIRECTORY_SEPARATOR . 'store_wallet');

register_shutdown_function(static function () use ($walletDirectory): void {
    @unlink($walletDirectory . DIRECTORY_SEPARATOR . 'default_wallet');
    @unlink($walletDirectory . DIRECTORY_SEPARATOR . 'store_wallet');
    @rmdir($walletDirectory);
});

$tests = [];

$tests['preserves one satoshi across the API service boundary'] = static function () use ($walletDirectory): void {
    [$service, $wallet, $manager] = newStatelessTestService($walletDirectory);

    $result = $service->createInvoiceFromApi([
        'amount' => '0,00000001',
        'description' => ' Order 42 ',
        'order_id' => ' 42 ',
        'expiration_minutes' => '5',
    ], 'api-key');

    statelessAssertSame('0.00000001', $result['amount'], 'The service changed a one-satoshi amount.');
    statelessAssertSame('0.00000001', $manager->createdInvoices[0]['amount'], 'The manager received an imprecise amount.');
    statelessAssertSame('Order 42', $manager->createdInvoices[0]['description'], 'The description was not normalized.');
    statelessAssertSame(10, $manager->createdInvoices[0]['expiration'], 'The minimum expiration was not applied.');
    statelessAssertSame(
        $walletDirectory . DIRECTORY_SEPARATOR . 'store_wallet',
        $wallet->loadedWalletPaths[0],
        'The API client wallet was not selected.'
    );
};

$tests['rejects sub-satoshi input before loading a wallet'] = static function () use ($walletDirectory): void {
    [$service, $wallet, $manager] = newStatelessTestService($walletDirectory);

    statelessAssertThrows(
        BtcStatelessServiceException::class,
        static fn () => $service->createInvoiceAsAdmin([
            'amount' => '0.000000001',
            'description' => 'Invalid amount',
        ], 'store_wallet'),
        'A sub-satoshi amount was accepted.'
    );

    statelessAssertSame([], $wallet->loadedWalletPaths, 'The wallet was loaded before input validation completed.');
    statelessAssertSame([], $manager->createdInvoices, 'The invoice manager was called for invalid input.');
};

$tests['rejects wallet path traversal'] = static function () use ($walletDirectory): void {
    [$service] = newStatelessTestService($walletDirectory);

    $exception = statelessAssertThrows(
        BtcStatelessServiceException::class,
        static fn () => $service->createInvoiceAsAdmin([
            'amount' => '0.001',
            'description' => 'Traversal',
        ], '../store_wallet'),
        'A wallet path traversal was accepted.'
    );

    statelessAssertSame(400, $exception->getCode(), 'Path traversal returned the wrong HTTP-oriented code.');
};

$tests['rejects an unknown API key'] = static function () use ($walletDirectory): void {
    [$service] = newStatelessTestService($walletDirectory);

    $exception = statelessAssertThrows(
        BtcStatelessServiceException::class,
        static fn () => $service->createInvoiceFromApi([], 'unknown'),
        'An unknown API key was accepted.'
    );

    statelessAssertSame(401, $exception->getCode(), 'Authentication failure returned the wrong code.');
};

$tests['loads the wallet embedded in a current token'] = static function () use ($walletDirectory): void {
    [$service, $wallet, $manager] = newStatelessTestService($walletDirectory);
    $manager->decodedInvoice = ['p' => ['wallet' => 'store_wallet']];
    $manager->statusResult = ['status' => 'unpaid'];

    $result = $service->checkStatus('current-token');

    statelessAssertSame(['status' => 'unpaid'], $result, 'The payment status result changed.');
    statelessAssertSame(
        $walletDirectory . DIRECTORY_SEPARATOR . 'store_wallet',
        $wallet->loadedWalletPaths[0],
        'The wallet embedded in the token was not loaded.'
    );
};

$tests['uses the configured wallet for a legacy token'] = static function () use ($walletDirectory): void {
    [$service, $wallet, $manager] = newStatelessTestService($walletDirectory);
    $manager->decodedInvoice = ['p' => []];
    $manager->statusResult = ['status' => 'unpaid'];

    $service->checkStatus('legacy-token');

    statelessAssertSame(
        $walletDirectory . DIRECTORY_SEPARATOR . 'default_wallet',
        $wallet->loadedWalletPaths[0],
        'A legacy token did not select the configured default wallet.'
    );
};

$tests['keeps exact amounts in the AJAX status response'] = static function (): void {
    $service = new StatelessControllerTestService();
    $service->statusResult = [
        'status' => 'underpaid',
        'invoice' => [
            'v' => '0.00000005',
            'd' => 'Small payment',
            'p' => ['order_id' => 'small-1', 'wallet' => 'store_wallet'],
            't' => 1_700_000_000,
        ],
        'payment' => [
            'received_total' => '0.00000003',
            'missing_amount' => '0.00000002',
        ],
    ];
    $controller = new BtcStatelessAjaxController($service, 'default_wallet', '/BTCPayLite/admin');

    $result = $controller->handleRequest([
        'api_action' => 'check_status',
        'token' => 'token+with/slash',
    ]);

    statelessAssertSame('0.00000005', $result['amount'], 'The AJAX controller changed the invoice amount.');
    statelessAssertSame('0.00000002', $result['missing_amount'], 'The AJAX controller recalculated the missing amount imprecisely.');
    statelessAssertSame(
        '/BTCPayLite/admin/url_pay.php?inv=token%2Bwith%2Fslash',
        $result['url'],
        'The invoice token was not URL-encoded.'
    );
};

$tests['rejects non-string AJAX boundary values'] = static function (): void {
    $service = new StatelessControllerTestService();
    $controller = new BtcStatelessAjaxController($service, 'default_wallet', '/BTCPayLite/admin');

    statelessAssertThrows(
        BtcStatelessServiceException::class,
        static fn () => $controller->handleRequest(['api_action' => ['create']]),
        'A non-string API action was accepted.'
    );
    statelessAssertThrows(
        BtcStatelessServiceException::class,
        static fn () => $controller->handleRequest(['api_action' => 'check_status', 'token' => []]),
        'A non-string token was accepted.'
    );
};

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, "[PASS] {$name}\n");
    } catch (Throwable $throwable) {
        fwrite(STDERR, "[FAIL] {$name}: {$throwable->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "{$passed} stateless service/controller tests passed.\n");
