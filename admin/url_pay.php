<?php

declare(strict_types=1);

use BtcPayLite\BtcStatelessCheckoutController;
use BtcPayLite\BtcStatelessFactory;
use BtcPayLite\BtcStatelessServiceException;
use BtcPayLite\BtcInvoiceManagerException;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header(
    "Content-Security-Policy: default-src 'none'; "
    . "style-src 'self'; script-src 'self'; img-src 'self' data:; "
    . "connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
);

$requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null)
    ? strtoupper($_SERVER['REQUEST_METHOD'])
    : '';
if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$tokenValue = $_GET['token'] ?? ($_GET['inv'] ?? '');
$token = is_string($tokenValue) ? $tokenValue : '';
$actionValue = $_GET['action'] ?? '';
$action = is_string($actionValue) ? $actionValue : '';

$urlManager = new UrlManager(
    $_SERVER,
    is_string($config['app_url'] ?? null) ? $config['app_url'] : null
);
$assetBaseUrl = $urlManager->getBaseUrl();
$statusUrl = $assetBaseUrl . '/url-invoice?action=status&token=' . rawurlencode($token);

try {
    $factory = new BtcStatelessFactory($config);
    $controller = new BtcStatelessCheckoutController($factory->service());

    if ($action === 'status' || $action === 'check') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $controller->paymentStatus($token),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $checkout = $controller->paymentPage($token);
    require __DIR__ . '/views/url_pay_view.php';
} catch (BtcStatelessServiceException | BtcInvoiceManagerException $exception) {
    error_log(sprintf(
        'Stateless checkout %s failed: %s',
        $exception->getOperation(),
        $exception->getMessage()
    ));

    if ($action === 'status' || $action === 'check') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($exception->getCode() >= 400 && $exception->getCode() < 500 ? 400 : 503);
        echo json_encode(
            ['status' => 'error', 'message' => 'Invoice status is temporarily unavailable.'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    http_response_code(404);
    require __DIR__ . '/views/url_pay_error_view.php';
} catch (Throwable $exception) {
    error_log(sprintf(
        'Unexpected stateless checkout failure: %s (%s)',
        $exception->getMessage(),
        $exception::class
    ));

    if ($action === 'status' || $action === 'check') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode(
            ['status' => 'error', 'message' => 'Invoice status is temporarily unavailable.'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    http_response_code(404);
    require __DIR__ . '/views/url_pay_error_view.php';
}
