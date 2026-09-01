<?php

declare(strict_types=1);

use BtcPayLite\BtcStatelessApiController;
use BtcPayLite\BtcStatelessFactory;
use BtcPayLite\BtcStatelessServiceException;
use BtcPayLite\ApiRequestLogger;
use BtcPayLite\Database;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

$lockHandle = null;
$requestStartedAt = microtime(true);
$responseStatus = 500;

try {
    $requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null)
        ? $_SERVER['REQUEST_METHOD']
        : '';
    $rawBody = file_get_contents('php://input', false, null, 0, 65_537);
    if ($rawBody === false) {
        throw new BtcStatelessServiceException('Request body could not be read.', 'read_api_request', 400);
    }

    $authorizationHeader = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $serverKey) {
        if (is_string($_SERVER[$serverKey] ?? null) && trim($_SERVER[$serverKey]) !== '') {
            $authorizationHeader = $_SERVER[$serverKey];
            break;
        }
    }

    if ($authorizationHeader === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && strtolower($name) === 'authorization' && is_string($value)) {
                    $authorizationHeader = $value;
                    break;
                }
            }
        }
    }

    $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'btcpay_electrum_stateless.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new BtcStatelessServiceException(
            'Server is busy. Please retry shortly.',
            'acquire_invoice_lock',
            503
        );
    }

    $factory = new BtcStatelessFactory($config);

    $urlManager = new UrlManager(
        $_SERVER,
        is_string($config['app_url'] ?? null) ? $config['app_url'] : null
    );
    $controller = new BtcStatelessApiController(
        $factory->service(),
        $urlManager->getBaseUrl() . '/url-invoice'
    );
    $response = $controller->handleRequest(
        $requestMethod,
        $rawBody,
        is_array($_POST) ? $_POST : [],
        $authorizationHeader
    );

    $responseStatus = 200;
    http_response_code($responseStatus);
    echo json_encode(
        $response,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} catch (BtcStatelessServiceException $exception) {
    $code = $exception->getCode();
    $httpCode = $code >= 400 && $code < 600 ? $code : 500;
    $responseStatus = $httpCode;
    if ($httpCode === 405) {
        header('Allow: POST');
    } elseif ($httpCode === 401) {
        header('WWW-Authenticate: Bearer');
    } elseif ($httpCode === 503) {
        header('Retry-After: 1');
    }

    $message = $exception->getMessage();
    if ($httpCode === 500) {
        error_log(sprintf(
            'Stateless API %s failed: %s',
            $exception->getOperation(),
            $exception->getMessage()
        ));
        $message = 'Internal server error.';
    }

    http_response_code($httpCode);
    echo json_encode(
        ['status' => 'error', 'message' => $message],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $exception) {
    error_log('Unexpected stateless API failure: ' . $exception->getMessage());
    $responseStatus = 500;
    http_response_code($responseStatus);
    echo json_encode(
        ['status' => 'error', 'message' => 'Internal server error.'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    try {
        $database = new Database(
            $config['db_host'],
            $config['db_name'],
            $config['db_user'],
            $config['db_pass'],
            (int) ($config['db_port'] ?? 3306)
        );
        (new ApiRequestLogger($database))->record(
            $_SERVER,
            $responseStatus,
            (int) round((microtime(true) - $requestStartedAt) * 1000)
        );
    } catch (Throwable $exception) {
        error_log('Stateless API request metadata could not be recorded: ' . $exception::class);
    }
}
