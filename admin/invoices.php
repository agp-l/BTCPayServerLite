<?php

declare(strict_types=1);

use BtcPayLite\AdminInvoiceService;
use BtcPayLite\AdminManagementService;
use BtcPayLite\AdminOperationsException;
use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\PdoAdminOperationsRepository;
use BtcPayLite\PdoAdminManagementRepository;
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
$newInvoiceUrl = '';
$databaseInvoices = [];
$clients = [];
$filterStores = [];
$invoiceStatuses = [];
$selectedUserId = null;
$selectedStoreId = is_string($_GET['store_id'] ?? null) && $_GET['store_id'] !== ''
    ? $_GET['store_id']
    : null;
$selectedStatus = is_string($_GET['status'] ?? null) && $_GET['status'] !== ''
    ? $_GET['status']
    : null;
$service = null;
$management = null;

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
    $repository = new PdoAdminOperationsRepository($database);
    $management = new AdminManagementService(
        new PdoAdminManagementRepository($database->getPdo())
    );
    $service = new AdminInvoiceService(
        $repository,
        static function (array $store, string $amount, array $metadata) use ($config, $database): array {
            $rpc = new ElectrumRPC(
                $config['rpc_host'],
                (int) $config['rpc_port'],
                $config['rpc_user'],
                $config['rpc_pass']
            );
            $wallet = new ElectrumWallet($rpc);
            $wallet->loadWallet($store['wallet_path']);
            $manager = new BtcInvoiceManager($wallet, $config['secret_key'], $database);

            return $manager->createDatabaseInvoice($store['id'], $amount, $metadata, 15);
        }
    );
} catch (Throwable $exception) {
    error_log(sprintf('Admin invoices initialization failed: %s (%s)', $exception->getMessage(), $exception::class));
    http_response_code(500);
    $pageError = 'Správa faktur nyní není dostupná.';
}

if ($management instanceof AdminManagementService) {
    try {
        $clients = $management->clients();
        $filterStores = $management->stores($selectedUserId);
        $invoiceStatuses = $management->invoiceStatuses();
        $databaseInvoices = $management->invoices(
            $selectedUserId,
            $selectedStoreId,
            $selectedStatus
        );
    } catch (AdminOperationsException $exception) {
        http_response_code($exception->getHttpStatus());
        $pageError = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log(sprintf('Admin invoice list failed: %s (%s)', $exception->getMessage(), $exception::class));
        $pageError = 'Seznam faktur nyní není dostupný.';
    }
}

if ($service instanceof AdminInvoiceService
    && $management instanceof AdminManagementService
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
) {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        if ($action === 'create') {
            $amount = is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '';
            $description = is_string($_POST['description'] ?? null) ? $_POST['description'] : '';
            $orderId = is_string($_POST['order_id'] ?? null) ? $_POST['order_id'] : '';
            $invoice = $service->create($amount, $description, $orderId);
            $newInvoiceUrl = $urlManager->url('/checkout/pay.php', ['id' => $invoice['id']]);

            $history = is_array($_SESSION['created_invoices'] ?? null)
                ? $_SESSION['created_invoices']
                : [];
            $history[] = [
                'url' => $newInvoiceUrl,
                'amount' => $invoice['amount'],
                'desc' => $invoice['description'],
                'time' => $invoice['created_at'],
            ];
            $_SESSION['created_invoices'] = array_slice($history, -20);
            $toastMsg = 'Faktura byla vytvořena a bezpečně uložena.';
        } elseif ($action === 'clear_history') {
            $_SESSION['created_invoices'] = [];
            $toastMsg = 'Lokální náhled historie byl vymazán.';
        } elseif ($action === 'change_status') {
            $management->changeInvoiceStatus(
                is_string($_POST['invoice_id'] ?? null) ? $_POST['invoice_id'] : '',
                is_string($_POST['status'] ?? null) ? $_POST['status'] : ''
            );
            $toastMsg = 'Stav nezaplacené faktury byl změněn.';
        } else {
            throw new AdminOperationsException('Neznámá operace správy faktur.');
        }
    } catch (AuthException $exception) {
        http_response_code(403);
        $pageError = 'Platnost formuláře vypršela. Obnovte stránku a zkuste akci znovu.';
        error_log('Admin invoices CSRF validation failed.');
    } catch (AdminOperationsException $exception) {
        http_response_code($exception->getHttpStatus());
        $pageError = $exception->getMessage();
        error_log('Admin invoice operation failed: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()));
    } catch (Throwable $exception) {
        http_response_code(500);
        $pageError = 'Operaci se nyní nepodařilo dokončit.';
        error_log(sprintf('Unexpected admin invoice failure: %s (%s)', $exception->getMessage(), $exception::class));
    }
}

$invoicesHistory = [];
$rawHistory = $_SESSION['created_invoices'] ?? [];
if (is_array($rawHistory)) {
    foreach (array_reverse(array_slice($rawHistory, -20)) as $item) {
        if (
            is_array($item)
            && is_string($item['url'] ?? null)
            && is_string($item['amount'] ?? null)
            && is_string($item['desc'] ?? null)
            && is_int($item['time'] ?? null)
        ) {
            $invoicesHistory[] = $item;
        }
    }
}

require __DIR__ . '/views/invoices_view.php';
