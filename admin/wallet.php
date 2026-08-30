<?php
// admin/wallet.php
declare(strict_types=1);

ini_set('display_errors', '1'); // Ve výchozím stavu na produkci skrýváme chyby
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcDashboard;
use BtcPayLite\AuthManager;

// Zavoláme statickou metodu. Pokud to není admin, metoda ho automaticky vyhodí na login.
AuthManager::requireRole('admin', '../client/login.php');

$walletDir = dirname($config['wallet_path']);
$currentWalletName = $_GET['w'] ?? basename($config['wallet_path']);
$activeWalletPath = rtrim($walletDir, '/') . '/' . $currentWalletName;

$hideEmpty = isset($_GET['hide_empty']) && $_GET['hide_empty'] == '1';

// Proměnné pro zprávy v UI
$toastMsg = '';
$sendResult = '';
$sendResultColor = '#17201a';
$sendResultIcon = '';
$exportedSeed = '';
$exportedXprv = ''; 

// Inicializace
$rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
$wallet = new ElectrumWallet($rpc);
$dashboard = new BtcDashboard($wallet, $walletDir);

// Zjištění dostupných peněženek
$availableWallets = [];
$scanned = @scandir($walletDir);
if (is_array($scanned)) {
    foreach ($scanned as $f) {
        if ($f !== '.' && $f !== '..' && !is_dir($walletDir . '/' . $f)) {
            $availableWallets[] = $f;
        }
    }
}

$connStatus = 'Offline';
$fiatText = 'Spojení ztraceno / Daemon nedostupný';
$fiatValueStr = '';

// Načtení aktivní peněženky
try {
    $wallet->loadWallet($activeWalletPath);
    $connStatus = 'Online';
    $fiatText = 'Aktivní spojení: ' . htmlspecialchars($currentWalletName);
} catch (Exception $e) {
    $fiatText = 'Chyba připojení: ' . htmlspecialchars($e->getMessage());
}

// Zpracování akcí (tlačítka ve formulářích)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $connStatus === 'Online') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'new_address') {
        $res = $dashboard->generateNewAddress();
        $toastMsg = $res['status'] === 'success' ? "Nová adresa vytvořena." : "Chyba: " . $res['message'];
    }
    elseif ($action === 'export_keys') {
        $pwd = $_POST['export_password'] ?? '';
        $res = $dashboard->exportKeys($pwd);
        
        if ($res['status'] === 'success') {
            $exportedSeed = $res['seed'];
            $exportedXprv = $res['xprv'];
            $toastMsg = "Klíče byly úspěšně dešifrovány.";
        } else {
            $toastMsg = "Chyba: " . $res['message'];
        }
    }
    elseif ($action === 'send') {
        $to = $_POST['to'] ?? ''; 
        $amountRaw = str_replace(',', '.', $_POST['amount'] ?? '0');
        $amount = $amountRaw === '!' ? '!' : (float)$amountRaw; 
        $feeRate = (int)($_POST['fee'] ?? 0); 
        $password = $_POST['password'] ?? '';
        
        $res = $dashboard->executePayment($to, $amount, $password !== '' ? $password : null, $feeRate ?: null);
        
        if ($res['status'] === 'success') {
            $sendResult = "Odesláno! TXID: " . substr($res['txid'], 0, 20) . "..."; 
            $sendResultColor = "#20b948";
            $sendResultIcon = '<i class="fa-solid fa-circle-check"></i> ';
            $toastMsg = "Platba byla odeslána.";
        } else {
            $sendResult = $res['message']; 
            $sendResultColor = "#ef4d4d";
            $sendResultIcon = '<i class="fa-solid fa-circle-xmark"></i> ';
        }
    }
}

// Stažení veškerých dat pro View
$balanceConfirmed = 0;
$balanceFormatted = '0.00000000';
$finalTxs = [];
$finalAddresses = [];
$receiveAddress = 'Žádná adresa nenalezena';
$feeLow = 1; $feeMed = 1; $feeHigh = 1;
$mpk = '';

if ($connStatus === 'Online') {
    $balInfo = $dashboard->getBalanceInfo();
    if ($balInfo['status'] === 'ok') {
        $balanceConfirmed = $balInfo['confirmed_num'];
        $balanceFormatted = $balInfo['confirmed_formatted'];
        
        $czkPrice = $dashboard->getFiatPrice('CZK');
        if ($czkPrice > 0 && $balanceConfirmed > 0) {
            $fiatValueStr = '~ ' . number_format($balanceConfirmed * $czkPrice, 2, ',', ' ') . ' CZK';
        }
    }

    $addrData = $dashboard->getAddressesData($hideEmpty);
    if ($addrData['status'] === 'ok') {
        $finalAddresses = $addrData['list'];
        $receiveAddress = $addrData['recommended_receive'];
    }

    $finalTxs = $dashboard->getTransactionsHistory();
    $fees = $dashboard->getRecommendedFees();
    $feeLow = $fees['low']; $feeMed = $fees['med']; $feeHigh = $fees['high'];
    $mpk = $dashboard->getMasterPublicKey();
}

// Volání šablony
require __DIR__ . '/views/wallet_view.php';