<?php

declare(strict_types=1);

use BtcPayLite\DatabaseCheckoutController;
use BtcPayLite\DatabaseCheckoutFactory;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header(
    "Content-Security-Policy: default-src 'none'; "
    . "style-src 'self'; script-src 'self'; connect-src 'self'; "
    . "base-uri 'none'; frame-ancestors 'none'; form-action 'none'"
);
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null)
    ? strtoupper($_SERVER['REQUEST_METHOD'])
    : '';
$urlManager = isset($urlManager) && $urlManager instanceof UrlManager
    ? $urlManager
    : null;

try {
    $config = isset($config) && is_array($config)
        ? $config
        : require __DIR__ . '/../config.php';
    if (!is_array($config)) {
        throw new RuntimeException('Application configuration must be an array.');
    }

    if (!$urlManager instanceof UrlManager) {
        $urlManager = new UrlManager(
            $_SERVER,
            is_string($config['app_url'] ?? null) ? $config['app_url'] : null
        );
    }

    $response = (new DatabaseCheckoutController(
        DatabaseCheckoutFactory::fromConfig($config)
    ))->handle($requestMethod, $_GET);
} catch (Throwable $exception) {
    error_log(sprintf(
        'Database checkout failed: %s (%s)',
        $exception->getMessage(),
        $exception::class
    ));
    $response = [
        'status_code' => 500,
        'mode' => 'html',
        'data' => [],
        'error' => 'Platební stránku nyní nelze načíst.',
        'allowed_methods' => [],
    ];
}

$statusCode = is_int($response['status_code'] ?? null)
    ? $response['status_code']
    : 500;
http_response_code($statusCode);

$allowedMethods = is_array($response['allowed_methods'] ?? null)
    ? $response['allowed_methods']
    : [];
if ($statusCode === 405 && $allowedMethods !== []) {
    header('Allow: ' . implode(', ', $allowedMethods));
}

$mode = $response['mode'] ?? 'html';
if ($mode === 'json') {
    header('Content-Type: application/json; charset=utf-8');

    $payload = is_array($response['data'] ?? null)
        ? $response['data']
        : ['message' => 'Neplatná odpověď checkoutu.'];
    try {
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        http_response_code(500);
        echo '{"message":"Platební stav nyní nelze zobrazit."}';
    }
    return;
}

if ($requestMethod === 'HEAD') {
    return;
}

if (!$urlManager instanceof UrlManager) {
    $stylesheetUrl = '../assets/checkout.css';
    $homeUrl = '../';
} else {
    $stylesheetUrl = $urlManager->url('/assets/checkout.css');
    $homeUrl = $urlManager->url('/');
}

$error = $response['error'] ?? null;
if (is_string($error) && $error !== '') {
    $checkoutErrorStatus = $statusCode;
    $checkoutErrorMessage = $error;
    require __DIR__ . '/views/error_view.php';
    return;
}

$checkout = is_array($response['data'] ?? null) ? $response['data'] : [];
if (!$urlManager instanceof UrlManager) {
    $checkoutErrorStatus = 500;
    $checkoutErrorMessage = 'Platební stránku nyní nelze načíst.';
    require __DIR__ . '/views/error_view.php';
    return;
}

$statusUrl = $urlManager->url('/pay', [
    'id' => (string) ($checkout['id'] ?? ''),
    'action' => 'check',
]);
$scriptUrl = $urlManager->url('/assets/checkout.js');

require __DIR__ . '/views/pay_view.php';
