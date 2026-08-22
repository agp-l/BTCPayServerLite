<?php
// admin/wallet.php
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\BtcDashboard;
use Exception;


$walletDir = dirname($config['wallet_path']);
$currentWalletName = $_GET['w'] ?? basename($config['wallet_path']);
$activeWalletPath = rtrim($walletDir, '/') . '/' . $currentWalletName;

$hideEmpty = isset($_GET['hide_empty']) && $_GET['hide_empty'] == '1';

$toastMsg = '';
$sendResult = '';
$sendResultColor = '#17201a';
$sendResultIcon = '';
$exportedSeed = '';
$exportedXprv = ''; 

$rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
$wallet = new ElectrumWallet($rpc);
$dashboard = new BtcDashboard($wallet, $walletDir);

// Doplnění chybějící proměnné pro roletku peněženek
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

try {
    $wallet->loadWallet($activeWalletPath);
    $connStatus = 'Online';
    $fiatText = 'Aktivní spojení: ' . htmlspecialchars($currentWalletName);
} catch (Exception $e) {
    $fiatText = 'Chyba připojení: ' . htmlspecialchars($e->getMessage());
}

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
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bitcoin Peněženka (Pro Verze)</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; } html { scroll-behavior: smooth; }
    
    /* Věrná kopie pozadí z Dashboardu (šedá mřížka + zelená zář) */
    body { 
      margin: 0; 
      color: #17201a; 
      font-family: Inter, sans-serif; 
      background-color: #fafcfa; 
      background-image: 
        radial-gradient(circle at 50% 0%, rgba(47, 211, 90, 0.12) 0%, transparent 60%),
        linear-gradient(to right, rgba(229, 234, 231, 0.7) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(229, 234, 231, 0.7) 1px, transparent 1px);
      background-size: 100% 100%, 24px 24px, 24px 24px;
      background-attachment: fixed;
      padding: 30px 20px 50px; 
      min-height: 100vh; 
    }

    button, input, select { font: inherit; } button { cursor: pointer; }
    .main { width: min(1120px, 100%); margin: 0 auto; padding: 0; }
    
    /* Sjednocené Topbar menu */
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 15px; }
    h1 { font-size: 26px; margin: 0; display: flex; align-items: center; gap: 10px; }
    .nav-links { display: flex; gap: 10px; flex-wrap: wrap; }
    .ghost-btn { border: 1px solid #e5eae7; background: #fff; border-radius: 11px; padding: 10px 16px; color: #17201a; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .ghost-btn:hover { border-color: #2fd35a; color: #2fd35a; }
    .ghost-btn.active { background: #17201a; color: #fff; border-color: #17201a; }

    .top-actions { display: flex; gap: 10px; align-items: center; }
    .select, .ghost { border: 1px solid #e5eae7; background: #fff; border-radius: 11px; padding: 10px 14px; color: #17201a; transition: all 0.2s; font-weight: 600; font-size: 13px; outline: none; }
    .ghost { width: 42px; height: 42px; padding: 0; display: grid; place-items: center; text-decoration: none; font-size: 16px; } .ghost:hover { border-color: #2fd35a; color: #2fd35a; }
    .grid { display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start; }
    .col-main { display: grid; gap: 24px; } .col-side { display: grid; gap: 24px; }
    .card { background: #fff; border: 1px solid #e5eae7; border-radius: 18px; box-shadow: 0 8px 30px rgba(20,45,28,.06); transition: background 0.3s, border-color 0.3s; }
    .balance { padding: 30px; display: flex; justify-content: space-between; gap: 30px; align-items: center; min-height: 185px; }
    .label { color: #748078; font-size: 14px; margin-bottom: 10px; }
    .balance-row { display: flex; align-items: center; gap: 10px; }
    .balance-value { font-size: 38px; font-weight: 800; letter-spacing: -1.5px; color: #17201a; }
    .eye { border: 0; background: none; color: #748078; font-size: 18px; padding: 0 5px; } .eye:hover { color: #2fd35a; }
    .fiat { color: #20b948; font-size: 14px; font-weight: 600; margin-top: 8px; }
    .spark { width: 100%; max-width: 250px; height: 80px; } .spark svg { width: 100%; height: 100%; }
    .transactions { padding: 22px; }
    .card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
    .card-head h2 { font-size: 18px; margin: 0; color: #17201a; display: flex; align-items: center; gap: 8px; }
    .link { border: 0; background: none; color: #20b948; font-weight: 600; text-decoration: none; font-size: 13px;} .link:hover{ text-decoration: underline; }
    .tx-list { max-height: 520px; overflow-y: auto; padding-right: 5px; } .tx-list::-webkit-scrollbar { width: 4px; } .tx-list::-webkit-scrollbar-thumb { background: #e5eae7; border-radius: 10px; }
    .tx { display: grid; grid-template-columns: 42px 1fr auto auto; gap: 12px; align-items: center; padding: 15px 0; border-bottom: 1px solid #e5eae7; } .tx:last-child { border-bottom: 0; }
    .tx-icon { width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; font-size: 16px; flex-shrink: 0; } .tx-icon.in { background: #e6f9eb; color: #13aa3d; } .tx-icon.out { background: #fff0f0; color: #ef4d4d; }
    .tx strong { font-size: 13px; color: #17201a; } .tx small { display: block; color: #748078; margin-top: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .tx .amount { text-align: right; font-size: 13px; font-weight: 700; } .amount.in { color: #13aa3d; } .amount.out { color: #ef4d4d; }
    .time { font-size: 11px; color: #748078; white-space: nowrap; font-weight: 600; }
    .receive { padding: 22px; text-align: center; }
    .receive h2 { margin: 0; text-align: left; font-size: 18px; color: #17201a; }
    .qr-container { background: #fff; padding: 10px; border-radius: 12px; display: inline-block; margin-bottom: 15px; border: 1px solid #e5eae7; }
    .address { display: flex; gap: 8px; align-items: center; border: 1px solid #e5eae7; border-radius: 10px; padding: 10px; font-size: 12px; text-align: left; word-break: break-all; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: #fff; color: #17201a; }
    .copy { border: 0; background: transparent; color: #748078; font-size: 16px; cursor: pointer; padding: 0 5px; } .copy:hover { color: #2fd35a; }
    .primary { width: 100%; border: 0; background: #2fd35a; color: #fff; border-radius: 10px; padding: 13px; font-weight: 700; margin-top: 12px; transition: 0.2s; } .primary:hover { background: #20b948; }
    .send { padding: 24px; } .send-grid { display: grid; grid-template-columns: 1fr; gap: 18px; }
    .field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 7px; color: #748078; text-transform: uppercase; letter-spacing: 0.5px; }
    .input-wrap { display: flex; border: 1px solid #e5eae7; border-radius: 10px; overflow: hidden; background: #fff; }
    .input-wrap input { width: 100%; border: 0; outline: 0; padding: 13px; background: transparent; color: #17201a; font-family: Inter, system-ui, sans-serif; }
    .input-wrap .unit { padding: 13px 15px; font-weight: 700; color: #748078; border-left: 1px solid #e5eae7; background: #f9fafa; font-size: 13px; display: flex; align-items: center; }
    .input-wrap input:focus { box-shadow: inset 0 0 0 1px #2fd35a; }
    .send-button { margin-top: 10px; }
    .security { padding: 22px; background: #fff; }
    .toast { position: fixed; right: 25px; bottom: 25px; background: #17201a; color: #fff; padding: 12px 16px; border-radius: 10px; font-weight: 600; opacity: 0; transform: translateY(10px); transition: .2s; pointer-events: none; z-index: 100; box-shadow: 0 8px 30px rgba(20,45,28,.06); } .toast.show { opacity: 1; transform: translateY(0); }
    .tx-details { grid-column: 1 / -1; background: #f9fafa; border: 1px solid #e5eae7; border-radius: 12px; padding: 14px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; margin-top: 10px; color: #17201a; }
    .tx-details-row { display: flex; justify-content: space-between; margin-bottom: 4px; padding-bottom: 4px; border-bottom: 1px dashed #e5eae7; }
    .key-box { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 10px; padding: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; word-break: break-all; margin-top: 6px; }
    .filter-control { margin-bottom: 15px; display: flex; align-items: center; gap: 8px; font-size: 13px; color: #17201a; font-weight: 600; }
    .filter-control label { cursor: pointer; user-select: none; }
    @media(max-width:1050px) { .grid { grid-template-columns: 1fr; } .balance { padding: 22px; display: block; } .spark { max-width: none; width: 100%; margin-top: 20px; height: 75px; } }
    @media(max-width:760px) { .topbar { align-items: flex-start; } .topbar h1 { font-size: 25px; } .tx { grid-template-columns: 38px minmax(0, 1fr) auto; } .time { display: none; } .receive { text-align: center; } .receive h2 { text-align: left; } }
  </style>
</head>
<body>
<div class="main">
    
    <!-- Sjednocené hlavní menu -->
    <header class="topbar">
        <h1><i class="fa-solid fa-bolt" style="color: #2fd35a;"></i> BTCPay Lite</h1>
        <div class="nav-links">
            <a href="index.php" class="ghost-btn"><i class="fa-solid fa-chart-pie"></i> Přehled</a>
            <a href="wallet.php" class="ghost-btn active"><i class="fa-solid fa-wallet"></i> Peněženka</a>
            <a href="stores.php" class="ghost-btn"><i class="fa-solid fa-shop"></i> E-shopy</a>
            <a href="invoices.php" class="ghost-btn"><i class="fa-solid fa-file-invoice"></i> Faktury</a>
            <a href="webhooks.php" class="ghost-btn"><i class="fa-solid fa-satellite-dish"></i> Webhooky</a>
        </div>
    </header>

    <!-- Ovládací panel peněženky -->
    <div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding: 15px 25px;">
        <div style="font-weight: 600; font-size: 14px; color: #748078;"><i class="fa-solid fa-folder-open" style="margin-right: 5px;"></i> Vyberte peněženku k zobrazení:</div>
        <div class="top-actions">
            <form method="GET" action="wallet.php" style="margin:0;">
              <select name="w" class="select" onchange="this.form.submit()" style="font-family: inherit; font-weight: 600;">
                <?php foreach ($availableWallets as $w): ?>
                  <option value="<?php echo htmlspecialchars($w); ?>" <?php echo $w === $currentWalletName ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($w); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
            <a href="wallet.php?w=<?php echo urlencode($currentWalletName); ?>" class="ghost" title="Aktualizovat data"><i class="fa-solid fa-rotate-right"></i></a>
        </div>
    </div>

    <section class="grid">
      <div class="col-main">
        
        <article class="card balance">
          <div>
            <div class="label">Celkový dostupný zůstatek <button class="eye" id="eye" title="Skrýt/Zobrazit"><i class="fa-solid fa-eye"></i></button></div>
            <div class="balance-row"><div class="balance-value" id="balance"><?php echo htmlspecialchars($balanceFormatted); ?> BTC</div></div>
            <div class="fiat" id="fiat"><i class="fa-solid fa-signal" style="margin-right:5px; font-size:11px;"></i><?php echo htmlspecialchars($fiatText); ?> <?php echo $fiatValueStr ? '<span style=\'color:#748078; font-weight:normal;\'>('.htmlspecialchars($fiatValueStr).')</span>' : ''; ?></div>
          </div>
          <div class="spark">
            <svg viewBox="0 0 400 110" preserveAspectRatio="none"><defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2fd35a" stop-opacity=".3"/><stop offset="1" stop-color="#2fd35a" stop-opacity="0"/></linearGradient></defs><path d="M0 86 C25 65 32 89 55 70 S88 77 110 50 S145 65 167 44 S190 55 215 28 S245 51 270 25 S298 46 322 19 S352 35 400 7 L400 110 L0 110Z" fill="url(#g)"/><path d="M0 86 C25 65 32 89 55 70 S88 77 110 50 S145 65 167 44 S190 55 215 28 S245 51 270 25 S298 46 322 19 S352 35 400 7" fill="none" stroke="#2fd35a" stroke-width="4"/></svg>
          </div>
        </article>
     
        <article class="card transactions">
          <div class="card-head"><h2><i class="fa-solid fa-list-ul" style="color:#748078;"></i> Historie transakcí</h2></div>
          <div class="tx-list">
            <?php if (empty($finalTxs)): ?><div style="padding: 20px; color: #748078; text-align: center;"><i class="fa-solid fa-inbox" style="display:block; font-size:24px; margin-bottom:10px; opacity:0.5;"></i>Žádné transakce</div><?php else: ?>
                <?php foreach ($finalTxs as $tx): ?>
                <div class="tx" style="display: block;">
                    <div style="display: grid; grid-template-columns: 42px 1fr auto auto; gap: 12px; align-items: center;">
                        <span class="tx-icon <?php echo $tx['isInc'] ? 'in' : 'out'; ?>"><i class="fa-solid <?php echo $tx['isInc'] ? 'fa-arrow-down' : 'fa-arrow-up'; ?>"></i></span>
                        <div style="overflow:hidden"><strong><?php echo $tx['isInc'] ? 'Přijato' : 'Odesláno'; ?></strong><small><?php echo htmlspecialchars($tx['timeStr']); ?></small></div>
                        <div class="amount <?php echo $tx['isInc'] ? 'in' : 'out'; ?>"><?php echo htmlspecialchars($tx['valStr']); ?> BTC</div>
                        <div class="time"><?php echo ($tx['confText'] === 'Čeká v síti' ? '<i class="fa-solid fa-hourglass-half"></i> ' : '<i class="fa-solid fa-check-double"></i> ') . htmlspecialchars($tx['confText']); ?></div>
                    </div>
                    <div class="tx-details">
                        <?php if (empty($tx['outputs'])): ?><div style="color:#748078;">Detail adres momentálně není dostupný.</div><?php else: ?>
                            <?php foreach ($tx['outputs'] as $o): ?>
                            <div class="tx-details-row"><span style="word-break:break-all; padding-right:10px;"><?php echo htmlspecialchars($o['address']); ?></span><span style="color:#748078; white-space:nowrap; text-align:right;"><span style="font-weight:700; color:#20b948;"><?php echo htmlspecialchars($o['label']); ?></span><br><?php echo htmlspecialchars($o['value']); ?> BTC</span></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div style="display:flex; justify-content:space-between; align-items:flex-end; color: #748078; padding-top: 6px;">
                            <div style="flex:1; padding-right:10px;"><span style="color:#17201a; font-weight:700;">TXID:</span><br><span style="word-break: break-all;"><?php echo htmlspecialchars($tx['txid']); ?></span></div>
                            <a href="https://mempool.space/tx/<?php echo htmlspecialchars($tx['txid']); ?>" target="_blank" class="ghost" style="height:auto; width:auto; padding:4px 8px; font-size:11px; text-decoration:none;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Prozkoumat</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </article>

        <article class="card receive">
          <div class="card-head" style="margin-bottom: 18px;">
            <h2><i class="fa-solid fa-qrcode" style="color:#748078;"></i> Rychlý příjem BTC</h2>
            <form method="POST" action="wallet.php?w=<?php echo urlencode($currentWalletName); ?>" style="margin:0;">
              <input type="hidden" name="action" value="new_address">
              <button type="submit" class="link" style="font-size:12px;"><i class="fa-solid fa-plus"></i> Vygenerovat novou</button>
            </form>
          </div>
          <div class="qr-container">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=bitcoin:<?php echo htmlspecialchars($receiveAddress); ?>" alt="QR" style="width: 160px; height: 160px; display: block;">
          </div>
          <div class="address"><span id="btcAddress" style="flex: 1;"><?php echo htmlspecialchars($receiveAddress); ?></span><button type="button" class="copy" id="copyAddress" title="Kopírovat"><i class="fa-regular fa-copy"></i></button></div>
          <button type="button" class="primary" id="copyAddress2"><i class="fa-solid fa-paste"></i> Zkopírovat do schránky</button>
        </article>

        <article class="card transactions">
          <div class="card-head"><h2><i class="fa-solid fa-wallet" style="color:#748078;"></i> Všechny adresy peněženky</h2></div>
          
          <form method="GET" action="wallet.php" class="filter-control" style="margin: 0 0 15px 0;">
            <input type="hidden" name="w" value="<?php echo htmlspecialchars($currentWalletName); ?>">
            <input type="checkbox" id="hideEmptyAddresses" name="hide_empty" value="1" onchange="this.form.submit()" <?php echo $hideEmpty ? 'checked' : ''; ?>>
            <label for="hideEmptyAddresses">Skrýt prázdné adresy</label>
            <noscript><button type="submit" class="ghost" style="padding: 2px 8px; font-size: 11px;">Filtrovat</button></noscript>
          </form>

          <div class="tx-list">
            <?php if (empty($finalAddresses)): ?><div style="padding: 20px; color: #748078; text-align: center;">Žádné adresy k zobrazení.</div><?php else: ?>
                <?php foreach ($finalAddresses as $a): ?>
                <?php 
                    $typeLabel = $a['type'] === 'change' 
                        ? '<span style="color:#748078; border:1px solid #e5eae7; padding:2px 6px; border-radius:4px; font-size:9px; margin-left:6px; text-transform:uppercase; font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Drobné</span>' 
                        : '<span style="color:#20b948; background:#eafbef; padding:2px 6px; border-radius:4px; font-size:9px; margin-left:6px; text-transform:uppercase; font-weight:700;"><i class="fa-solid fa-download"></i> Přijímací</span>';
                    $valColor = $a['hasFunds'] ? '#20b948' : '#748078';
                    $textLabel = $a['hasFunds'] ? 'Leží zde BTC (UTXO)' : 'Prázdná adresa';
                ?>
                <div class="tx" style="grid-template-columns: 42px 1fr auto;"><span class="tx-icon" style="background:transparent; border:1px solid #e5eae7; color:#17201a;"><i class="fa-brands fa-bitcoin"></i></span><div style="overflow:hidden; padding-right:15px;"><strong style="word-break: break-all;"><?php echo htmlspecialchars($a['address']); ?></strong><small><?php echo $textLabel . ' ' . $typeLabel; ?></small></div><div style="text-align: right;"><div class="amount" style="color:<?php echo $valColor; ?>"><?php echo htmlspecialchars($a['valStr']); ?> BTC</div><button type="button" class="ghost" style="height:auto; width:auto; padding:2px 8px; font-size:11px; margin-top:4px; border:none; background:#f9fafa; border:1px solid #e5eae7;" onclick="copyText('<?php echo htmlspecialchars($a['address']); ?>')"><i class="fa-regular fa-copy"></i> Kopírovat</button></div></div>
                <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </article>

      </div>

      <div class="col-side">
        
        <article class="card send">
          <div class="card-head" style="margin-bottom: 20px;"><h2><i class="fa-solid fa-paper-plane" style="color:#748078;"></i> Odeslat BTC</h2></div>
          <form method="POST" action="wallet.php?w=<?php echo urlencode($currentWalletName); ?>">
              <input type="hidden" name="action" value="send">
              <div class="send-grid">
                <div class="field"><label>Adresa příjemce</label><div class="input-wrap"><input id="recipient" name="to" placeholder="bc1q..." required><button type="button" class="copy" id="paste" title="Vložit ze schránky" style="padding:0 15px; border-left:1px solid #e5eae7;"><i class="fa-solid fa-paste"></i></button></div></div>
                <div class="field"><label>Částka ke stržení</label><div class="input-wrap"><input id="amount" name="amount" type="text" placeholder="0.00000000" required><div class="unit">BTC</div></div><div style="display: flex; gap: 5px; margin-top: 8px;"><button type="button" class="ghost" style="padding: 4px 8px; height: auto; border-radius: 6px; font-size: 11px;" onclick="setAmount(0.25)">25%</button><button type="button" class="ghost" style="padding: 4px 8px; height: auto; border-radius: 6px; font-size: 11px;" onclick="setAmount(0.50)">50%</button><button type="button" class="ghost" style="padding: 4px 8px; height: auto; border-radius: 6px; font-size: 11px; background: #17201a; color: #fff;" onclick="setAmount('MAX')">MAX</button></div></div>
                <div class="field"><label>Poplatek sítě (sat/vB)</label><div class="input-wrap"><input id="feeRate" name="fee" type="number" min="1" value="<?php echo htmlspecialchars((string)$feeMed); ?>" oninput="updateFeeEstimate()" required></div><div style="display: flex; gap: 5px; margin-top: 8px;"><button type="button" class="ghost" style="padding: 4px 8px; height: auto; border-radius: 6px; font-size: 11px;" onclick="setFee(<?php echo $feeHigh; ?>)"><i class="fa-solid fa-bolt" style="color:#f59e0b; margin-right:4px;"></i> <?php echo $feeHigh; ?></button><button type="button" class="ghost" style="padding: 4px 8px; height: auto; border-radius: 6px; font-size: 11px;" onclick="setFee(<?php echo $feeMed; ?>)"><i class="fa-solid fa-clock" style="color:#3b82f6; margin-right:4px;"></i> <?php echo $feeMed; ?></button><button type="button" class="ghost" style="padding: 4px 8px; height: auto; border-radius: 6px; font-size: 11px;" onclick="setFee(<?php echo $feeLow; ?>)"><i class="fa-solid fa-mug-hot" style="color:#748078; margin-right:4px;"></i> <?php echo $feeLow; ?></button></div><div id="feeEstimate" style="font-size: 11px; color: #748078; margin-top: 8px; font-weight: 500;">Odhad poplatku sítě: ~140 sat (0.00000140 BTC)</div></div>
                <div class="field"><label>Heslo (volitelné)</label><div class="input-wrap"><input type="password" name="password" placeholder="***"></div></div>
                <div class="send-button"><button type="submit" class="primary" onclick="this.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Zpracovávám...';"><i class="fa-solid fa-arrow-right"></i> Potvrdit odeslání</button></div>
                <?php if ($sendResult): ?><div style="text-align: center; font-size: 13px; font-weight: 600; margin-top: 5px; color: <?php echo $sendResultColor; ?>"><?php echo $sendResultIcon . htmlspecialchars($sendResult); ?></div><?php endif; ?>
              </div>
          </form>
        </article>

        <article class="card security">
          <div class="card-head" style="margin-bottom: 15px;"><h2><i class="fa-solid fa-shield-halved" style="color:#748078;"></i> Klíče a záloha</h2></div>
          <div style="margin-bottom: 18px;"><label style="font-size: 11px; font-weight: 700; color: #748078; text-transform: uppercase;">Master Public Key (xpub / zpub)</label><div style="font-size: 11px; color: #748078; margin-bottom: 4px;">Klíč pro sledování plateb v jiné peněžence (bezpečná volba):</div><div class="key-box" id="mpkBox"><?php echo $mpk ? htmlspecialchars($mpk) : 'Nedostupné'; ?></div><?php if ($mpk): ?><button type="button" class="ghost" style="width:100%; height:auto; padding:6px; font-size:11px; margin-top:6px;" onclick="copyText('<?php echo htmlspecialchars($mpk); ?>')"><i class="fa-regular fa-copy"></i> Kopírovat xpub</button><?php endif; ?></div>
          <hr style="border: 0; border-top: 1px dashed #e5eae7; margin: 18px 0;">
          <label style="font-size: 11px; font-weight: 700; color: #ef4d4d; text-transform: uppercase;">Citlivé klíče (Seed & PrivKey)</label><div style="font-size: 11px; color: #748078; margin-bottom: 8px;">Pro dešifrování zadej heslo peněženky:</div>
          <form method="POST" action="wallet.php?w=<?php echo urlencode($currentWalletName); ?>" style="margin-bottom: 10px;">
            <input type="hidden" name="action" value="export_keys">
            <div class="input-wrap" style="margin-bottom: 8px;"><input type="password" name="export_password" placeholder="Heslo peněženky"></div>
            <button type="submit" class="ghost" style="width:100%; height:auto; padding:8px; font-size:12px; background:#f9fafa;"><i class="fa-solid fa-unlock-keyhole"></i> Odemknout zálohu</button>
          </form>
          <?php if ($exportedSeed): ?><div style="margin-top: 12px;"><strong style="font-size: 11px; color: #17201a;"><i class="fa-solid fa-seedling"></i> Seed fráze (12 slov):</strong><div class="key-box" style="background:#fff0f0; border-color:#ffcccc; color:#ef4d4d; font-weight:600;"><?php echo htmlspecialchars($exportedSeed); ?></div></div><?php endif; ?>
          <?php if ($exportedXprv): ?><div style="margin-top: 10px;"><strong style="font-size: 11px; color: #17201a;"><i class="fa-solid fa-key"></i> Master Private Key (xprv):</strong><div class="key-box" style="background:#fff0f0; border-color:#ffcccc; color:#ef4d4d; font-size:10px;"><?php echo htmlspecialchars($exportedXprv); ?></div></div><?php endif; ?>
        </article>

      </div>
    </section>
  </main>

<div class="toast" id="toast"><?php echo htmlspecialchars($toastMsg); ?></div>
<script>
  const toastEl = document.querySelector('#toast'); 
  const toast = msg => { 
      toastEl.innerHTML = `<i class="fa-solid fa-bell"></i> ${msg}`; 
      toastEl.classList.add('show'); 
      setTimeout(() => toastEl.classList.remove('show'), 2500); 
  };
  
  <?php if ($toastMsg): ?>toast('<?php echo addslashes($toastMsg); ?>');<?php endif; ?>
  
  async function copyText(text) { 
      try { 
          await navigator.clipboard.writeText(text); 
          toast('Zkopírováno do schránky'); 
      } catch(e) { 
          toast('Kopírování selhalo'); 
      } 
  }
  
  document.querySelector('#copyAddress').onclick = () => copyText(document.querySelector('#btcAddress').textContent);
  document.querySelector('#copyAddress2').onclick = () => copyText(document.querySelector('#btcAddress').textContent);
  
  document.querySelector('#paste').onclick = async () => { 
      try { 
          document.querySelector('#recipient').value = await navigator.clipboard.readText(); 
          toast('Vloženo'); 
      } catch(e) { 
          toast('Povolte přístup ke schránce'); 
      } 
  };
  
  const balanceEl = document.querySelector('#balance');
  const fiatEl = document.querySelector('#fiat'); 
  const eyeBtn = document.querySelector('#eye');
  let hidden = false; 
  const actualBalance = "<?php echo htmlspecialchars($balanceFormatted); ?>";
  const actualFiat = "<i class='fa-solid fa-signal' style='margin-right:5px; font-size:11px;'></i><?php echo htmlspecialchars($fiatText); ?> <?php echo $fiatValueStr ? '<span style=\'color:#748078; font-weight:normal;\'>('.htmlspecialchars($fiatValueStr).')</span>' : ''; ?>";
  
  eyeBtn.onclick = () => { 
      hidden = !hidden; 
      balanceEl.textContent = hidden ? '•••••••• BTC' : `${actualBalance} BTC`; 
      fiatEl.innerHTML = hidden ? '<i class="fa-solid fa-eye-slash" style="margin-right:5px; font-size:11px;"></i>Zůstatek skrytý' : actualFiat; 
      eyeBtn.innerHTML = hidden ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
  };
  
  const currentBalanceNum = <?php echo $balanceConfirmed; ?>;
  window.setAmount = val => document.getElementById('amount').value = (val === 'MAX') ? '!' : (currentBalanceNum * val).toFixed(8);
  
  function updateFeeEstimate() { 
      const rate = parseFloat(document.getElementById('feeRate').value) || 1; 
      const estSats = Math.round(rate * 140); 
      document.getElementById('feeEstimate').textContent = `Odhad poplatku sítě: ~${estSats} sat (${(estSats / 100000000).toFixed(8)} BTC)`; 
  }
  
  window.setFee = val => { 
      document.getElementById('feeRate').value = val; 
      updateFeeEstimate(); 
      toast(`Poplatek upraven na ${val} sat/vB`); 
  };
  
  updateFeeEstimate();
</script>
</body>
</html>