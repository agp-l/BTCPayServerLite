<?php

declare(strict_types=1);

use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\Database;
use BtcPayLite\DatabaseException;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\GreenfieldApiController;
use BtcPayLite\GreenfieldApiException;
use BtcPayLite\GreenfieldApiRepository;
use BtcPayLite\GreenfieldApiService;
use BtcPayLite\WebhookEndpointPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

final class GreenfieldTestRepository extends GreenfieldApiRepository
{
    /** @var array<string, array{id: string, name: string, api_key: string, wallet_path: string}> */
    public array $stores = [];

    /** @var list<array{store_id: string, url: string}> */
    public array $webhookCalls = [];

    public function __construct()
    {
    }

    public function findStore(string $storeId): ?array
    {
        return $this->stores[$storeId] ?? null;
    }

    public function findStoreByApiKey(string $apiKey): ?array
    {
        foreach ($this->stores as $store) {
            if ($store['api_key'] === $apiKey) {
                return $store;
            }
        }
        return null;
    }

    public function listWebhooks(string $storeId): array
    {
        return [];
    }

    public function findOrCreateWebhook(string $storeId, string $url, ?string $requestedSecret = null): array
    {
        $this->webhookCalls[] = ['store_id' => $storeId, 'url' => $url];

        return [
            'id' => 'wh_test',
            'url' => $url,
            'secret' => 'webhook-secret',
        ];
    }
}

final class GreenfieldTestDatabase extends Database
{
    public int $lockCalls = 0;
    public bool $busy = false;

    public function __construct()
    {
    }

    public function withNamedLock(string $lockName, int $timeoutSeconds, callable $callback): mixed
    {
        ++$this->lockCalls;
        if ($this->busy) {
            throw new DatabaseException('busy', 'acquire_lock', 503);
        }

        return $callback();
    }
}

final class GreenfieldTestWallet extends ElectrumWallet
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

final class GreenfieldTestInvoiceManager extends BtcInvoiceManager
{
    /** @var array<string, mixed> */
    public array $storedInvoice = [];

    /** @var list<array{store_id: string, amount: int|float|string, metadata: array, expiration: int}> */
    public array $createdInvoices = [];

    public function __construct()
    {
    }

    public function getDatabaseInvoice(string $invoiceId): array
    {
        return $this->storedInvoice;
    }

    public function createDatabaseInvoice(
        string $storeId,
        int|float|string $amountBtc,
        array $metadata = [],
        int $expirationMinutes = 15
    ): array {
        $this->createdInvoices[] = [
            'store_id' => $storeId,
            'amount' => $amountBtc,
            'metadata' => $metadata,
            'expiration' => $expirationMinutes,
        ];

        return [
            'id' => 'inv_test',
            'amount' => (string) $amountBtc,
            'status' => 'New',
            'created_at' => 1_700_000_000,
            'expires_at' => 1_700_000_600,
        ];
    }
}

final class GreenfieldControllerTestService extends GreenfieldApiService
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function getStore(string $storeId, string $apiKey): array
    {
        $this->calls[] = ['method' => 'getStore', 'store_id' => $storeId, 'api_key' => $apiKey];

        return ['id' => $storeId];
    }

    public function getInvoice(string $storeId, string $invoiceId, string $apiKey): array
    {
        $this->calls[] = [
            'method' => 'getInvoice',
            'store_id' => $storeId,
            'invoice_id' => $invoiceId,
            'api_key' => $apiKey,
        ];

        return ['id' => $invoiceId];
    }

    public function createInvoice(string $storeId, array $input, string $apiKey): array
    {
        $this->calls[] = [
            'method' => 'createInvoice',
            'store_id' => $storeId,
            'input' => $input,
            'api_key' => $apiKey,
        ];

        return ['id' => 'inv_test', 'amount' => $input['amount'] ?? null];
    }

    public function createWebhook(string $storeId, array $input, string $apiKey): array
    {
        $this->calls[] = [
            'method' => 'createWebhook',
            'store_id' => $storeId,
            'input' => $input,
            'api_key' => $apiKey,
        ];

        return ['id' => 'wh_test'];
    }
}

function greenfieldAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function greenfieldAssertThrows(string $expectedClass, callable $callback, string $message): Throwable
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

/**
 * @return array{0: GreenfieldApiService, 1: GreenfieldTestRepository, 2: GreenfieldTestDatabase, 3: GreenfieldTestWallet, 4: GreenfieldTestInvoiceManager}
 */
function newGreenfieldTestService(string $walletPath): array
{
    $repository = new GreenfieldTestRepository();
    $repository->stores['store_test'] = [
        'id' => 'store_test',
        'name' => 'Test Store',
        'api_key' => 'store-api-key',
        'wallet_path' => $walletPath,
    ];
    $database = new GreenfieldTestDatabase();
    $wallet = new GreenfieldTestWallet();
    $manager = new GreenfieldTestInvoiceManager();
    $service = new GreenfieldApiService(
        $repository,
        $database,
        $wallet,
        $manager,
        'admin-api-key',
        'http://localhost/BTCPayLite',
        new WebhookEndpointPolicy(static fn (): array => ['8.8.8.8'], true)
    );

    return [$service, $repository, $database, $wallet, $manager];
}

$walletDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'btcpaylite-greenfield-' . bin2hex(random_bytes(6));
if (!mkdir($walletDirectory, 0700) && !is_dir($walletDirectory)) {
    throw new RuntimeException('Could not create the test wallet directory.');
}
$walletPath = $walletDirectory . DIRECTORY_SEPARATOR . 'store_wallet';
if (!touch($walletPath)) {
    throw new RuntimeException('Could not create the test wallet file.');
}

register_shutdown_function(static function () use ($walletPath, $walletDirectory): void {
    @unlink($walletPath);
    @rmdir($walletDirectory);
});

$tests = [];

$tests['authenticates each store before exposing it'] = static function () use ($walletPath): void {
    [$service] = newGreenfieldTestService($walletPath);

    $store = $service->getStore('store_test', 'store-api-key');
    greenfieldAssertSame('store_test', $store['id'], 'The authenticated store changed.');

    $exception = greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $service->getStore('store_test', 'wrong-key'),
        'An invalid store API key was accepted.'
    );
    greenfieldAssertSame(401, $exception->getHttpStatus(), 'Invalid authentication returned the wrong status.');
};

$tests['allows the configured administrator key'] = static function () use ($walletPath): void {
    [$service] = newGreenfieldTestService($walletPath);

    $store = $service->getStore('store_test', 'admin-api-key');

    greenfieldAssertSame('store_test', $store['id'], 'The administrator could not access the store.');
};

$tests['rejects a checkout base URL containing credentials'] = static function () use ($walletPath): void {
    [, $repository, $database, $wallet, $manager] = newGreenfieldTestService($walletPath);

    $exception = greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => new GreenfieldApiService(
            $repository,
            $database,
            $wallet,
            $manager,
            'admin-api-key',
            'https://user:password@example.com'
        ),
        'A checkout URL containing credentials was accepted.'
    );

    greenfieldAssertSame(500, $exception->getHttpStatus(), 'Invalid server configuration was exposed as client input.');
};

$tests['keeps invoices scoped to their authenticated store'] = static function () use ($walletPath): void {
    [$service, , , , $manager] = newGreenfieldTestService($walletPath);
    $manager->storedInvoice = [
        'id' => 'inv_test',
        'store_id' => 'another_store',
        'amount' => '0.00000001',
        'status' => 'New',
        'metadata' => [],
        'created_at' => 1_700_000_000,
        'expires_at' => 1_700_000_900,
    ];

    $exception = greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $service->getInvoice('store_test', 'inv_test', 'store-api-key'),
        'An invoice from another store was exposed.'
    );

    greenfieldAssertSame(404, $exception->getHttpStatus(), 'Cross-store invoice access returned the wrong status.');
};

$tests['creates an exact invoice while holding the Electrum lock'] = static function () use ($walletPath): void {
    [$service, , $database, $wallet, $manager] = newGreenfieldTestService($walletPath);

    $invoice = $service->createInvoice('store_test', [
        'amount' => '0.00000001',
        'metadata' => ['orderId' => 'one-sat'],
        'expirationMinutes' => '10',
    ], 'store-api-key');

    greenfieldAssertSame('0.00000001', $invoice['amount'], 'The API response changed the exact amount.');
    greenfieldAssertSame('0.00000001', $manager->createdInvoices[0]['amount'], 'The manager received an imprecise amount.');
    greenfieldAssertSame(10, $manager->createdInvoices[0]['expiration'], 'The expiration changed.');
    greenfieldAssertSame(1, $database->lockCalls, 'Invoice creation did not acquire the shared lock.');
    greenfieldAssertSame(realpath($walletPath), $wallet->loadedWalletPaths[0], 'The wrong wallet was loaded.');
    greenfieldAssertSame(
        'http://localhost/BTCPayLite/pay?id=inv_test',
        $invoice['checkoutLink'],
        'The checkout URL is invalid.'
    );
};

$tests['rejects numeric JSON amounts at the API boundary'] = static function () use ($walletPath): void {
    [$service, , $database, , $manager] = newGreenfieldTestService($walletPath);

    $exception = greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $service->createInvoice('store_test', [
            'amount' => 0.00000001,
        ], 'store-api-key'),
        'A floating-point invoice amount was accepted.'
    );

    greenfieldAssertSame(400, $exception->getHttpStatus(), 'A numeric amount returned the wrong HTTP status.');
    greenfieldAssertSame(0, $database->lockCalls, 'Invalid input acquired the Electrum lock.');
    greenfieldAssertSame([], $manager->createdInvoices, 'Invalid input reached the invoice manager.');
};

$tests['maps a busy Electrum lock to retryable HTTP 503'] = static function () use ($walletPath): void {
    [$service, , $database] = newGreenfieldTestService($walletPath);
    $database->busy = true;

    $exception = greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $service->createInvoice('store_test', [
            'amount' => '0.001',
        ], 'store-api-key'),
        'A busy Electrum lock was not reported.'
    );

    greenfieldAssertSame(503, $exception->getHttpStatus(), 'A busy lock returned the wrong HTTP status.');
};

$tests['validates webhook destinations before storing them'] = static function () use ($walletPath): void {
    [$service, $repository] = newGreenfieldTestService($walletPath);

    greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $service->createWebhook(
            'store_test',
            ['url' => 'http://example.com/webhook'],
            'store-api-key'
        ),
        'An insecure remote webhook URL was accepted.'
    );

    $webhook = $service->createWebhook(
        'store_test',
        ['url' => 'http://localhost/test-webhook'],
        'store-api-key'
    );
    greenfieldAssertSame('wh_test', $webhook['id'], 'The webhook response changed.');
    greenfieldAssertSame(1, count($repository->webhookCalls), 'The valid webhook was not stored once.');
};

$tests['requires an explicit opt-in for localhost webhooks'] = static function () use ($walletPath): void {
    [, $repository, $database, $wallet, $manager] = newGreenfieldTestService($walletPath);
    $service = new GreenfieldApiService(
        $repository,
        $database,
        $wallet,
        $manager,
        'admin-api-key',
        'http://localhost/BTCPayLite'
    );

    greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $service->createWebhook(
            'store_test',
            ['url' => 'http://localhost/test-webhook'],
            'store-api-key'
        ),
        'A localhost webhook was accepted without an explicit opt-in.'
    );
    greenfieldAssertSame([], $repository->webhookCalls, 'Rejected localhost webhook reached storage.');
};

$tests['routes an exact JSON invoice request'] = static function (): void {
    $service = new GreenfieldControllerTestService();
    $controller = new GreenfieldApiController($service);

    $response = $controller->handleRequest(
        'POST',
        '/api/v1/stores/store_test/invoices',
        '{"amount":"0.00000001"}',
        'bearer store-api-key'
    );

    greenfieldAssertSame(200, $response['status_code'], 'The controller returned the wrong status.');
    greenfieldAssertSame('0.00000001', $response['body']['amount'], 'The controller changed the JSON amount.');
    greenfieldAssertSame('store-api-key', $service->calls[0]['api_key'], 'The Bearer token changed.');
};

$tests['adapts an Apache PATH_INFO request without its query string'] = static function (): void {
    $service = new GreenfieldControllerTestService();
    $controller = new GreenfieldApiController($service);

    $response = $controller->handleServerRequest([
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/BTCPayLite/api.php/api/v1/stores/store_test?ignored=1',
        'SCRIPT_NAME' => '/BTCPayLite/api.php',
        'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer store-api-key',
    ], '');

    greenfieldAssertSame(200, $response['status_code'], 'The Apache request returned the wrong status.');
    greenfieldAssertSame('getStore', $service->calls[0]['method'], 'The Apache path was routed incorrectly.');
    greenfieldAssertSame('store-api-key', $service->calls[0]['api_key'], 'The redirected Bearer token changed.');
};

$tests['rejects malformed JSON and wrong HTTP methods'] = static function (): void {
    $service = new GreenfieldControllerTestService();
    $controller = new GreenfieldApiController($service);

    $jsonException = greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $controller->handleRequest(
            'POST',
            '/api/v1/stores/store_test/invoices',
            '{invalid',
            'Bearer store-api-key'
        ),
        'Malformed API JSON was accepted.'
    );
    greenfieldAssertSame(400, $jsonException->getHttpStatus(), 'Malformed JSON returned the wrong status.');

    $methodException = greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $controller->handleRequest(
            'DELETE',
            '/api/v1/stores/store_test',
            '',
            'Bearer store-api-key'
        ),
        'An unsupported HTTP method was accepted.'
    );
    greenfieldAssertSame(405, $methodException->getHttpStatus(), 'Wrong method returned the wrong status.');
};

$tests['requires an explicit authorization scheme'] = static function (): void {
    $controller = new GreenfieldApiController(new GreenfieldControllerTestService());

    $exception = greenfieldAssertThrows(
        GreenfieldApiException::class,
        static fn () => $controller->handleRequest(
            'GET',
            '/api/v1/stores/store_test',
            '',
            'store-api-key'
        ),
        'A raw API key without Bearer was accepted.'
    );

    greenfieldAssertSame(401, $exception->getHttpStatus(), 'Missing Bearer returned the wrong status.');
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

fwrite(STDOUT, "{$passed} Greenfield API tests passed.\n");
