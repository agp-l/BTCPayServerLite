<?php

declare(strict_types=1);

use BtcPayLite\WebhookCronController;

require dirname(__DIR__) . '/vendor/autoload.php';

function webhookCronAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

$tests = [];

$tests['allows a CLI run without HTTP credentials'] = static function (): void {
    $calls = 0;
    $controller = new WebhookCronController(
        '0123456789abcdef0123456789abcdef',
        static function () use (&$calls): array {
            ++$calls;

            return ['deliveries_delivered' => 1];
        }
    );

    $response = $controller->handleServerRequest([], true);

    webhookCronAssertSame(200, $response['status_code'], 'CLI request was rejected.');
    webhookCronAssertSame(1, $calls, 'CLI runner was not called once.');
};

$tests['rejects web requests using the wrong method before running'] = static function (): void {
    $calls = 0;
    $controller = new WebhookCronController(
        '0123456789abcdef0123456789abcdef',
        static function () use (&$calls): array {
            ++$calls;

            return [];
        }
    );

    $response = $controller->handleServerRequest([
        'REQUEST_METHOD' => 'GET',
        'HTTP_AUTHORIZATION' => 'Bearer 0123456789abcdef0123456789abcdef',
    ], false);

    webhookCronAssertSame(405, $response['status_code'], 'GET request returned the wrong status.');
    webhookCronAssertSame(0, $calls, 'Rejected GET request invoked the runner.');
};

$tests['rejects a missing or malformed Bearer token'] = static function (): void {
    $controller = new WebhookCronController(
        '0123456789abcdef0123456789abcdef',
        static fn (): array => []
    );

    $missing = $controller->handleServerRequest(['REQUEST_METHOD' => 'POST'], false);
    $queryStyle = $controller->handleServerRequest([
        'REQUEST_METHOD' => 'POST',
        'QUERY_STRING' => 'key=0123456789abcdef0123456789abcdef',
    ], false);
    $wrongScheme = $controller->handleServerRequest([
        'REQUEST_METHOD' => 'POST',
        'HTTP_AUTHORIZATION' => 'Basic 0123456789abcdef0123456789abcdef',
    ], false);

    webhookCronAssertSame(401, $missing['status_code'], 'Missing token was accepted.');
    webhookCronAssertSame(401, $queryStyle['status_code'], 'Query-string key was accepted.');
    webhookCronAssertSame(401, $wrongScheme['status_code'], 'Non-Bearer token was accepted.');
};

$tests['accepts the redirected Apache Authorization header'] = static function (): void {
    $controller = new WebhookCronController(
        '0123456789abcdef0123456789abcdef',
        static fn (): array => ['ok' => true]
    );

    $response = $controller->handleServerRequest([
        'REQUEST_METHOD' => 'POST',
        'REDIRECT_HTTP_AUTHORIZATION' => 'bearer 0123456789abcdef0123456789abcdef',
    ], false);

    webhookCronAssertSame(200, $response['status_code'], 'Valid redirected Bearer token was rejected.');
};

$tests['does not expose an unexpected runner failure'] = static function (): void {
    $controller = new WebhookCronController(
        '0123456789abcdef0123456789abcdef',
        static function (): array {
            throw new RuntimeException('database password should stay private');
        }
    );

    $response = $controller->handleServerRequest([], true);

    webhookCronAssertSame(500, $response['status_code'], 'Runner failure returned the wrong status.');
    webhookCronAssertSame(
        ['message' => 'Webhook processing failed.'],
        $response['body'],
        'Runner failure details leaked to the response.'
    );
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

echo "{$passed} webhook cron controller tests passed.\n";
