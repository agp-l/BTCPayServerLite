<?php

declare(strict_types=1);

use BtcPayLite\WebhookDeliveryException;
use BtcPayLite\WebhookEndpointPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

function webhookPolicyAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function webhookPolicyAssertThrows(callable $callback, string $message): WebhookDeliveryException
{
    try {
        $callback();
    } catch (WebhookDeliveryException $exception) {
        return $exception;
    }

    throw new RuntimeException($message . ' No exception was thrown.');
}

$tests = [];

$tests['accepts and resolves a public HTTPS endpoint'] = static function (): void {
    // 8.8.8.8 is used here because documentation-only ranges are reserved and
    // must correctly be rejected by the production policy.
    $policy = new WebhookEndpointPolicy(static fn (): array => ['8.8.8.8']);
    $endpoint = $policy->inspect('https://hooks.example.com/payment');

    webhookPolicyAssertSame('hooks.example.com', $endpoint['host'], 'The normalized host changed.');
    webhookPolicyAssertSame(443, $endpoint['port'], 'The default HTTPS port changed.');
    webhookPolicyAssertSame(['8.8.8.8'], $endpoint['addresses'], 'The resolved address changed.');
};

$tests['allows explicit localhost HTTP for development'] = static function (): void {
    $policy = new WebhookEndpointPolicy(static fn (): array => []);
    $endpoint = $policy->inspect('http://localhost/webhook');

    webhookPolicyAssertSame(['127.0.0.1'], $endpoint['addresses'], 'Localhost was not pinned to loopback.');
    webhookPolicyAssertSame(80, $endpoint['port'], 'The localhost port changed.');
};

$tests['rejects remote plaintext HTTP'] = static function (): void {
    $policy = new WebhookEndpointPolicy(static fn (): array => ['8.8.8.8']);

    $exception = webhookPolicyAssertThrows(
        static fn () => $policy->inspect('http://hooks.example.com/webhook'),
        'A remote plaintext webhook was accepted.'
    );

    webhookPolicyAssertSame(false, $exception->isRetryable(), 'Invalid HTTP policy was marked retryable.');
};

$tests['rejects credentials embedded in a webhook URL'] = static function (): void {
    $policy = new WebhookEndpointPolicy(static fn (): array => ['8.8.8.8']);

    webhookPolicyAssertThrows(
        static fn () => $policy->inspect('https://user:password@hooks.example.com/webhook'),
        'A webhook URL containing credentials was accepted.'
    );
};

$tests['rejects a hostname resolving to a private address'] = static function (): void {
    $policy = new WebhookEndpointPolicy(static fn (): array => ['10.10.0.2']);

    webhookPolicyAssertThrows(
        static fn () => $policy->inspect('https://hooks.example.com/webhook'),
        'A private webhook destination was accepted.'
    );
};

$tests['rejects mixed public and private DNS answers'] = static function (): void {
    $policy = new WebhookEndpointPolicy(static fn (): array => ['8.8.8.8', '127.0.0.1']);

    webhookPolicyAssertThrows(
        static fn () => $policy->inspect('https://hooks.example.com/webhook'),
        'A DNS answer containing loopback was accepted.'
    );
};

$tests['marks DNS resolution failure as retryable'] = static function (): void {
    $policy = new WebhookEndpointPolicy(static fn (): array => []);

    $exception = webhookPolicyAssertThrows(
        static fn () => $policy->inspect('https://missing.example.com/webhook'),
        'An unresolved webhook endpoint was accepted.'
    );

    webhookPolicyAssertSame(true, $exception->isRetryable(), 'DNS failure was not marked retryable.');
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

fwrite(STDOUT, "{$passed} webhook endpoint policy tests passed.\n");
