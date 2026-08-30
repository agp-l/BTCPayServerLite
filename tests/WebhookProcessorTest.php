<?php

declare(strict_types=1);

use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\Database;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\WebhookDeliveryException;
use BtcPayLite\WebhookDeliveryRepository;
use BtcPayLite\WebhookProcessor;
use BtcPayLite\WebhookTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

final class WebhookProcessorTestDatabase extends Database
{
    public int $lockCalls = 0;

    public function __construct()
    {
    }

    public function withNamedLock(string $lockName, int $timeoutSeconds, callable $callback): mixed
    {
        ++$this->lockCalls;

        return $callback(null);
    }
}

final class WebhookProcessorTestWallet extends ElectrumWallet
{
    /** @var list<string> */
    public array $loadedWallets = [];

    public function __construct()
    {
    }

    public function loadWallet(string $walletPath, ?string $password = null): void
    {
        $this->loadedWallets[] = $walletPath;
    }
}

final class WebhookProcessorTestInvoiceManager extends BtcInvoiceManager
{
    /** @var array<string, mixed> */
    public array $result = ['status' => 'New'];
    public ?Throwable $failure = null;

    public function __construct()
    {
    }

    public function checkDatabasePaymentStatus(string $invoiceId): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}

final class WebhookProcessorTestRepository extends WebhookDeliveryRepository
{
    /** @var list<array{id: string, store_id: string, status: string, wallet_path: string}> */
    public array $activeInvoices = [];
    /** @var list<array{id: string, store_id: string, status: string, wallet_path: string}> */
    public array $terminalInvoices = [];
    /** @var list<array<string, mixed>> */
    public array $dueDeliveries = [];
    /** @var list<array<string, mixed>> */
    public array $ensureCalls = [];
    /** @var list<array<string, mixed>> */
    public array $delivered = [];
    /** @var list<array<string, mixed>> */
    public array $failed = [];

    public function __construct()
    {
    }

    public function findActiveInvoices(int $limit): array
    {
        return $this->activeInvoices;
    }

    public function findTerminalInvoicesMissingDeliveries(int $limit): array
    {
        return $this->terminalInvoices;
    }

    public function ensureDeliveries(
        string $invoiceId,
        string $storeId,
        string $eventType,
        int $timestamp
    ): int {
        $this->ensureCalls[] = [
            'invoice_id' => $invoiceId,
            'store_id' => $storeId,
            'event_type' => $eventType,
            'timestamp' => $timestamp,
        ];

        return 1;
    }

    public function claimDueDeliveries(int $now, int $limit, int $staleAfterSeconds = 300): array
    {
        return $this->dueDeliveries;
    }

    public function markDelivered(
        string $deliveryId,
        string $lockToken,
        int $deliveredAt,
        int $httpStatus,
        string $primaryIp
    ): void {
        $this->delivered[] = [
            'id' => $deliveryId,
            'lock_token' => $lockToken,
            'delivered_at' => $deliveredAt,
            'http_status' => $httpStatus,
            'primary_ip' => $primaryIp,
        ];
    }

    public function markFailed(
        string $deliveryId,
        string $lockToken,
        bool $retry,
        int $nextAttemptAt,
        ?int $httpStatus,
        string $error
    ): void {
        $this->failed[] = [
            'id' => $deliveryId,
            'lock_token' => $lockToken,
            'retry' => $retry,
            'next_attempt_at' => $nextAttemptAt,
            'http_status' => $httpStatus,
            'error' => $error,
        ];
    }
}

final class WebhookProcessorTestTransport implements WebhookTransport
{
    /** @var list<array{url: string, payload: string, signature: string}> */
    public array $calls = [];
    /** @var array{http_status: int, primary_ip: string} */
    public array $result = ['http_status' => 204, 'primary_ip' => '8.8.8.8'];
    public ?Throwable $failure = null;

    public function deliver(string $url, string $payload, string $signature): array
    {
        $this->calls[] = [
            'url' => $url,
            'payload' => $payload,
            'signature' => $signature,
        ];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}

function webhookProcessorAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

/**
 * @return array{
 *   0: WebhookProcessor,
 *   1: WebhookProcessorTestRepository,
 *   2: WebhookProcessorTestDatabase,
 *   3: WebhookProcessorTestWallet,
 *   4: WebhookProcessorTestInvoiceManager,
 *   5: WebhookProcessorTestTransport
 * }
 */
function newWebhookProcessorTestSystem(int $now = 1_700_000_000): array
{
    $repository = new WebhookProcessorTestRepository();
    $database = new WebhookProcessorTestDatabase();
    $wallet = new WebhookProcessorTestWallet();
    $manager = new WebhookProcessorTestInvoiceManager();
    $transport = new WebhookProcessorTestTransport();
    $processor = new WebhookProcessor(
        $database,
        $wallet,
        $manager,
        $repository,
        $transport,
        static fn (): int => $now
    );

    return [$processor, $repository, $database, $wallet, $manager, $transport];
}

/** @return array{id: string, store_id: string, status: string, wallet_path: string} */
function webhookProcessorInvoice(string $status): array
{
    return [
        'id' => 'inv_test',
        'store_id' => 'store_test',
        'status' => $status,
        'wallet_path' => '/wallets/test',
    ];
}

/** @return array<string, mixed> */
function webhookProcessorDelivery(int $attempts = 1): array
{
    return [
        'id' => 'wd_0123456789abcdef0123456789abcdef',
        'webhook_id' => 'wh_test',
        'invoice_id' => 'inv_test',
        'event_type' => 'InvoiceSettled',
        'payload' => '{"deliveryId":"wd_test","type":"InvoiceSettled"}',
        'attempts' => $attempts,
        'url' => 'https://example.test/webhook',
        'secret' => 'test-secret',
        'lock_token' => '0123456789abcdef0123456789abcdef',
    ];
}

$tests = [];

$tests['queues the event produced by an invoice transition'] = static function (): void {
    [$processor, $repository, $database, $wallet, $manager] = newWebhookProcessorTestSystem();
    $repository->activeInvoices = [webhookProcessorInvoice('New')];
    $manager->result = ['status' => 'Settled'];

    $report = $processor->run();

    webhookProcessorAssertSame(1, $report['invoices_scanned'], 'One invoice should be scanned.');
    webhookProcessorAssertSame(1, $report['invoice_transitions'], 'The transition should be counted.');
    webhookProcessorAssertSame(1, $report['deliveries_queued'], 'One delivery should be queued.');
    webhookProcessorAssertSame('InvoiceSettled', $repository->ensureCalls[0]['event_type'], 'Settled event expected.');
    webhookProcessorAssertSame(1, $database->lockCalls, 'Electrum access must use the named lock.');
    webhookProcessorAssertSame(['/wallets/test'], $wallet->loadedWallets, 'The invoice wallet must be selected.');
};

$tests['repairs a processing event before a failed Electrum check'] = static function (): void {
    [$processor, $repository, , , $manager] = newWebhookProcessorTestSystem();
    $repository->activeInvoices = [webhookProcessorInvoice('Processing')];
    $manager->failure = new RuntimeException('sensitive daemon failure');

    $report = $processor->run();

    webhookProcessorAssertSame(1, $report['deliveries_queued'], 'Existing Processing state should be queued first.');
    webhookProcessorAssertSame(1, $report['invoices_failed'], 'Electrum failure should be isolated to one invoice.');
    webhookProcessorAssertSame('InvoiceProcessing', $repository->ensureCalls[0]['event_type'], 'Processing event expected.');
    webhookProcessorAssertSame(
        'Unexpected webhook processing failure.',
        $report['errors'][0]['message'],
        'Unexpected exception details must not be exposed.'
    );
};

$tests['reconciles a missing terminal invoice event'] = static function (): void {
    [$processor, $repository] = newWebhookProcessorTestSystem();
    $repository->terminalInvoices = [webhookProcessorInvoice('Expired')];

    $report = $processor->run();

    webhookProcessorAssertSame(1, $report['deliveries_queued'], 'Missing terminal event should be queued.');
    webhookProcessorAssertSame('InvoiceExpired', $repository->ensureCalls[0]['event_type'], 'Expired event expected.');
};

$tests['signs and completes a claimed delivery'] = static function (): void {
    [$processor, $repository, , , , $transport] = newWebhookProcessorTestSystem();
    $delivery = webhookProcessorDelivery();
    $repository->dueDeliveries = [$delivery];

    $report = $processor->run();

    $expectedSignature = 'sha256=' . hash_hmac('sha256', $delivery['payload'], $delivery['secret']);
    webhookProcessorAssertSame($expectedSignature, $transport->calls[0]['signature'], 'Payload signature differs.');
    webhookProcessorAssertSame(1, $report['deliveries_delivered'], 'Delivery should be completed.');
    webhookProcessorAssertSame(204, $repository->delivered[0]['http_status'], 'HTTP status should be stored.');
};

$tests['schedules a retry for a temporary endpoint failure'] = static function (): void {
    [$processor, $repository, , , , $transport] = newWebhookProcessorTestSystem();
    $repository->dueDeliveries = [webhookProcessorDelivery(1)];
    $transport->failure = new WebhookDeliveryException(
        'Webhook endpoint returned HTTP 503.',
        'send_webhook',
        true,
        503
    );

    $report = $processor->run();

    webhookProcessorAssertSame(1, $report['retries_scheduled'], 'Temporary failure should be retried.');
    webhookProcessorAssertSame(true, $repository->failed[0]['retry'], 'Retry flag should be stored.');
    webhookProcessorAssertSame(1_700_000_060, $repository->failed[0]['next_attempt_at'], 'First retry delay should be 60 seconds.');
    webhookProcessorAssertSame(503, $repository->failed[0]['http_status'], 'HTTP failure should be stored.');
};

$tests['marks a permanent endpoint failure dead'] = static function (): void {
    [$processor, $repository, , , , $transport] = newWebhookProcessorTestSystem();
    $repository->dueDeliveries = [webhookProcessorDelivery(1)];
    $transport->failure = new WebhookDeliveryException(
        'Webhook endpoint returned HTTP 400.',
        'send_webhook',
        false,
        400
    );

    $report = $processor->run();

    webhookProcessorAssertSame(1, $report['deliveries_dead'], 'Permanent failure should become dead.');
    webhookProcessorAssertSame(false, $repository->failed[0]['retry'], 'Permanent failure must not retry.');
};

$tests['stops retrying after the maximum attempt count'] = static function (): void {
    [$processor, $repository, , , , $transport] = newWebhookProcessorTestSystem();
    $repository->dueDeliveries = [webhookProcessorDelivery(8)];
    $transport->failure = new WebhookDeliveryException(
        'Temporary transport failure.',
        'send_webhook',
        true
    );

    $report = $processor->run();

    webhookProcessorAssertSame(1, $report['deliveries_dead'], 'Eighth failed attempt should be final.');
    webhookProcessorAssertSame(false, $repository->failed[0]['retry'], 'Attempt limit must disable retries.');
};

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        ++$passed;
        echo "[PASS] {$name}\n";
    } catch (Throwable $throwable) {
        fwrite(STDERR, "[FAIL] {$name}: {$throwable->getMessage()}\n");
        exit(1);
    }
}

echo "{$passed} webhook processor tests passed.\n";
