<?php

declare(strict_types=1);

use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\GreenfieldApiController;
use BtcPayLite\GreenfieldApiException;
use BtcPayLite\GreenfieldApiRepository;
use BtcPayLite\GreenfieldApiService;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

require __DIR__ . '/vendor/autoload.php';

$statusCode = 500;
$responseBody = ['message' => 'Internal server error.'];

try {
    $config = require __DIR__ . '/config.php';
    if (!is_array($config)) {
        throw new GreenfieldApiException('Application configuration is invalid.', 'configure_api');
    }

    $databasePort = $config['db_port'] ?? 3306;
    if (is_string($databasePort) && ctype_digit($databasePort)) {
        $databasePort = (int) $databasePort;
    }
    if (!is_int($databasePort)) {
        throw new GreenfieldApiException('Database port configuration is invalid.', 'configure_api');
    }

    $database = new Database(
        $config['db_host'] ?? '',
        $config['db_name'] ?? '',
        $config['db_user'] ?? '',
        $config['db_pass'] ?? '',
        $databasePort
    );
    $rpc = new ElectrumRPC(
        $config['rpc_host'] ?? '',
        $config['rpc_port'] ?? 0,
        $config['rpc_user'] ?? null,
        $config['rpc_pass'] ?? null
    );
    $wallet = new ElectrumWallet($rpc);
    $invoiceManager = new BtcInvoiceManager(
        $wallet,
        $config['secret_key'] ?? '',
        $database
    );
    $repository = new GreenfieldApiRepository($database);

    $configuredBaseUrl = $config['app_url'] ?? null;
    $checkoutBaseUrl = is_string($configuredBaseUrl) && trim($configuredBaseUrl) !== ''
        ? $configuredBaseUrl
        : (new UrlManager())->getBaseUrl();

    $service = new GreenfieldApiService(
        $repository,
        $database,
        $wallet,
        $invoiceManager,
        is_string($config['admin_api_key'] ?? null) ? $config['admin_api_key'] : '',
        $checkoutBaseUrl
    );
    $controller = new GreenfieldApiController($service);

    $inputStream = fopen('php://input', 'rb');
    if ($inputStream === false) {
        throw new GreenfieldApiException('Request body could not be read.', 'read_request', 400);
    }
    try {
        $rawBody = stream_get_contents($inputStream, 65_537);
    } finally {
        fclose($inputStream);
    }
    if (!is_string($rawBody)) {
        throw new GreenfieldApiException('Request body could not be read.', 'read_request', 400);
    }

    $response = $controller->handleServerRequest($_SERVER, $rawBody);
    $statusCode = $response['status_code'];
    $responseBody = $response['body'];
} catch (GreenfieldApiException $exception) {
    $statusCode = $exception->getHttpStatus();
    $responseBody = ['message' => $exception->getMessage()];

    if ($statusCode >= 500) {
        error_log(sprintf(
            'Greenfield API operation "%s" failed: %s',
            $exception->getOperation(),
            $exception->getMessage()
        ));
    }
} catch (Throwable $exception) {
    error_log('Unhandled Greenfield API failure: ' . $exception->getMessage());
}

if ($statusCode === 401) {
    header('WWW-Authenticate: Bearer');
} elseif ($statusCode === 503) {
    header('Retry-After: 2');
}

try {
    $json = json_encode(
        $responseBody,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    $statusCode = 500;
    $json = '{"message":"Internal server error."}';
    error_log('Greenfield API response encoding failed: ' . $exception->getMessage());
}

http_response_code($statusCode);
echo $json;
