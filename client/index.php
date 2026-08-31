<?php

declare(strict_types=1);

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\ClientDashboardException;
use BtcPayLite\ClientDashboardService;
use BtcPayLite\Database;
use BtcPayLite\ElectrumCliWalletProvisioner;
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
$toastMsg = '';
$pageError = null;
$service = null;

try {
    $walletPath = $config['wallet_path'] ?? null;
    if (!is_string($walletPath) || trim($walletPath) === '') {
        throw new RuntimeException('Configured default wallet path is unavailable.');
    }

    $database = new Database(
        $config['db_host'],
        $config['db_name'],
        $config['db_user'],
        $config['db_pass'],
        (int) ($config['db_port'] ?? 3306)
    );
    $service = new ClientDashboardService(
        new PdoClientDashboardRepository($database),
        new ElectrumCliWalletProvisioner(
            is_string($config['electrum_cli_path'] ?? null)
                ? $config['electrum_cli_path']
                : '/opt/electrum/run_electrum',
            is_string($config['electrum_data_dir'] ?? null)
                ? $config['electrum_data_dir']
                : '/opt/electrum_config',
            is_string($config['store_wallets_dir'] ?? null)
                ? $config['store_wallets_dir']
                : dirname($walletPath)
        ),
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
            $toastMsg = 'Obchod a jeho peněženka byly úspěšně vytvořeny.';
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
