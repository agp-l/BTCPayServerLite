<?php

declare(strict_types=1);

$GLOBALS['BTCPAY_LITE_TESTER_LIBRARY_ONLY'] = true;
require_once __DIR__ . '/../examples/btcpay_lite_api_tester.php';

$source = file_get_contents(__DIR__ . '/../examples/btcpay_lite_api_tester.php');
if (!is_string($source)) {
    throw new RuntimeException('Standalone API tester example is missing.');
}

$endpoints = [
    '/api/v1/health',
    '/api/v1/server/info',
    '/api/v1/api-keys/current',
    '/payment-methods',
    '/exchange/quotes',
    '/invoices',
    '/webhooks',
    '/payouts',
    "'/api'",
];
foreach ($endpoints as $endpoint) {
    if (!str_contains($source, $endpoint)) {
        throw new RuntimeException('API tester does not document endpoint ' . $endpoint);
    }
}

$securityChecks = [
    'protects the tester with HTTP Basic authentication' =>
        str_contains($source, 'WWW-Authenticate: Basic')
        && str_contains($source, 'testerBasicCredentials'),
    'protects browser mutations with CSRF' =>
        str_contains($source, "session_name('BTCPAYLITETESTER')")
        && str_contains($source, "hash_equals(\$_SESSION['csrf_token'], \$token)"),
    'uses a dedicated payout key and idempotency header' =>
        str_contains($source, '<PAYOUT_API_KEY>')
        && str_contains($source, 'Idempotency-Key'),
    'requires a live payout feature flag and confirmation phrase' =>
        str_contains($source, 'enable_live_payout_actions')
        && str_contains($source, 'SEND REAL BTC'),
    'verifies webhook HMAC over the raw request body' =>
        str_contains($source, "hash_hmac('sha256', \$rawBody, \$secret)")
        && str_contains($source, 'HTTP_BTCPAY_SIG'),
    'does not write webhook events into the public directory' =>
        str_contains($source, 'sys_get_temp_dir()')
        && !str_contains($source, "__DIR__ . '/webhook.log'"),
    'keeps TLS verification and redirects disabled' =>
        str_contains($source, 'CURLOPT_SSL_VERIFYPEER => true')
        && str_contains($source, 'CURLOPT_FOLLOWLOCATION => false'),
];
foreach ($securityChecks as $name => $passed) {
    if (!$passed) {
        throw new RuntimeException('Failed API tester boundary: ' . $name);
    }
}

$redacted = BtcPayLiteExampleClient::redact([
    'apiKey' => 'store-secret',
    'secret' => 'webhook-secret',
    'nested' => ['password' => 'wallet-password', 'token' => 'invoice-token'],
]);
if (($redacted['apiKey'] ?? null) !== '<redacted>'
    || ($redacted['secret'] ?? null) !== '<redacted>'
    || ($redacted['nested']['password'] ?? null) !== '<redacted>'
    || ($redacted['nested']['token'] ?? null) !== 'invoice-token') {
    throw new RuntimeException('API tester response redaction is invalid.');
}

$catalog = testerCatalog();
if (count($catalog['greenfield'] ?? []) !== 11
    || count($catalog['payouts_separate_key'] ?? []) !== 4
    || count($catalog['stateless'] ?? []) !== 1) {
    throw new RuntimeException('API tester catalog is incomplete.');
}

echo (count($securityChecks) + 3) . " standalone API tester tests passed.\n";
