<?php
// admin/invoices.php
declare(strict_types=1);

ini_set('display_errors', '0'); // Na produkci skrýváme chyby
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\AuthManager;

// Zavoláme statickou metodu. Pokud to není admin, metoda ho automaticky vyhodí na login.
AuthManager::requireRole('admin', '../client/login.php');

$toastMsg = '';
$newInvoiceUrl = '';

try {
    // 1. Připojení k DB a inicializace motoru
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    $rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
    $wallet = new ElectrumWallet($rpc);
    
    // Získání prvního obchodu z DB pro tuto administraci
    $stmt = $db->getPdo()->query("SELECT id, wallet_path FROM stores LIMIT 1");
    $store = $stmt->fetch();
    
    if (!$store) {
        throw new \Exception("V databázi chybí obchod. Vytvoř ho přes phpMyAdmin v tabulce 'stores'.");
    }
    
    $storeId = $store['id'];
    $wallet->loadWallet($store['wallet_path']);
    
    // 2. Správce faktur v databázovém režimu
    $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], $db);

    // 3. Zpracování formuláře z View
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        
        if ($_POST['action'] === 'create') {
            $amount = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
            $desc = trim($_POST['description'] ?? '');
            $orderId = trim($_POST['order_id'] ?? '');

            if ($amount <= 0 || empty($desc)) {
                throw new \Exception("Vyplň platnou částku a popis faktury.");
            }

            // Vytvoření DATABÁZOVÉ faktury
            $metadata = ['orderId' => $orderId, 'itemDesc' => $desc];
            $inv = $invoiceManager->createDatabaseInvoice($storeId, $amount, $metadata, 15);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $baseDir = dirname(dirname($_SERVER['SCRIPT_NAME'])); 
            if ($baseDir === '/' || $baseDir === '\\') $baseDir = '';
            
            // Odkaz na databázovou platební bránu
            $newInvoiceUrl = $protocol . $host . $baseDir . '/checkout/pay.php?id=' . $inv['id'];

            // Uložení do session pro dočasný výpis v administraci
            $_SESSION['created_invoices'][] = [
                'url' => $newInvoiceUrl,
                'amount' => $inv['amount'],
                'desc' => $desc,
                'time' => $inv['created_at']
            ];

            $toastMsg = "Faktura byla úspěšně uložena do databáze.";
        } 
        elseif ($_POST['action'] === 'clear_history') {
            $_SESSION['created_invoices'] = [];
            $toastMsg = "Historie zobrazení vymazána.";
        }
    }
} catch (\Throwable $e) {
    // Zachytí všechny výjimky i fatální chyby
    $toastMsg = "Chyba: " . $e->getMessage();
}

// 4. Extrakce dat pro View
$invoicesHistory = $_SESSION['created_invoices'] ?? [];

// 5. Zavolání šablony
require __DIR__ . '/views/invoices_view.php';