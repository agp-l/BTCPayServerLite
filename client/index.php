<?php
// client/index.php - Klientský dashboard
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Ochrana - pustíme sem jen přihlášené uživatele
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';
use BtcPayLite\Database;

$userId = $_SESSION['user_id'];
$stores = [];
$invoices = [];
$webhooks = [];
$toastMsg = '';

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    // Zpracování formulářů (Tvorba e-shopu, Webhooky)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        
        // 1. AKCE: Vytvoření nového e-shopu (a peněženky na pozadí)
        if ($_POST['action'] === 'create_store') {
            $storeName = trim($_POST['store_name'] ?? '');
            if (empty($storeName)) $storeName = 'Nový e-shop';

            $storeId = 'store_' . substr(bin2hex(random_bytes(8)), 0, 10);
            $apiKey = bin2hex(random_bytes(16));
            $walletPath = '/opt/btcpay_wallets/' . $storeId . '_wallet';
            
            
            // Fyzické vytvoření peněženky v Linuxu (s naší odladěnou cestou a offline režimem)
            $cmd = "python3 /opt/electrum/run_electrum -D /opt/electrum_config create --offline -w " . escapeshellarg($walletPath) . " 2>&1";
            shell_exec($cmd);

// Nastavíme práva, aby k souboru mohl přistoupit hlavní Electrum démon (ag)
chmod($walletPath, 0664);
            
            $stmt = $db->getPdo()->prepare("INSERT INTO stores (id, name, api_key, wallet_path, user_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$storeId, $storeName, $apiKey, $walletPath, $userId]);
            $toastMsg = "Obchod $storeName byl úspěšně založen!";
        }
        
        // 2. AKCE: Přidání Webhooku
        elseif ($_POST['action'] === 'create_webhook') {
            $storeId = trim($_POST['store_id'] ?? '');
            $url = trim($_POST['url'] ?? '');

            if (!empty($storeId) && !empty($url)) {
                // Bezpečnost: Ověříme, že tento obchod patří tomuto uživateli
                $stmt = $db->getPdo()->prepare("SELECT id FROM stores WHERE id = ? AND user_id = ?");
                $stmt->execute([$storeId, $userId]);
                if ($stmt->fetch()) {
                    $whId = 'wh_' . substr(bin2hex(random_bytes(8)), 0, 10);
                    $whSecret = bin2hex(random_bytes(16));
                    $insStmt = $db->getPdo()->prepare("INSERT INTO webhooks (id, store_id, url, secret) VALUES (?, ?, ?, ?)");
                    $insStmt->execute([$whId, $storeId, $url, $whSecret]);
                    $toastMsg = "Webhook byl úspěšně přidán!";
                } else {
                    $toastMsg = "Chyba: Neoprávněný přístup k obchodu.";
                }
            }
        } 
        
        // 3. AKCE: Smazání Webhooku
        elseif ($_POST['action'] === 'delete_webhook') {
            $whId = trim($_POST['webhook_id'] ?? '');
            
            $stmt = $db->getPdo()->prepare("
                SELECT w.id FROM webhooks w 
                JOIN stores s ON w.store_id = s.id 
                WHERE w.id = ? AND s.user_id = ?
            ");
            $stmt->execute([$whId, $userId]);
            if ($stmt->fetch()) {
                $delStmt = $db->getPdo()->prepare("DELETE FROM webhooks WHERE id = ?");
                $delStmt->execute([$whId]);
                $toastMsg = "Webhook byl smazán.";
            } else {
                $toastMsg = "Chyba: Nemáte oprávnění ke smazání tohoto webhooku.";
            }
        }
    }

    // Načtení klientských statistik
    $statStmt = $db->getPdo()->prepare("
        SELECT 
            (SELECT COUNT(*) FROM stores WHERE user_id = ?) as total_stores,
            (SELECT COUNT(*) FROM invoices i JOIN stores s ON i.store_id = s.id WHERE s.user_id = ?) as total_invoices,
            (SELECT COUNT(*) FROM invoices i JOIN stores s ON i.store_id = s.id WHERE s.user_id = ? AND i.status = 'Settled') as paid_invoices
    ");
    $statStmt->execute([$userId, $userId, $userId]);
    $clientStats = $statStmt->fetch();

    // Načtení e-shopů
    $stmt = $db->getPdo()->prepare("SELECT * FROM stores WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$userId]);
    $stores = $stmt->fetchAll();

    if (!empty($stores)) {
        $storeIds = array_column($stores, 'id');
        $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
        
        // Načtení faktur
        $invStmt = $db->getPdo()->prepare("
            SELECT i.*, s.name as store_name 
            FROM invoices i 
            JOIN stores s ON i.store_id = s.id 
            WHERE i.store_id IN ($placeholders) 
            ORDER BY i.created_at DESC 
            LIMIT 30
        ");
        $invStmt->execute($storeIds);
        $invoices = $invStmt->fetchAll();

        // Načtení webhooků
        $whStmt = $db->getPdo()->prepare("
            SELECT w.*, s.name as store_name 
            FROM webhooks w 
            JOIN stores s ON w.store_id = s.id 
            WHERE w.store_id IN ($placeholders) 
            ORDER BY w.id DESC
        ");
        $whStmt->execute($storeIds);
        $webhooks = $whStmt->fetchAll();
    }
} catch (Exception $e) {
    die("Chyba při načítání dat: " . htmlspecialchars($e->getMessage()));
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Můj účet - BTCPay Lite</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    body { 
      margin: 0; color: #17201a; font-family: Inter, sans-serif; background-color: #fafcfa; 
      background-image: radial-gradient(circle at 50% 0%, rgba(47, 211, 90, 0.12) 0%, transparent 60%), linear-gradient(to right, rgba(229, 234, 231, 0.7) 1px, transparent 1px), linear-gradient(to bottom, rgba(229, 234, 231, 0.7) 1px, transparent 1px);
      background-size: 100% 100%, 24px 24px, 24px 24px; background-attachment: fixed; padding: 40px 20px; min-height: 100vh; 
    }
    .container { max-width: 1000px; margin: 0 auto; }
    
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
    h1 { font-size: 26px; margin: 0; display: flex; align-items: center; gap: 10px; }
    .ghost-btn { border: 1px solid #e5eae7; background: #fff; border-radius: 11px; padding: 10px 16px; color: #17201a; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; cursor: pointer; }
    .ghost-btn:hover { border-color: #2fd35a; color: #2fd35a; }
    .ghost-btn.danger:hover { border-color: #ef4d4d; color: #ef4d4d; }

    /* Statistiky */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
    .stat-card { background: #fff; border: 1px solid #e5eae7; border-radius: 16px; padding: 20px; box-shadow: 0 4px 15px rgba(20,45,28,.04); }
    .stat-label { font-size: 12px; font-weight: 700; color: #748078; text-transform: uppercase; margin-bottom: 5px; }
    .stat-value { font-size: 24px; font-weight: 800; color: #17201a; }

    .card { background: #fff; border: 1px solid #e5eae7; border-radius: 18px; padding: 30px; box-shadow: 0 8px 30px rgba(20,45,28,.06); margin-bottom: 24px; }
    .key-box { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 8px; padding: 10px 15px; font-family: monospace; font-size: 13px; color: #17201a; margin-top: 5px; margin-bottom: 15px; word-break: break-all; display: flex; justify-content: space-between; align-items: center; }
    .code-label { font-size: 11px; font-weight: 700; color: #748078; text-transform: uppercase; margin-bottom: 3px; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
    th { text-align: left; padding: 12px 10px; color: #748078; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e5eae7; }
    td { padding: 14px 10px; border-bottom: 1px solid #f0f4f1; vertical-align: middle; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-weight: 700; font-size: 11px; }
    .badge-New { background: #fef3c7; color: #d97706; }
    .badge-Processing { background: #e0f2fe; color: #0284c7; }
    .badge-Settled { background: #eafbef; color: #13aa3d; }
    .badge-Expired { background: #fee2e2; color: #ef4d4d; }

    input, select { width: 100%; border: 1px solid #e5eae7; border-radius: 10px; padding: 11px; font: inherit; transition: 0.2s; background: #fff; margin-bottom: 15px; }
    input:focus, select:focus { outline: none; border-color: #2fd35a; box-shadow: inset 0 0 0 1px #2fd35a; }
    .primary-btn { border: 0; background: #2fd35a; color: #fff; border-radius: 10px; padding: 11px 20px; font-weight: 700; cursor: pointer; font-size: 13px; transition: 0.2s; }
    .primary-btn:hover { background: #20b948; }

    .wh-item { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 12px; padding: 20px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
    .wh-info { flex: 1; min-width: 0; }
    
    .toast { position: fixed; right: 25px; bottom: 25px; background: #17201a; color: #fff; padding: 12px 16px; border-radius: 10px; font-weight: 600; opacity: 0; transform: translateY(10px); transition: 0.3s; z-index: 1000; }
    .toast.show { opacity: 1; transform: translateY(0); }
  </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1><i class="fa-solid fa-bolt" style="color: #2fd35a;"></i> Klientský panel</h1>
        <div>
            <span style="font-size: 13px; font-weight: 600; color: #748078; margin-right: 15px;"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($_SESSION['email']); ?></span>
            <a href="../login.php?logout=1" class="ghost-btn" onclick="<?php if(isset($_GET['logout'])) { session_destroy(); header('Location: ../login.php'); exit; } ?>"><i class="fa-solid fa-right-from-bracket"></i> Odhlásit se</a>
        </div>
    </div>

    <!-- Statistiky klienta -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Aktivní E-shopy</div>
            <div class="stat-value"><?php echo $clientStats['total_stores'] ?? 0; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Vygenerováno faktur</div>
            <div class="stat-value"><?php echo $clientStats['total_invoices'] ?? 0; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Úspěšně zaplaceno</div>
            <div class="stat-value" style="color: #13aa3d;"><?php echo $clientStats['paid_invoices'] ?? 0; ?></div>
        </div>
    </div>

    <!-- E-shopy a API Klíče -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; font-size: 18px;"><i class="fa-solid fa-shop" style="color:#748078;"></i> Moje e-shopy (API Klíče)</h2>
            <button onclick="document.getElementById('newStoreForm').style.display='block'" class="ghost-btn"><i class="fa-solid fa-plus"></i> Nový e-shop</button>
        </div>

        <!-- Formulář pro nový e-shop (skrytý) -->
        <div id="newStoreForm" style="display: none; background: #f9fafa; padding: 20px; border-radius: 12px; border: 1px solid #e5eae7; margin-bottom: 25px;">
            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Založit nový e-shop / projekt</h3>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="create_store">
                <div class="code-label">Název projektu (např. Alza.cz)</div>
                <input type="text" name="store_name" placeholder="Název vašeho e-shopu" required>
                <button type="submit" class="primary-btn"><i class="fa-solid fa-check"></i> Vytvořit e-shop a peněženku</button>
                <button type="button" class="ghost-btn" style="margin-left: 10px;" onclick="document.getElementById('newStoreForm').style.display='none'">Zrušit</button>
            </form>
        </div>

        <?php if (empty($stores)): ?>
            <p style="color: #748078;">Zatím nemáte vytvořen žádný e-shop.</p>
        <?php else: ?>
            <div style="display: grid; gap: 20px;">
                <?php foreach ($stores as $store): ?>
                    <div style="border: 1px solid #e5eae7; border-radius: 12px; padding: 20px;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #17201a;"><?php echo htmlspecialchars($store['name']); ?></h3>
                        
                        <div class="code-label">Store ID (ID Obchodu):</div>
                        <div class="key-box">
                            <span><?php echo htmlspecialchars($store['id']); ?></span>
                        </div>
                        
                        <div class="code-label">API Klíč (Zadejte do WooCommerce):</div>
                        <div class="key-box" style="margin-bottom: 0;">
                            <span><?php echo htmlspecialchars($store['api_key']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Webhooky -->
    <div class="card">
        <h2 style="margin: 0 0 20px 0; font-size: 18px;"><i class="fa-solid fa-satellite-dish" style="color:#748078;"></i> Správa Webhooků</h2>
        
        <?php if (!empty($stores)): ?>
        <div style="background: #f9fafa; padding: 20px; border-radius: 12px; border: 1px solid #e5eae7; margin-bottom: 25px;">
            <h3 style="margin: 0 0 15px 0; font-size: 14px;">Přidat nový Webhook</h3>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="create_webhook">
                <div class="code-label">Vyberte obchod</div>
                <select name="store_id" required>
                    <option value="">-- Vyberte obchod --</option>
                    <?php foreach ($stores as $s): ?>
                        <option value="<?php echo htmlspecialchars($s['id']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <div class="code-label">URL adresa notifikace</div>
                <input type="url" name="url" placeholder="https://muj-eshop.cz/wc-api/webhook/" required>
                
                <button type="submit" class="primary-btn"><i class="fa-solid fa-plus"></i> Uložit Webhook</button>
            </form>
        </div>
        <?php else: ?>
            <p style="color: #748078; font-size: 13px;">Pro vytvoření webhooku si nejprve musíte založit e-shop.</p>
        <?php endif; ?>

        <h3 style="margin: 0 0 15px 0; font-size: 14px;">Aktivní Webhooky</h3>
        <?php if (empty($webhooks)): ?>
            <p style="color: #748078; font-size: 13px;">Zatím nemáte vytvořené žádné webhooky.</p>
        <?php else: ?>
            <?php foreach ($webhooks as $w): ?>
                <div class="wh-item">
                    <div class="wh-info">
                        <div class="code-label">Přiřazeno k obchodu: <?php echo htmlspecialchars($w['store_name']); ?></div>
                        <h4 style="margin: 0 0 10px 0; font-size: 14px; word-break: break-all;"><?php echo htmlspecialchars($w['url']); ?></h4>
                        
                        <div class="code-label">Secret klíč (Pro ověření podpisu e-shopem):</div>
                        <div class="key-box" style="margin-bottom: 0; padding: 6px 10px;"><?php echo htmlspecialchars($w['secret']); ?></div>
                    </div>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Opravdu smazat tento webhook?');">
                        <input type="hidden" name="action" value="delete_webhook">
                        <input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars($w['id']); ?>">
                        <button type="submit" class="ghost-btn danger"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Faktury -->
    <div class="card">
        <h2 style="margin: 0 0 20px 0; font-size: 18px;"><i class="fa-solid fa-file-invoice" style="color:#748078;"></i> Přijaté platby a faktury</h2>
        <?php if (empty($invoices)): ?>
            <p style="color: #748078; font-size: 14px;">Zatím nemáte žádné transakce.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>E-shop</th>
                        <th>ID Faktury</th>
                        <th>Částka</th>
                        <th>Stav</th>
                        <th>Vytvořeno</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <?php 
                            $dateStr = is_numeric($inv['created_at']) ? date('d.m.Y H:i', (int)$inv['created_at']) : date('d.m.Y H:i', strtotime($inv['created_at']));
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($inv['store_name']); ?></strong></td>
                            <td style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($inv['id']); ?></td>
                            <td><strong><?php echo htmlspecialchars($inv['amount']); ?> BTC</strong></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars($inv['status']); ?>"><?php echo htmlspecialchars($inv['status']); ?></span></td>
                            <td style="color: #748078;"><?php echo $dateStr; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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