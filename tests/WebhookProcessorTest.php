<?php

declare(strict_types=1);

use BtcPayLite\WebhookDeliveryException;
use BtcPayLite\WebhookDeliveryRepository;
use BtcPayLite\WebhookProcessor;
use BtcPayLite\WebhookTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

final class WebhookProcessorTestRepository extends WebhookDeliveryRepository
{
    /** @var list<array<string, mixed>> */
    public array $dueDeliveries = [];
    /** @var list<array<string, mixed>> */
    public array $delivered = [];
    /** @var list<array<string, mixed>> */
    public array $failed = [];

    public function __construct()
    {
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
 *   2: WebhookProcessorTestTransport
 * }
 */
function newWebhookProcessorTestSystem(int $now = 1_700_000_000): array
{
    $repository = new WebhookProcessorTestRepository();
    $transport = new WebhookProcessorTestTransport();
    $processor = new WebhookProcessor(
        $repository,
        $transport,
        static fn (): int => $now
    );

    return [$processor, $repository, $transport];
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

$tests['claims and delivers due outbox records without blockchain monitoring'] = static function (): void {
    [$processor, $repository, $transport] = newWebhookProcessorTestSystem();
    $delivery = webhookProcessorDelivery();
    $repository->dueDeliveries = [$delivery];

    $report = $processor->run();

    webhookProcessorAssertSame(1, $report['deliveries_claimed'], 'One delivery should be claimed.');
    webhookProcessorAssertSame(1, $report['deliveries_delivered'], 'Delivery should be completed.');
    webhookProcessorAssertSame(0, $report['invoices_scanned'], 'WebhookProcessor must not scan blockchain invoices.');
    webhookProcessorAssertSame(1, count($transport->calls), 'One delivery transport call expected.');
};

$tests['signs and completes a claimed delivery'] = static function (): void {
    [$processor, $repository, $transport] = newWebhookProcessorTestSystem();
    $delivery = webhookProcessorDelivery();
    $repository->dueDeliveries = [$delivery];

    $report = $processor->run();

    $expectedSignature = 'sha256=' . hash_hmac('sha256', $delivery['payload'], $delivery['secret']);
    webhookProcessorAssertSame($expectedSignature, $transport->calls[0]['signature'], 'Payload signature differs.');
    webhookProcessorAssertSame(1, $report['deliveries_delivered'], 'Delivery should be completed.');
    webhookProcessorAssertSame(204, $repository->delivered[0]['http_status'], 'HTTP status should be stored.');
};

$tests['schedules a retry for a temporary endpoint failure'] = static function (): void {
    [$processor, $repository, $transport] = newWebhookProcessorTestSystem();
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
    [$processor, $repository, $transport] = newWebhookProcessorTestSystem();
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
    [$processor, $repository, $transport] = newWebhookProcessorTestSystem();
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
