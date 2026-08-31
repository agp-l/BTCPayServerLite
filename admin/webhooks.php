<?php

declare(strict_types=1);

use BtcPayLite\AdminOperationsException;
use BtcPayLite\AdminOperationsFactory;
use BtcPayLite\AdminOperationsService;
use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';
$urlManager = new UrlManager(
    $_SERVER,
    is_string($config['app_url'] ?? null) ? $config['app_url'] : null
);
AuthManager::requireRole('admin', $urlManager->url('/login'));

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$csrfToken = AuthManager::csrfToken();
$toastMsg = '';
$pageError = null;
$stores = [];
$webhooks = [];
$service = null;

try {
    $service = AdminOperationsFactory::fromConfig($config);
} catch (Throwable $exception) {
    error_log(sprintf('Admin webhooks initialization failed: %s (%s)', $exception->getMessage(), $exception::class));
    http_response_code(500);
    $pageError = 'Správa webhooků nyní není dostupná.';
}

if ($service instanceof AdminOperationsService && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'create') {
            $storeId = is_string($_POST['store_id'] ?? null) ? $_POST['store_id'] : '';
            $url = is_string($_POST['url'] ?? null) ? $_POST['url'] : '';
            $service->createWebhook($storeId, $url);
            $toastMsg = 'Webhook byl bezpečně ověřen a uložen.';
        } elseif ($action === 'delete') {
            $webhookId = is_string($_POST['webhook_id'] ?? null) ? $_POST['webhook_id'] : '';
            $service->deleteWebhook($webhookId);
            $toastMsg = 'Webhook byl odstraněn.';
        } else {
            throw new AdminOperationsException('Neznámá operace správy webhooků.');
        }
    } catch (AuthException $exception) {
        http_response_code(403);
        $pageError = 'Platnost formuláře vypršela. Obnovte stránku a zkuste akci znovu.';
        error_log('Admin webhooks CSRF validation failed.');
    } catch (AdminOperationsException $exception) {
        http_response_code($exception->getHttpStatus());
        $pageError = $exception->getMessage();
        error_log('Admin webhook operation failed: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()));
    } catch (Throwable $exception) {
        http_response_code(500);
        $pageError = 'Operaci se nyní nepodařilo dokončit.';
        error_log(sprintf('Unexpected admin webhook failure: %s (%s)', $exception->getMessage(), $exception::class));
    }
}

if ($service instanceof AdminOperationsService) {
    try {
        $stores = $service->stores();
        $webhooks = $service->webhooks();
    } catch (Throwable $exception) {
        error_log(sprintf('Admin webhooks data load failed: %s (%s)', $exception->getMessage(), $exception::class));
        http_response_code(500);
        $pageError = 'Data webhooků nyní nejsou dostupná.';
    }
}

require __DIR__ . '/views/webhooks_view.php';
