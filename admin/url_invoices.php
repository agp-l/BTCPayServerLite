<?php
// admin/url_invoices.php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcDashboard;
use BtcPayLite\BtcInvoiceManager;

$rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
$wallet = new ElectrumWallet($rpc);

$walletsDirectory = dirname($config['wallet_path']);
$dashboard = new BtcDashboard($wallet, $walletsDirectory);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    ob_start(); 
    header('Content-Type: application/json');
    
    try {
        if ($_POST['api_action'] === 'create') {
            $selectedWallet = $_POST['wallet'] ?? basename($config['wallet_path']);
            $walletPath = $walletsDirectory . '/' . basename($selectedWallet);
            $wallet->loadWallet($walletPath);
            
            $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], null);

            $amount = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
            $desc = trim($_POST['description'] ?? '');
            $orderId = trim($_POST['order_id'] ?? '');

            if ($amount <= 0 || empty($desc)) {
                throw new \Exception("Vyplň platnou částku a popis faktury.");
            }

            // Přibalíme peněženku do customData, aby ji ověřovač příště sám poznal
            $customData = [
                'order_id' => $orderId,
                'wallet' => $selectedWallet
            ];

            $res = $invoiceManager->createStatelessInvoice($amount, $desc, $customData, 15);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $path = dirname($_SERVER['REQUEST_URI']);
            $newInvoiceUrl = $protocol . $host . rtrim($path, '/\\') . '/url_pay.php?inv=' . $res['token'];

            ob_end_clean();
            echo json_encode([
                'status' => 'ok',
                'url' => $newInvoiceUrl,
                'token' => $res['token'],
                'amount' => number_format($amount, 8, '.', ''),
                'desc' => $desc,
                'order_id' => $orderId,
                'wallet' => $selectedWallet,
                'time' => time()
            ]);
            exit;
        } 
        
        elseif ($_POST['api_action'] === 'check_status') {
            $token = $_POST['token'] ?? '';
            if (empty($token)) throw new \Exception("Chybí token faktury.");

            // 1. Rozkódujeme token pro zjištění dat
            $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], null);
            $decoded = $invoiceManager->decodeStatelessToken($token);
            
            // 2. Detekce peněženky 
            $detectedWallet = $decoded['p']['wallet'] ?? basename($config['wallet_path']);
            $walletPath = $walletsDirectory . '/' . basename($detectedWallet);
            $wallet->loadWallet($walletPath);
            
            // 3. Ověření stavu na blockchainu
            $statusData = $invoiceManager->checkStatelessPaymentStatus($token);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $path = dirname($_SERVER['REQUEST_URI']);
            $fullUrl = $protocol . $host . rtrim($path, '/\\') . '/url_pay.php?inv=' . $token;

            ob_end_clean();
            
            // OPRAVA: Data čteme přímo z dekódovaného tokenu ($decoded)
            echo json_encode([
                'status' => 'ok',
                'payment_status' => $statusData['status'],
                'missing_amount' => $statusData['payment']['missing_amount'] ?? '0.00000000',
                
                'amount' => number_format((float)($decoded['v'] ?? 0), 8, '.', ''),
                'desc' => $decoded['d'] ?? '',
                'order_id' => $decoded['p']['order_id'] ?? '',
                'wallet' => $detectedWallet,
                'time' => $decoded['t'] ?? time(),
                
                'url' => $fullUrl,
                'token' => $token
            ]);
            exit;
        }

    } catch (\Throwable $e) { 
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Chyba: ' . $e->getMessage()]);
        exit;
    }
}

$availableWallets = $dashboard->getAvailableWallets();
$defaultWallet = basename($config['wallet_path']);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stateless URL Faktury - BTCPay Lite</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    body { 
      margin: 0; color: #17201a; font-family: Inter, sans-serif; background-color: #fafcfa; 
      background-image: radial-gradient(circle at 50% 0%, rgba(47, 211, 90, 0.12) 0%, transparent 60%), linear-gradient(to right, rgba(229, 234, 231, 0.7) 1px, transparent 1px), linear-gradient(to bottom, rgba(229, 234, 231, 0.7) 1px, transparent 1px);
      background-size: 100% 100%, 24px 24px, 24px 24px; background-attachment: fixed; padding: 40px 20px; min-height: 100vh; 
    }
    .container { max-width: 900px; margin: 0 auto; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
    h1 { font-size: 26px; margin: 0; display: flex; align-items: center; gap: 10px; }
    .nav-links { display: flex; gap: 10px; flex-wrap: wrap; }
    .ghost-btn { border: 1px solid #e5eae7; background: #fff; border-radius: 11px; padding: 10px 16px; color: #17201a; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; cursor: pointer; }
    .ghost-btn:hover { border-color: #17201a; background: #f0f4f1; }
    .ghost-btn.active { background: #17201a; color: #fff; border-color: #17201a; }
    
    .card { background: #fff; border: 1px solid #e5eae7; border-radius: 18px; padding: 30px; box-shadow: 0 8px 30px rgba(20,45,28,.06); margin-bottom: 24px; }
    .card-title { font-size: 18px; font-weight: 700; margin: 0 0 20px 0; display: flex; align-items: center; justify-content: space-between; }
    .field { margin-bottom: 18px; }
    label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 7px; color: #748078; text-transform: uppercase; }
    .input-wrap { display: flex; border: 1px solid #e5eae7; border-radius: 10px; overflow: hidden; background: #fff; transition: 0.2s; }
    .input-wrap:focus-within { box-shadow: inset 0 0 0 1px #2fd35a; border-color: #2fd35a; }
    input, select { width: 100%; border: 0; outline: 0; padding: 13px; font: inherit; background: transparent; }
    select { cursor: pointer; color: #17201a; font-weight: 600; }
    .unit { padding: 13px 15px; font-weight: 700; color: #748078; border-left: 1px solid #e5eae7; background: #f9fafa; }
    .primary { width: 100%; border: 0; background: #2fd35a; color: #fff; border-radius: 10px; padding: 13px; font-weight: 700; cursor: pointer; font-size: 14px; transition: 0.2s; display:inline-flex; align-items:center; justify-content:center; gap:8px;}
    .primary:hover { background: #20b948; }
    
    .invoice-item { padding: 15px; border: 1px solid #e5eae7; border-radius: 12px; margin-bottom: 15px; background: #f9fafa; transition: 0.2s; }
    .invoice-item:hover { border-color: #17201a; background: #fff; box-shadow: 0 4px 15px rgba(0,0,0,.03); }
    .invoice-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .invoice-amount { font-family: ui-monospace, monospace; font-weight: 800; color: #17201a; font-size: 16px; }
    .invoice-actions { display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap; }
    
    .status-badge { display: inline-block; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 11px; text-transform: uppercase; }
    .s-paid { background: #eafbef; color: #13aa3d; border: 1px solid #13aa3d; }
    .s-paid_late { background: #f3e8ff; color: #7e22ce; border: 1px solid #7e22ce; }
    .s-unpaid { background: #fff; color: #748078; border: 1px solid #e5eae7; }
    .s-expired { background: #fee2e2; color: #ef4d4d; border: 1px solid #ef4d4d; }
    .s-pending_mempool { background: #e0f2fe; color: #0284c7; border: 1px solid #0284c7; }
    .s-underpaid { background: #fef3c7; color: #d97706; border: 1px solid #d97706; }
    .s-unknown { background: #f9fafa; color: #748078; border: 1px solid #e5eae7; }
    
    .toast { position: fixed; right: 25px; bottom: 25px; background: #17201a; color: #fff; padding: 12px 16px; border-radius: 10px; font-weight: 600; box-shadow: 0 8px 30px rgba(20,45,28,.06); opacity: 0; transform: translateY(10px); transition: 0.3s; z-index: 1000; }
    .toast.show { opacity: 1; transform: translateY(0); }
  </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1><i class="fa-solid fa-bolt" style="color: #2fd35a;"></i> BTCPay Lite</h1>
        <div class="nav-links">
            <a href="index.php" class="ghost-btn"><i class="fa-solid fa-chart-pie"></i> Přehled</a>
            <a href="wallet.php" class="ghost-btn"><i class="fa-solid fa-wallet"></i> Peněženka</a>
            <a href="url_invoices.php" class="ghost-btn active"><i class="fa-solid fa-link"></i> URL Faktury</a>
            <a href="invoices.php" class="ghost-btn"><i class="fa-solid fa-database"></i> DB Faktury</a>
        </div>
    </div>

    <!-- Generování faktury -->
    <div class="card">
        <div class="card-title">
            <span style="display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-plus-circle" style="color:#20b948;"></i> Vystavit Stateless Fakturu (URL)</span>
        </div>
        <form id="createForm">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <div class="field">
                    <label>Cílová peněženka</label>
                    <div class="input-wrap">
                        <select id="walletSelect" required>
                            <?php foreach ($availableWallets as $w): ?>
                                <option value="<?php echo htmlspecialchars($w); ?>" <?php if($w === $defaultWallet) echo 'selected'; ?>>📁 <?php echo htmlspecialchars($w); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label>Částka (BTC)</label>
                    <div class="input-wrap"><input type="text" id="amount" placeholder="0.00100000" required><div class="unit">BTC</div></div>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <div class="field">
                    <label>Popis / Název položky</label>
                    <div class="input-wrap"><input type="text" id="desc" placeholder="Např. Osobní konzultace" required></div>
                </div>
                <div class="field">
                    <label>Interní ID (volitelné)</label>
                    <div class="input-wrap"><input type="text" id="order_id" placeholder="Např. ORD-123"></div>
                </div>
            </div>
            <button type="submit" class="primary" id="btnCreate"><i class="fa-solid fa-magic"></i> Vygenerovat zabezpečený odkaz</button>
        </form>
    </div>

    <!-- Ověřovač cizích faktur -->
    <div class="card">
        <div class="card-title">
            <span style="display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-magnifying-glass" style="color:#20b948;"></i> Lupa - Ověřit cizí URL fakturu</span>
        </div>
        <form id="verifyForm">
            <div class="field">
                <label>Vložte odkaz (nebo token) od zákazníka</label>
                <div class="input-wrap">
                    <input type="text" id="verifyInput" placeholder="https://..." required>
                </div>
            </div>
            <button type="submit" class="primary" id="btnVerify" style="width: auto; padding: 12px 20px;"><i class="fa-solid fa-radar"></i> Ověřit a detekovat peněženku</button>
        </form>
        <div id="verifyResult" style="display:none; margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #e5eae7; border-radius: 8px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"></div>
    </div>

    <!-- Výpis faktur z prohlížeče -->
    <div class="card">
        <div class="card-title">
            <span style="display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-clock-rotate-left" style="color:#748078;"></i> Historie v prohlížeči</span>
            <div style="display:flex; gap:10px;">
                <button class="ghost-btn" onclick="document.getElementById('importFile').click()" style="padding: 6px 10px; font-size:11px;"><i class="fa-solid fa-file-import"></i> Import</button>
                <input type="file" id="importFile" style="display:none" accept=".json" onchange="importData(event)">
                <button class="ghost-btn" onclick="exportData()" style="padding: 6px 10px; font-size:11px;"><i class="fa-solid fa-file-export"></i> Export</button>
                <button class="ghost-btn" onclick="clearHistory()" style="color:#ef4d4d; border-color:#fff0f0; background:#fff0f0; padding:6px 10px; font-size:11px;"><i class="fa-solid fa-trash"></i> Smazat z paměti</button>
            </div>
        </div>
        <div id="invoiceList"></div>
    </div>
</div>

<div class="toast" id="toast"><i class="fa-solid fa-circle-info"></i> <span id="toastMsg"></span></div>

<script>
const STORAGE_KEY = 'url_btc_invoices';
const showToast = (msg) => {
    document.getElementById('toastMsg').innerText = msg;
    const t = document.getElementById('toast');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

const getInvoices = () => JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
const saveInvoices = (data) => localStorage.setItem(STORAGE_KEY, JSON.stringify(data));

// VYTVOŘENÍ FAKTURY
document.getElementById('createForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnCreate');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Zpracovávám...';

    const formData = new URLSearchParams();
    formData.append('api_action', 'create');
    formData.append('wallet', document.getElementById('walletSelect').value);
    formData.append('amount', document.getElementById('amount').value);
    formData.append('description', document.getElementById('desc').value);
    formData.append('order_id', document.getElementById('order_id').value);

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();

        if (data.status === 'ok') {
            const invoices = getInvoices();
            invoices.unshift({
                token: data.token,
                url: data.url,
                amount: data.amount,
                desc: data.desc,
                order_id: data.order_id,
                wallet: data.wallet,
                time: data.time,
                lastStatus: 'unknown'
            });
            saveInvoices(invoices);
            renderInvoices();
            showToast('Faktura vygenerována a uložena.');
            document.getElementById('amount').value = '';
            document.getElementById('desc').value = '';
            document.getElementById('order_id').value = '';
        } else { alert('Chyba serveru: ' + data.message); }
    } catch (err) { alert('Chyba spojení.'); }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-magic"></i> Vygenerovat zabezpečený odkaz';
});

// OVĚŘOVÁNÍ CIZÍCH ODKAZŮ A AUTO-DETEKCE
document.getElementById('verifyForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnVerify');
    const resDiv = document.getElementById('verifyResult');
    const inputVal = document.getElementById('verifyInput').value;

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Ověřuji a hledám peněženku...';
    resDiv.style.display = 'none';

    let token = inputVal;
    if (inputVal.includes('inv=')) {
        const urlParams = new URLSearchParams(inputVal.substring(inputVal.indexOf('?')));
        token = urlParams.get('inv') || inputVal;
    }

    const formData = new URLSearchParams();
    formData.append('api_action', 'check_status');
    formData.append('token', token);

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.status === 'ok') {
            const missingText = data.payment_status === 'underpaid' ? `<div style="color:#d97706; font-weight:700; margin-top:5px;">Chybí doplatit: ${data.missing_amount} BTC</div>` : '';
            resDiv.innerHTML = `
                <div style="margin-bottom:12px;">${getStatusBadge(data.payment_status)}</div>
                <div style="font-size: 13px; color: #17201a; line-height: 1.6;">
                    <strong>Popis:</strong> ${data.desc}<br>
                    <strong>Částka:</strong> ${data.amount} BTC<br>
                    ${data.order_id ? `<strong>Interní ID:</strong> ${data.order_id}<br>` : ''}
                    <strong>Peněženka:</strong> 📁 ${data.wallet}<br>
                    ${missingText}
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="ghost-btn" onclick="saveVerifiedToHistory('${data.token}', '${data.url}', '${data.amount}', '${data.desc}', '${data.order_id}', '${data.wallet}', ${data.time}, '${data.payment_status}')"><i class="fa-solid fa-floppy-disk"></i> Uložit do historie</button>
                    <a href="${data.url}" target="_blank" class="ghost-btn" style="text-decoration:none;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Otevřít fakturu</a>
                </div>
            `;
        } else {
            resDiv.innerHTML = `<span style="color:#ef4d4d; font-weight:600;"><i class="fa-solid fa-circle-xmark"></i> ${data.message}</span>`;
        }
    } catch (err) {
        resDiv.innerHTML = `<span style="color:#ef4d4d; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> Kritická chyba spojení se serverem.</span>`;
    }
    resDiv.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-radar"></i> Ověřit a detekovat peněženku';
});

// ULOŽENÍ OVĚŘENÉ FAKTURY DO HISTORIE
window.saveVerifiedToHistory = function(token, url, amount, desc, order_id, wallet, time, lastStatus) {
    const invoices = getInvoices();
    const exists = invoices.find(i => i.token === token);
    if (!exists) {
        invoices.unshift({ token, url, amount, desc, order_id, wallet, time, lastStatus });
        saveInvoices(invoices);
        renderInvoices();
        showToast('Faktura uložena do historie.');
    } else {
        showToast('Tato faktura již v historii je.');
    }
}

// KONTROLA HISTORICKÝCH FAKTUR
window.checkStatusByToken = async function(token, btnId) {
    const invoices = getInvoices();
    const index = invoices.findIndex(i => i.token === token);
    if (index === -1) return;
    
    const inv = invoices[index];
    const btn = document.getElementById(btnId);
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    const formData = new URLSearchParams();
    formData.append('api_action', 'check_status');
    formData.append('token', inv.token);

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'ok') {
            inv.lastStatus = data.payment_status;
            invoices[index] = inv;
            saveInvoices(invoices);
            renderInvoices();
            showToast('Stav faktury aktualizován.');
        } else { alert('Chyba: ' + data.message); btn.innerHTML = originalText; }
    } catch (err) { alert('Chyba spojení.'); btn.innerHTML = originalText; }
}

window.deleteInvoice = function(index) {
    if(confirm('Opravdu smazat tuto fakturu z historie?')) {
        const invoices = getInvoices();
        invoices.splice(index, 1);
        saveInvoices(invoices);
        renderInvoices();
        showToast('Faktura smazána.');
    }
}

function clearHistory() {
    if(confirm('Tato akce smaže historii lokálních faktur z vašeho prohlížeče. Pokračovat?')) {
        localStorage.removeItem(STORAGE_KEY);
        renderInvoices();
        showToast('Paměť prohlížeče byla vymazána.');
    }
}

function exportData() {
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(localStorage.getItem(STORAGE_KEY) || '[]');
    const a = document.createElement('a');
    a.href = dataStr;
    a.download = "url_invoices_backup.json";
    a.click();
}

function importData(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const imported = JSON.parse(e.target.result);
            if (Array.isArray(imported)) {
                saveInvoices(imported);
                renderInvoices();
                showToast('Záloha faktur byla úspěšně nahrána.');
            } else { alert('Neplatný formát dat v souboru.'); }
        } catch(err) { alert('Nastala chyba při čtení souboru.'); }
    };
    reader.readAsText(file);
    event.target.value = '';
}

function getStatusBadge(status) {
    const sMap = {
        'paid': 'Zaplaceno',
        'paid_late': 'Zaplaceno (Zpožděně)',
        'pending_mempool': 'V Síti',
        'unpaid': 'Nezaplaceno',
        'expired': 'Vypršela',
        'underpaid': 'Nedoplatek'
    };
    return `<span class="status-badge s-${status}">${sMap[status] || 'Neznámý stav'}</span>`;
}

function renderInvoices() {
    const list = document.getElementById('invoiceList');
    const invoices = getInvoices();

    if (invoices.length === 0) {
        list.innerHTML = '<div style="text-align:center; padding:30px; color:#748078;"><i class="fa-solid fa-inbox" style="font-size:30px; margin-bottom:10px; color:#e5eae7;"></i><br>Zatím nemáte uložené žádné URL faktury.</div>';
        return;
    }

    let html = '';
    invoices.forEach((inv, index) => {
        const d = new Date(inv.time * 1000);
        const orderIdHtml = inv.order_id ? ` &nbsp;&bull;&nbsp; <strong style="color:#17201a;">ID: ${inv.order_id}</strong>` : '';
        html += `
            <div class="invoice-item">
                <div class="invoice-header">
                    <div>
                        <strong style="font-size:15px;">${inv.desc}</strong>
                        <div style="font-size:11px; color:#748078; margin-top:4px;">
                            <i class="fa-regular fa-clock"></i> ${d.toLocaleString('cs-CZ')} &nbsp;&bull;&nbsp; 📁 ${inv.wallet}${orderIdHtml}
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div class="invoice-amount">${inv.amount} BTC</div>
                        <div style="margin-top:4px;">${getStatusBadge(inv.lastStatus)}</div>
                    </div>
                </div>
                <div style="font-size:11px; background:#fafcfa; padding:10px; border:1px solid #e5eae7; border-radius:6px; word-break:break-all; font-family:ui-monospace, monospace;">
                    <a href="${inv.url}" target="_blank" style="color:#17201a; text-decoration:underline; font-weight:600;">${inv.url}</a>
                </div>
                <div class="invoice-actions">
                    <button class="ghost-btn" onclick="navigator.clipboard.writeText('${inv.url}'); showToast('URL zkopírována');" style="padding:8px 12px; font-size:12px;"><i class="fa-regular fa-copy"></i> Kopírovat</button>
                    <button class="primary" id="btn-check-${index}" onclick="checkStatusByToken('${inv.token}', 'btn-check-${index}')" style="width:auto; padding:8px 16px; font-size:12px; margin-left:auto;"><i class="fa-solid fa-rotate"></i> Zkontrolovat stav</button>
                    <button class="ghost-btn" onclick="deleteInvoice(${index})" style="padding:8px 12px; font-size:12px; color:#ef4d4d; border-color:#fff0f0; background:#fff0f0;" title="Smazat fakturu"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
        `;
    });
    list.innerHTML = html;
}

renderInvoices();
</script>
</body>
</html>