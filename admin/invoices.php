<?php
// admin/invoices.php
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Načteme Composer autoloader (jsme v /admin, takže o složku výš)
require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

// Moderní volání tříd
use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcInvoiceManager;
use Exception;


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
        die("Chyba: V databázi chybí obchod. Vytvoř ho přes phpMyAdmin v tabulce 'stores'.");
    }
    
    $storeId = $store['id'];
    $wallet->loadWallet($store['wallet_path']);
    
    // 2. Správce faktur (nyní s databází)
    $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], $db);

    // 3. Zpracování formuláře
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        
        if ($_POST['action'] === 'create') {
            $amount = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
            $desc = trim($_POST['description'] ?? '');
            $orderId = trim($_POST['order_id'] ?? '');

            if ($amount <= 0 || empty($desc)) {
                throw new Exception("Vyplň platnou částku a popis faktury.");
            }

            // Vytvoření DATABÁZOVÉ faktury
            $metadata = ['orderId' => $orderId, 'itemDesc' => $desc];
            $inv = $invoiceManager->createDatabaseInvoice($storeId, $amount, $metadata, 15);
            
            // Vygenerování URL (o složku výš, protože jsme v /admin)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $baseDir = dirname(dirname($_SERVER['SCRIPT_NAME'])); // Odstraní '/admin'
            if ($baseDir === '/' || $baseDir === '\\') $baseDir = '';
            
            // Nový čistý formát URL s databázovým ID
            $newInvoiceUrl = $protocol . $host . $baseDir . '/checkout/pay.php?id=' . $inv['id'];

            // Uložení do session pro výpis
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
} catch (Exception $e) {
    $toastMsg = "Chyba: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Správa faktur</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; color: #17201a; font-family: Inter, system-ui, sans-serif; background: radial-gradient(circle at 50% 0%, rgba(47,211,90,.055), transparent 34rem), radial-gradient(circle, rgba(23,32,26,.055) 1px, transparent 1px), #fff; background-size: auto, 28px 28px, auto; background-position: center top; padding: 40px 20px; min-height: 100vh; }
    .container { max-width: 700px; margin: 0 auto; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; }
    h1 { font-size: 26px; margin: 0; letter-spacing: -.5px; }
    .ghost-btn { border: 1px solid #e5eae7; background: #fff; border-radius: 11px; padding: 10px 14px; color: #17201a; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; cursor:pointer; }
    .ghost-btn:hover { border-color: #2fd35a; color: #2fd35a; }
    .card { background: #fff; border: 1px solid #e5eae7; border-radius: 18px; padding: 30px; box-shadow: 0 8px 30px rgba(20,45,28,.06); margin-bottom: 24px; }
    .card-title { font-size: 18px; font-weight: 700; margin: 0 0 20px 0; display: flex; align-items: center; gap: 8px; }
    .field { margin-bottom: 18px; }
    label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 7px; color: #748078; text-transform: uppercase; }
    .input-wrap { display: flex; border: 1px solid #e5eae7; border-radius: 10px; overflow: hidden; background: #fff; transition: 0.2s; }
    .input-wrap:focus-within { box-shadow: inset 0 0 0 1px #2fd35a; border-color: #2fd35a; }
    input { width: 100%; border: 0; outline: 0; padding: 13px; font: inherit; background: transparent; }
    .unit { padding: 13px 15px; font-weight: 700; color: #748078; border-left: 1px solid #e5eae7; background: #f9fafa; }
    .primary { width: 100%; border: 0; background: #2fd35a; color: #fff; border-radius: 10px; padding: 13px; font-weight: 700; cursor: pointer; font-size: 14px; }
    .primary:hover { background: #20b948; }
    .result-box { background: #eafbef; border: 1px solid #2fd35a; border-radius: 10px; padding: 15px; margin-top: 20px; font-size: 13px; }
    .result-box a { color: #20b948; font-weight: 600; word-break: break-all; text-decoration: none; display: block; margin-top: 5px; }
    .invoice-item { padding: 15px 0; border-bottom: 1px solid #e5eae7; display: flex; justify-content: space-between; align-items: center; gap: 15px; }
    .invoice-item:last-child { border-bottom: 0; padding-bottom: 0; }
    .invoice-amount { font-family: ui-monospace, monospace; font-weight: 700; color: #20b948; font-size: 13px; }
    .toast { position: fixed; right: 25px; bottom: 25px; background: #17201a; color: #fff; padding: 12px 16px; border-radius: 10px; font-weight: 600; opacity: 0; transform: translateY(10px); transition: 0.3s; }
    .toast.show { opacity: 1; transform: translateY(0); }
  </style>
</head>
<body>
<div class="container">
    
    <div class="topbar">
        <h1>Generátor faktur (MySQL)</h1>
        <a href="index.php" class="ghost-btn"><i class="fa-solid fa-wallet"></i> Zpět do peněženky</a>
    </div>

    <div class="card">
        <h2 class="card-title"><i class="fa-solid fa-file-invoice-dollar" style="color:#20b948;"></i> Vystavit fakturu do DB</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="field">
                <label>Částka k zaplacení</label>
                <div class="input-wrap">
                    <input type="text" name="amount" placeholder="0.00100000" required>
                    <div class="unit">BTC</div>
                </div>
            </div>
            <div class="field">
                <label>Popis / Název položky</label>
                <div class="input-wrap">
                    <input type="text" name="description" placeholder="Např. Konzultace" required>
                </div>
            </div>
            <div class="field">
                <label>Interní ID objednávky (volitelné)</label>
                <div class="input-wrap">
                    <input type="text" name="order_id" placeholder="Např. ORD-2026-001">
                </div>
            </div>
            <button type="submit" class="primary"><i class="fa-solid fa-plus"></i> Uložit a vygenerovat odkaz</button>
        </form>

        <?php if ($newInvoiceUrl): ?>
            <div class="result-box">
                <div style="color: #17201a; font-weight: 700; margin-bottom: 5px;"><i class="fa-solid fa-link"></i> Odkaz pro zákazníka:</div>
                <a href="<?php echo htmlspecialchars($newInvoiceUrl); ?>" target="_blank"><?php echo htmlspecialchars($newInvoiceUrl); ?></a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['created_invoices'])): ?>
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 class="card-title" style="margin: 0;"><i class="fa-solid fa-clock-rotate-left" style="color:#748078;"></i> Nedávno vytvořené</h2>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="clear_history">
                <button type="submit" class="ghost-btn" style="padding: 6px 10px; font-size: 11px; color: #ef4d4d; background: #fff0f0; border: none;"><i class="fa-solid fa-trash"></i> Smazat náhled</button>
            </form>
        </div>
        <div>
            <?php foreach (array_reverse($_SESSION['created_invoices']) as $inv): ?>
                <div class="invoice-item">
                    <div>
                        <strong><?php echo htmlspecialchars($inv['desc']); ?></strong> <span class="invoice-amount"><?php echo $inv['amount']; ?> BTC</span><br>
                        <small style="color:#748078;"><i class="fa-regular fa-clock"></i> <?php echo date('H:i:s j.n.Y', $inv['time']); ?></small>
                    </div>
                    <a href="<?php echo htmlspecialchars($inv['url']); ?>" target="_blank" class="ghost-btn" style="padding: 6px 12px; font-size: 12px;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Otevřít</a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="toast" id="toast"><i class="fa-solid fa-circle-info"></i> <span id="toastMsg"><?php echo htmlspecialchars($toastMsg); ?></span></div>
<script>
  const toastEl = document.getElementById('toast');
  if (document.getElementById('toastMsg').innerText.trim() !== '') {
      toastEl.classList.add('show');
      setTimeout(() => toastEl.classList.remove('show'), 3000);
  }
</script>
</body>
</html>