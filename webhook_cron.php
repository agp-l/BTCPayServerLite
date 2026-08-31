<?php

declare(strict_types=1);

use BtcPayLite\WebhookCronApplication;
use BtcPayLite\WebhookCronController;
use BtcPayLite\WebhookDeliveryException;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';
$statusCode = 500;
$responseBody = ['message' => 'Webhook processing failed.'];

try {
    $config = require __DIR__ . '/config.php';
    if (!is_array($config)) {
        throw new WebhookDeliveryException(
            'Webhook cron configuration is invalid.',
            'configure_cron'
        );
    }

    $requestServer = $_SERVER;
    if (
        !$isCli
        && !isset($requestServer['HTTP_AUTHORIZATION'])
        && !isset($requestServer['REDIRECT_HTTP_AUTHORIZATION'])
        && function_exists('getallheaders')
    ) {
        $requestHeaders = getallheaders();
        if (is_array($requestHeaders)) {
            foreach ($requestHeaders as $name => $value) {
                if (
                    is_string($name)
                    && strcasecmp($name, 'Authorization') === 0
                    && is_string($value)
                ) {
                    $requestServer['HTTP_AUTHORIZATION'] = $value;
                    break;
                }
            }
        }
    }

    $application = new WebhookCronApplication($config);
    $controller = new WebhookCronController(
        $application->getCronKey(),
        [$application, 'run']
    );
    $response = $controller->handleServerRequest($requestServer, $isCli);
    $statusCode = $response['status_code'];
    $responseBody = $response['body'];
} catch (Throwable $exception) {
    $logMessage = $exception instanceof WebhookDeliveryException
        ? $exception->getMessage()
        : 'Unexpected ' . get_class($exception);
    error_log('Webhook cron bootstrap failed: ' . $logMessage);
}

if (!$isCli) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Vary: Authorization');
    if ($statusCode === 401) {
        header('WWW-Authenticate: Bearer');
    } elseif ($statusCode === 405) {
        header('Allow: POST');
    }
}

try {
    $json = json_encode(
        $responseBody,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    );
} catch (JsonException $exception) {
    error_log('Webhook cron response encoding failed.');
    $statusCode = 500;
    $json = '{"message":"Webhook processing failed."}';
    if (!$isCli) {
        http_response_code($statusCode);
    }
}

echo $json . PHP_EOL;
exit($statusCode === 200 ? 0 : 1);
