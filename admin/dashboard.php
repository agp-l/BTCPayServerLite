<?php

declare(strict_types=1);

use BtcPayLite\AdminManagementService;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\PdoAdminManagementRepository;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

$config = isset($config) && is_array($config) ? $config : require __DIR__ . '/../config.php';
$urlManager = isset($urlManager) && $urlManager instanceof UrlManager
    ? $urlManager
    : new UrlManager(
        $_SERVER,
        is_string($config['app_url'] ?? null) ? $config['app_url'] : null
    );

AuthManager::requireRole('admin', $urlManager->url('/login'));

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$dashboardSummary = [
    'total_stores' => 0,
    'total_invoices' => 0,
    'settled_invoices' => 0,
    'total_btc_volume' => '0.00000000',
    'settlement_rate' => 0,
];
$invoices = [];
$clients = [];
$selectedUserId = null;
$pageError = null;

$rawUserId = is_string($_GET['user_id'] ?? null) ? $_GET['user_id'] : '';
if ($rawUserId === '0' || ctype_digit($rawUserId)) {
    $selectedUserId = (int) $rawUserId;
}

try {
    $database = new Database(
        $config['db_host'],
        $config['db_name'],
        $config['db_user'],
        $config['db_pass'],
        (int) ($config['db_port'] ?? 3306)
    );
    $management = new AdminManagementService(
        new PdoAdminManagementRepository($database->getPdo())
    );
    $clients = $management->clients();
    $dashboard = $management->dashboard($selectedUserId);

    $dashboardSummary = $dashboard['summary'];
    $invoices = $dashboard['invoices'];
} catch (Throwable $exception) {
    error_log(sprintf(
        'Admin dashboard load failed: %s (%s)',
        $exception->getMessage(),
        $exception::class
    ));
    $pageError = 'Aktuální provozní data se nepodařilo načíst. Zkuste stránku obnovit později.';
}

require __DIR__ . '/views/index_view.php';
