<?php

declare(strict_types=1);

use BtcPayLite\AdminOperationsException;
use BtcPayLite\AdminOperationsFactory;
use BtcPayLite\AdminOperationsService;
use BtcPayLite\AdminManagementService;
use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\PdoAdminManagementRepository;
use BtcPayLite\UrlManager;
use BtcPayLite\WebhookEndpointPolicy;

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
$filterStores = [];
$webhooks = [];
$clients = [];
$selectedUserId = null;
$selectedStoreId = is_string($_GET['store_id'] ?? null) && $_GET['store_id'] !== ''
    ? $_GET['store_id']
    : null;
$service = null;
$management = null;

$rawUserId = is_string($_GET['user_id'] ?? null) ? $_GET['user_id'] : '';
if ($rawUserId === '0' || ctype_digit($rawUserId)) {
    $selectedUserId = (int) $rawUserId;
}

try {
    $service = AdminOperationsFactory::fromConfig($config);
    $database = new Database(
        $config['db_host'],
        $config['db_name'],
        $config['db_user'],
        $config['db_pass'],
        (int) ($config['db_port'] ?? 3306)
    );
    $management = new AdminManagementService(
        new PdoAdminManagementRepository($database->getPdo()),
        new WebhookEndpointPolicy(null, ($config['allow_local_webhooks'] ?? false) === true)
    );
} catch (Throwable $exception) {
    error_log(sprintf('Admin webhooks initialization failed: %s (%s)', $exception->getMessage(), $exception::class));
    http_response_code(500);
    $pageError = 'Správa webhooků nyní není dostupná.';
}

if ($service instanceof AdminOperationsService
    && $management instanceof AdminManagementService
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
) {
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
        } elseif ($action === 'update') {
            $management->updateWebhook(
                is_string($_POST['webhook_id'] ?? null) ? $_POST['webhook_id'] : '',
                is_string($_POST['url'] ?? null) ? $_POST['url'] : ''
            );
            $toastMsg = 'Webhook URL byla ověřena a změněna.';
        } elseif ($action === 'rotate_secret') {
            $management->rotateWebhookSecret(
                is_string($_POST['webhook_id'] ?? null) ? $_POST['webhook_id'] : ''
            );
            $toastMsg = 'Podpisový secret byl nahrazen.';
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

if ($service instanceof AdminOperationsService && $management instanceof AdminManagementService) {
    try {
        $stores = $service->stores();
        $clients = $management->clients();
        $filterStores = $management->stores($selectedUserId);
        $webhooks = $management->webhooks($selectedUserId, $selectedStoreId);
    } catch (Throwable $exception) {
        error_log(sprintf('Admin webhooks data load failed: %s (%s)', $exception->getMessage(), $exception::class));
        http_response_code(500);
        $pageError = 'Data webhooků nyní nejsou dostupná.';
    }
}

require __DIR__ . '/views/webhooks_view.php';
