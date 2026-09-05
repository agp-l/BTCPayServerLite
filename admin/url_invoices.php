<?php

declare(strict_types=1);

use BtcPayLite\AuthManager;
use BtcPayLite\AuthException;
use BtcPayLite\BtcInvoiceManagerException;
use BtcPayLite\BtcStatelessAjaxController;
use BtcPayLite\BtcStatelessFactory;
use BtcPayLite\BtcStatelessServiceException;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

AuthManager::requireRole('admin', '../client/login');

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$urlManager = new UrlManager(
    $_SERVER,
    is_string($config['app_url'] ?? null) ? $config['app_url'] : null
);
$factory = new BtcStatelessFactory($config);
$defaultWalletName = $factory->defaultWalletName();
$paymentPageUrl = $urlManager->getBaseUrl() . '/url-invoice';

$requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null)
    ? strtoupper($_SERVER['REQUEST_METHOD'])
    : '';

if ($requestMethod === 'POST' && isset($_POST['api_action'])) {
    ob_start();

    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);

        $controller = new BtcStatelessAjaxController(
            $factory->service(),
            $defaultWalletName,
            $paymentPageUrl
        );
        $response = $controller->handleRequest(is_array($_POST) ? $_POST : []);

        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $response,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (AuthException $exception) {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['status' => 'error', 'message' => 'Bezpečnostní token formuláře není platný.'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (BtcStatelessServiceException | BtcInvoiceManagerException $exception) {
        ob_end_clean();
        $code = $exception->getCode();
        $httpCode = $code >= 400 && $code < 600 ? $code : 500;
        if ($httpCode >= 500) {
            error_log(sprintf(
                'Admin stateless invoice %s failed: %s',
                $exception->getOperation(),
                $exception->getMessage()
            ));
        }

        http_response_code($httpCode);
        if ($httpCode === 503) {
            header('Retry-After: 1');
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            [
                'status' => 'error',
                'message' => $httpCode >= 500
                    ? 'Požadavek nyní nelze dokončit.'
                    : $exception->getMessage(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $exception) {
        ob_end_clean();
        error_log(sprintf(
            'Unexpected admin stateless invoice failure: %s (%s)',
            $exception->getMessage(),
            $exception::class
        ));
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['status' => 'error', 'message' => 'Požadavek nyní nelze dokončit.'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
    exit;
}

if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}

$availableWallets = $factory->availableWallets();
if (!in_array($defaultWalletName, $availableWallets, true)) {
    array_unshift($availableWallets, $defaultWalletName);
}
$availableWallets = array_values(array_unique($availableWallets));
$defaultWallet = $defaultWalletName;
$csrfToken = AuthManager::csrfToken();

require __DIR__ . '/views/url_invoices_view.php';
