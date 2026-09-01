<?php

declare(strict_types=1);

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\ClientDashboardException;
use BtcPayLite\ClientDashboardService;
use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\PdoClientDashboardRepository;
use BtcPayLite\UrlManager;
use BtcPayLite\WebhookEndpointPolicy;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
$urlManager = isset($urlManager) && $urlManager instanceof UrlManager
    ? $urlManager
    : new UrlManager(
        $_SERVER,
        is_string($config['app_url'] ?? null) ? $config['app_url'] : null
    );

AuthManager::requireRole('client', $urlManager->url('/login'));

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$csrfToken = AuthManager::csrfToken();
$sessionUserId = $_SESSION['user_id'] ?? null;
if (is_int($sessionUserId)) {
    $userId = $sessionUserId;
} elseif (is_string($sessionUserId) && ctype_digit($sessionUserId)) {
    $userId = (int) $sessionUserId;
} else {
    $userId = 0;
}

$clientStats = ['total_stores' => 0, 'total_invoices' => 0, 'paid_invoices' => 0];
$stores = [];
$invoices = [];
$webhooks = [];
$payouts = [];
$integrations = [];
$requests = [];
$walletBalance = null;
$walletError = null;
$toastMsg = '';
$pageError = null;
$service = null;
$repository = null;

try {
    $database = new Database(
        $config['db_host'],
        $config['db_name'],
        $config['db_user'],
        $config['db_pass'],
        (int) ($config['db_port'] ?? 3306)
    );
    $repository = new PdoClientDashboardRepository($database);
    $service = new ClientDashboardService(
        $repository,
        new WebhookEndpointPolicy(
            null,
            ($config['allow_local_webhooks'] ?? false) === true
        )
    );
} catch (Throwable $exception) {
    error_log(sprintf(
        'Client dashboard initialization failed: %s (%s)',
        $exception->getMessage(),
        $exception::class
    ));
    http_response_code(500);
    $pageError = 'Klientský panel nyní není dostupný. Zkuste to prosím později.';
}

if ($service instanceof ClientDashboardService && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        if ($action === 'create_store') {
            $name = is_string($_POST['store_name'] ?? null) ? $_POST['store_name'] : '';
            $service->createStore($userId, $name);
            $toastMsg = 'Obchod byl vytvořen se sdílenou peněženkou účtu.';
        } elseif ($action === 'create_webhook') {
            $storeId = is_string($_POST['store_id'] ?? null) ? $_POST['store_id'] : '';
            $url = is_string($_POST['url'] ?? null) ? $_POST['url'] : '';
            $service->createWebhook($userId, $storeId, $url);
            $toastMsg = 'Webhook byl bezpečně ověřen a uložen.';
        } elseif ($action === 'delete_webhook') {
            $webhookId = is_string($_POST['webhook_id'] ?? null) ? $_POST['webhook_id'] : '';
            $service->deleteWebhook($userId, $webhookId);
            $toastMsg = 'Webhook byl odstraněn.';
        } else {
            throw new ClientDashboardException('Neznámá operace klientského panelu.');
        }
    } catch (AuthException $exception) {
        http_response_code(403);
        $pageError = 'Platnost formuláře vypršela. Obnovte stránku a zkuste akci znovu.';
        error_log('Client dashboard CSRF validation failed.');
    } catch (ClientDashboardException $exception) {
        http_response_code($exception->getHttpStatus());
        $pageError = $exception->getMessage();
        error_log('Client dashboard operation failed: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()));
    } catch (Throwable $exception) {
        http_response_code(500);
        $pageError = 'Operaci se nyní nepodařilo dokončit. Zkuste to prosím později.';
        error_log(sprintf(
            'Unexpected client dashboard operation failure: %s (%s)',
            $exception->getMessage(),
            $exception::class
        ));
    }
}

if ($service instanceof ClientDashboardService) {
    try {
        $dashboard = $service->load($userId);
        $clientStats = $dashboard['summary'];
        $stores = $dashboard['stores'];
        $invoices = $dashboard['invoices'];
        $webhooks = $dashboard['webhooks'];
        $payouts = $dashboard['payouts'];
        $integrations = $dashboard['integrations'];
        $requests = $dashboard['requests'];
        $walletPath = $repository?->findAssignedWallet($userId);
        if ($walletPath === null) {
            $walletError = 'Účet nemá jednoznačně přiřazenou peněženku.';
        } else {
            try {
                $wallet = new ElectrumWallet(new ElectrumRPC(
                    $config['rpc_host'] ?? '',
                    (int) ($config['rpc_port'] ?? 0),
                    is_string($config['rpc_user'] ?? null) ? $config['rpc_user'] : null,
                    is_string($config['rpc_pass'] ?? null) ? $config['rpc_pass'] : null
                ));
                $wallet->loadWallet($walletPath);
                $walletBalance = $wallet->getWalletBalance();
            } catch (Throwable $exception) {
                error_log('Client wallet balance load failed: ' . $exception::class);
                $walletError = 'Zůstatek peněženky nyní nelze načíst.';
            }
        }
    } catch (Throwable $exception) {
        error_log(sprintf(
            'Client dashboard data load failed: %s (%s)',
            $exception->getMessage(),
            $exception::class
        ));
        http_response_code(500);
        $pageError = 'Data klientského panelu nyní nejsou dostupná. Zkuste to prosím později.';
    }
}

require __DIR__ . '/views/index_view.php';
