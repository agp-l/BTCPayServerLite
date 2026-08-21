<?php
// admin/stores.php
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;

$toastMsg = '';

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    // Zpracování formuláře pro nový obchod
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
        $name = trim($_POST['store_name'] ?? '');
        $walletPath = trim($_POST['wallet_path'] ?? '');

        if (empty($name) || empty($walletPath)) {
            throw new Exception("Vyplň název obchodu i cestu k peněžence.");
        }

        // Generování unikátních identifikátorů
        $storeId = 'store_' . substr(bin2hex(random_bytes(8)), 0, 10);
        $apiKey = 'sk_' . bin2hex(random_bytes(16)); // Bezpečný API klíč

        $stmt = $db->getPdo()->prepare("INSERT INTO stores (id, name, api_key, wallet_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$storeId, $name, $apiKey, $walletPath]);

        $toastMsg = "Obchod '$name' byl úspěšně vytvořen!";
    }

    // Načtení všech obchodů pro výpis
    $stores = $db->getPdo()->query("SELECT * FROM stores ORDER BY name ASC")->fetchAll();

} catch (Exception $e) {
    $toastMsg = "Chyba: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Správa E-shopů (Stores)</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; color: #17201a; font-family: Inter, sans-serif; background: #f0f4f1; padding: 40px 20px; min-height: 100vh; }
    .container { max-width: 800px; margin: 0 auto; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    h1 { font-size: 26px; margin: 0; }
    .ghost-btn { border: 1px solid #e5eae7; background: #fff; border-radius: 11px; padding: 10px 14px; color: #17201a; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
    .ghost-btn:hover { border-color: #2fd35a; color: #2fd35a; }
    .card { background: #fff; border: 1px solid #e5eae7; border-radius: 18px; padding: 30px; box-shadow: 0 8px 30px rgba(20,45,28,.06); margin-bottom: 24px; }
    .field { margin-bottom: 18px; }
    label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 7px; color: #748078; text-transform: uppercase; }
    input { width: 100%; border: 1px solid #e5eae7; border-radius: 10px; padding: 13px; font: inherit; transition: 0.2s; }
    input:focus { outline: none; border-color: #2fd35a; box-shadow: inset 0 0 0 1px #2fd35a; }
    .primary { width: 100%; border: 0; background: #2fd35a; color: #fff; border-radius: 10px; padding: 13px; font-weight: 700; cursor: pointer; font-size: 14px; transition: 0.2s; }
    .primary:hover { background: #20b948; }
    .store-item { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 12px; padding: 20px; margin-bottom: 15px; }
    .store-title { font-size: 16px; font-weight: 700; margin: 0 0 15px 0; display: flex; align-items: center; gap: 8px; }
    .code-box { background: #fff; border: 1px solid #e5eae7; padding: 10px; border-radius: 8px; font-family: monospace; font-size: 12px; word-break: break-all; margin-bottom: 10px; }
    .code-label { font-size: 11px; font-weight: 700; color: #748078; margin-bottom: 3px; text-transform: uppercase; }
    .toast { position: fixed; right: 25px; bottom: 25px; background: #17201a; color: #fff; padding: 12px 16px; border-radius: 10px; font-weight: 600; opacity: 0; transform: translateY(10px); transition: 0.3s; }
    .toast.show { opacity: 1; transform: translateY(0); }
  </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1>Správa obchodů (Stores)</h1>
        <div>
            <a href="invoices.php" class="ghost-btn"><i class="fa-solid fa-file-invoice"></i> Faktury</a>
            <a href="index.php" class="ghost-btn"><i class="fa-solid fa-wallet"></i> Peněženka</a>
        </div>
    </div>

    <div class="card">
        <h2 style="margin: 0 0 20px 0; font-size: 18px;"><i class="fa-solid fa-shop" style="color:#20b948;"></i> Přidat nový e-shop</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="field">
                <label>Název obchodu (např. Můj E-shop)</label>
                <input type="text" name="store_name" required>
            </div>
            <div class="field">
                <label>Absolutní cesta k souboru peněženky pro tento obchod</label>
                <input type="text" name="wallet_path" placeholder="/home/ag/.../wallets/novy_obchod_wallet" required>
            </div>
            <button type="submit" class="primary"><i class="fa-solid fa-plus"></i> Vytvořit Store & Vygenerovat API Klíč</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin: 0 0 20px 0; font-size: 18px;"><i class="fa-solid fa-list" style="color:#748078;"></i> Moje e-shopy</h2>
        
        <?php if (empty($stores)): ?>
            <p style="color: #748078; font-size: 14px;">Zatím zde nemáš žádné obchody.</p>
        <?php else: ?>
            <?php foreach ($stores as $s): ?>
                <div class="store-item">
                    <h3 class="store-title"><i class="fa-solid fa-cart-shopping"></i> <?php echo htmlspecialchars($s['name']); ?></h3>
                    
                    <div class="code-label">Store ID (Zadej do pluginu):</div>
                    <div class="code-box"><?php echo htmlspecialchars($s['id']); ?></div>
                    
                    <div class="code-label">API Klíč (Zadej do pluginu):</div>
                    <div class="code-box"><?php echo htmlspecialchars($s['api_key']); ?></div>
                    
                    <div class="code-label">Peněženka (Kam chodí BTC):</div>
                    <div class="code-box" style="color:#748078; border-style: dashed;"><?php echo htmlspecialchars($s['wallet_path']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="toast" id="toast"><i class="fa-solid fa-circle-info"></i> <span id="toastMsg"><?php echo htmlspecialchars($toastMsg); ?></span></div>
<script>
  const toastEl = document.getElementById('toast');
  if (document.getElementById('toastMsg').innerText.trim() !== '') {
      toastEl.classList.add('show');
      setTimeout(() => toastEl.classList.remove('show'), 4000);
  }
</script>
</body>
</html>