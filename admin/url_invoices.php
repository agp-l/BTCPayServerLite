<?php
// admin/url_invoices.php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcDashboard;
use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\BtcStatelessService;
use BtcPayLite\BtcStatelessAjaxController;
use BtcPayLite\AuthManager;

// Zavoláme statickou metodu. Pokud to není admin, metoda ho automaticky vyhodí na login.
AuthManager::requireRole('admin', '../client/login.php');

// 1. Dependency Injection / Inicializace
$rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
$wallet = new ElectrumWallet($rpc);
$invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], null);
$service = new BtcStatelessService($config, $wallet, $invoiceManager);

$defaultWalletName = basename($config['wallet_path']);
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUri = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);

// 2. Kontroler pro AJAX (Tvorba a ověřování faktur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    ob_start();
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $controller = new BtcStatelessAjaxController($service, $defaultWalletName, $baseUri);
        $response = $controller->handleRequest($_POST);
        
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode($response);
    } catch (\Throwable $e) { 
        ob_end_clean();
        header('Content-Type: application/json');
        $code = $e->getCode();
        http_response_code(($code >= 400 && $code < 600) ? $code : 400);
        echo json_encode(['status' => 'error', 'message' => 'Chyba: ' . $e->getMessage()]);
    }
    exit;
}

// 3. Kontroler pro View (Příprava proměnných pro HTML šablonu)
$dashboard = new BtcDashboard($wallet, dirname($config['wallet_path']));
$availableWallets = $dashboard->getAvailableWallets();
$defaultWallet = $defaultWalletName;

// 4. Vykreslení šablony (Zde se vloží ten čistý HTML soubor, co jsme vytvořili)
require_once __DIR__ . '/views/url_invoices_view.php';
