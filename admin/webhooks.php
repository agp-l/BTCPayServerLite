<?php
// admin/webhooks.php
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;
use Exception;

$toastMsg = '';

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    // Zpracování formulářů (Přidání a Smazání webhooku)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'create') {
            $storeId = trim($_POST['store_id'] ?? '');
            $url = trim($_POST['url'] ?? '');

            if (empty($storeId) || empty($url)) {
                throw new Exception("Vyber obchod a zadej URL webhooku.");
            }

            $whId = 'wh_' . substr(bin2hex(random_bytes(8)), 0, 10);
            $whSecret = bin2hex(random_bytes(16));

            $stmt = $db->getPdo()->prepare("INSERT INTO webhooks (id, store_id, url, secret) VALUES (?, ?, ?, ?)");
            $stmt->execute([$whId, $storeId, $url, $whSecret]);
            $toastMsg = "Webhook byl úspěšně přidán!";
        } elseif ($_POST['action'] === 'delete') {
            $whId = trim($_POST['webhook_id'] ?? '');
            $stmt = $db->getPdo()->prepare("DELETE FROM webhooks WHERE id = ?");
            $stmt->execute([$whId]);
            $toastMsg = "Webhook byl smazán.";
        }
    }

    // Načtení dat pro výpis
    $stores = $db->getPdo()->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll();
    $webhooks = $db->getPdo()->query("
        SELECT w.*, s.name as store_name 
        FROM webhooks w 
        LEFT JOIN stores s ON w.store_id = s.id 
        ORDER BY w.id DESC
    ")->fetchAll();

} catch (Exception $e) {
    $toastMsg = "Chyba: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Správa Webhooků</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; color: #17201a; font-family: Inter, sans-serif; background: #f0f4f1; padding: 40px 20px; min-height: 100vh; }
    .container { max-width: 800px; margin: 0 auto; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    h1 { font-size: 26px; margin: 0; }
    .ghost-btn { border: 1px solid #e5eae7; background: #fff; border-radius: 11px; padding: 10px 14px; color: #17201a; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; cursor: pointer;}
    .ghost-btn:hover { border-color: #2fd35a; color: #2fd35a; }
    .ghost-btn.danger:hover { border-color: #ef4d4d; color: #ef4d4d; }
    .card { background: #fff; border: 1px solid #e5eae7; border-radius: 18px; padding: 30px; box-shadow: 0 8px 30px rgba(20,45,28,.06); margin-bottom: 24px; }
    .field { margin-bottom: 18px; }
    label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 7px; color: #748078; text-transform: uppercase; }
    input, select { width: 100%; border: 1px solid #e5eae7; border-radius: 10px; padding: 13px; font: inherit; transition: 0.2s; background: #fff; }
    input:focus, select:focus { outline: none; border-color: #2fd35a; box-shadow: inset 0 0 0 1px #2fd35a; }
    .primary { width: 100%; border: 0; background: #2fd35a; color: #fff; border-radius: 10px; padding: 13px; font-weight: 700; cursor: pointer; font-size: 14px; transition: 0.2s; }
    .primary:hover { background: #20b948; }
    .wh-item { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 12px; padding: 20px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
    .wh-info { flex: 1; min-width: 0; }
    .wh-url { font-weight: 600; margin: 0 0 10px 0; word-break: break-all; }
    .code-box { background: #fff; border: 1px solid #e5eae7; padding: 10px; border-radius: 8px; font-family: monospace; font-size: 12px; word-break: break-all; margin-bottom: 5px; }
    .code-label { font-size: 11px; font-weight: 700; color: #748078; margin-bottom: 3px; text-transform: uppercase; }
    .toast { position: fixed; right: 25px; bottom: 25px; background: #17201a; color: #fff; padding: 12px 16px; border-radius: 10px; font-weight: 600; opacity: 0; transform: translateY(10px); transition: 0.3s; }
    .toast.show { opacity: 1; transform: translateY(0); }
  </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1>Správa Webhooků</h1>
        <div>
            <a href="stores.php" class="ghost-btn"><i class="fa-solid fa-shop"></i> E-shopy</a>
            <a href="invoices.php" class="ghost-btn"><i class="fa-solid fa-file-invoice"></i> Faktury</a>
        </div>
    </div>

    <div class="card">
        <h2 style="margin: 0 0 20px 0; font-size: 18px;"><i class="fa-solid fa-satellite-dish" style="color:#20b948;"></i> Přidat Webhook ručně</h2>
        <p style="font-size: 13px; color: #748078; margin-top: -10px; margin-bottom: 20px;">Poznámka: Většina e-shopů si webhook vytvoří automaticky přes API. Zde to můžeš udělat ručně pro testování.</p>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="field">
                <label>Přiřadit k obchodu</label>
                <select name="store_id" required>
                    <option value="">-- Vyber obchod --</option>
                    <?php foreach ($stores as $s): ?>
                        <option value="<?php echo htmlspecialchars($s['id']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>URL adresa (Kam odeslat notifikaci o platbě)</label>
                <input type="url" name="url" placeholder="https://tvuj-eshop.cz/wc-api/WC_Gateway_BtcPay/" required>
            </div>
            <button type="submit" class="primary"><i class="fa-solid fa-plus"></i> Vytvořit Webhook</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin: 0 0 20px 0; font-size: 18px;"><i class="fa-solid fa-list" style="color:#748078;"></i> Aktivní Webhooky</h2>
        
        <?php if (empty($webhooks)): ?>
            <p style="color: #748078; font-size: 14px;">Zatím nemáš žádné webhooky.</p>
        <?php else: ?>
            <?php foreach ($webhooks as $w): ?>
                <div class="wh-item">
                    <div class="wh-info">
                        <div class="code-label">Obchod: <?php echo htmlspecialchars($w['store_name'] ?? 'Neznámý obchod'); ?></div>
                        <h3 class="wh-url"><?php echo htmlspecialchars($w['url']); ?></h3>
                        
                        <div class="code-label">Webhook Secret (Pro ověření podpisů):</div>
                        <div class="code-box"><?php echo htmlspecialchars($w['secret']); ?></div>
                        
                        <div class="code-label">ID Webhooku:</div>
                        <div style="font-size: 12px; color: #748078;"><?php echo htmlspecialchars($w['id']); ?></div>
                    </div>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Opravdu smazat tento webhook?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars($w['id']); ?>">
                        <button type="submit" class="ghost-btn danger"><i class="fa-solid fa-trash"></i> Smazat</button>
                    </form>
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