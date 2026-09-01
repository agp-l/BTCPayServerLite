<?php

declare(strict_types=1);

use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\ExchangeQuoteService;
use BtcPayLite\GreenfieldApiController;
use BtcPayLite\GreenfieldApiException;
use BtcPayLite\GreenfieldApiRepository;
use BtcPayLite\GreenfieldApiService;
use BtcPayLite\HttpBitcoinMarketDataProvider;
use BtcPayLite\PayoutRepository;
use BtcPayLite\PayoutService;
use BtcPayLite\UrlManager;
use BtcPayLite\WebhookEndpointPolicy;

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

    $exchangeFeeBps = $config['exchange_fee_bps'] ?? 0;
    if (is_string($exchangeFeeBps) && ctype_digit($exchangeFeeBps)) {
        $exchangeFeeBps = (int) $exchangeFeeBps;
    }
    if (!is_int($exchangeFeeBps)) {
        throw new GreenfieldApiException('Exchange fee configuration is invalid.', 'configure_api');
    }
    $payoutApiKeys = $config['payout_api_keys'] ?? [];
    $payoutWalletPasswords = $config['payout_wallet_passwords'] ?? [];
    $payoutMaxBtc = $config['payout_max_btc'] ?? '0.01000000';
    $payoutDailyLimitBtc = $config['payout_daily_limit_btc'] ?? '0.05000000';
    if (!is_array($payoutApiKeys)
        || !is_array($payoutWalletPasswords)
        || !is_string($payoutMaxBtc)
        || !is_string($payoutDailyLimitBtc)
    ) {
        throw new GreenfieldApiException('Payout configuration is invalid.', 'configure_api');
    }

    $marketData = new HttpBitcoinMarketDataProvider();
    $exchangeQuotes = new ExchangeQuoteService($marketData, $exchangeFeeBps);
    $payoutService = new PayoutService(
        $repository,
        new PayoutRepository($database),
        $database,
        $wallet,
        $exchangeQuotes,
        $payoutApiKeys,
        $payoutWalletPasswords,
        $payoutMaxBtc,
        $payoutDailyLimitBtc,
        ($config['payout_api_enabled'] ?? false) === true
    );

    $service = new GreenfieldApiService(
        $repository,
        $database,
        $wallet,
        $invoiceManager,
        is_string($config['admin_api_key'] ?? null) ? $config['admin_api_key'] : '',
        $checkoutBaseUrl,
        new WebhookEndpointPolicy(
            null,
            ($config['allow_local_webhooks'] ?? false) === true
        ),
        $marketData,
        $exchangeQuotes,
        $payoutService
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

    $requestServer = $_SERVER;
    if (
        (
            (!isset($requestServer['HTTP_AUTHORIZATION'])
                && !isset($requestServer['REDIRECT_HTTP_AUTHORIZATION']))
            || !isset($requestServer['HTTP_IDEMPOTENCY_KEY'])
        )
        && function_exists('getallheaders')
    ) {
        $requestHeaders = getallheaders();
        if (is_array($requestHeaders)) {
            foreach ($requestHeaders as $headerName => $headerValue) {
                if (!is_string($headerName) || !is_string($headerValue)) {
                    continue;
                }
                if (strcasecmp($headerName, 'Authorization') === 0) {
                    $requestServer['HTTP_AUTHORIZATION'] = $headerValue;
                } elseif (strcasecmp($headerName, 'Idempotency-Key') === 0) {
                    $requestServer['HTTP_IDEMPOTENCY_KEY'] = $headerValue;
                }
            }
        }
    }

    $response = $controller->handleServerRequest($requestServer, $rawBody);
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
    header('WWW-Authenticate: token');
    header('WWW-Authenticate: Bearer', false);
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
