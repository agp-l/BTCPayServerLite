<?php
// admin/index.php - Hlavní Dashboard BTCPay Lite
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);

    // Statistiky pro dashboard
    $totalStores = $db->getPdo()->query("SELECT COUNT(*) FROM stores")->fetchColumn();
    $totalInvoices = $db->getPdo()->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
    $settledInvoices = $db->getPdo()->query("SELECT COUNT(*) FROM invoices WHERE status = 'Settled'")->fetchColumn();
    $totalBtcVolume = $db->getPdo()->query("SELECT SUM(amount) FROM invoices WHERE status = 'Settled'")->fetchColumn() ?? 0;

    // Načtení posledních faktur s názvem obchodu
    $invoices = $db->getPdo()->query("
        SELECT i.*, s.name as store_name 
        FROM invoices i 
        LEFT JOIN stores s ON i.store_id = s.id 
        ORDER BY i.created_at DESC 
        LIMIT 20
    ")->fetchAll();

} catch (Exception $e) {
    die("Chyba při načítání dashboardu: " . htmlspecialchars($e->getMessage()));
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BTCPay Lite - Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    
    /* Věrná kopie pozadí z tvé peněženky (šedá mřížka + zelená zář) */
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
      padding: 40px 20px; 
      min-height: 100vh; 
    }
    
    .container { max-width: 1000px; margin: 0 auto; }
    
    /* Topbar & Navigace */
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
    h1 { font-size: 26px; margin: 0; display: flex; align-items: center; gap: 10px; }
    .nav-links { display: flex; gap: 10px; flex-wrap: wrap; }
    .ghost-btn { border: 1px solid #e5eae7; background: #fff; border-radius: 11px; padding: 10px 16px; color: #17201a; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .ghost-btn:hover { border-color: #2fd35a; color: #2fd35a; }
    .ghost-btn.active { background: #17201a; color: #fff; border-color: #17201a; }

    /* Kartičky se statistikami */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
    .stat-card { background: #fff; border: 1px solid #e5eae7; border-radius: 16px; padding: 20px; box-shadow: 0 4px 15px rgba(20,45,28,.04); }
    .stat-label { font-size: 12px; font-weight: 700; color: #748078; text-transform: uppercase; margin-bottom: 5px; }
    .stat-value { font-size: 24px; font-weight: 800; color: #17201a; }

    /* Tabulka faktur */
    .card { background: #fff; border: 1px solid #e5eae7; border-radius: 18px; padding: 30px; box-shadow: 0 8px 30px rgba(20,45,28,.06); }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
    th { text-align: left; padding: 12px 10px; color: #748078; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e5eae7; }
    td { padding: 14px 10px; border-bottom: 1px solid #f0f4f1; vertical-align: middle; }
    tr:last-child td { border-bottom: 0; }
    
    .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-weight: 700; font-size: 11px; }
    .badge-New { background: #fef3c7; color: #d97706; }
    .badge-Processing { background: #e0f2fe; color: #0284c7; }
    .badge-Settled { background: #eafbef; color: #13aa3d; }
    .badge-Expired { background: #fee2e2; color: #ef4d4d; }
    
    .code { font-family: monospace; font-size: 12px; }
    .action-link { color: #20b948; text-decoration: none; font-weight: 600; }
    .action-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1><i class="fa-solid fa-bolt" style="color: #2fd35a;"></i> BTCPay Lite</h1>
        <div class="nav-links">
            <a href="index.php" class="ghost-btn active"><i class="fa-solid fa-chart-pie"></i> Přehled</a>
            <a href="wallet.php" class="ghost-btn"><i class="fa-solid fa-wallet"></i> Peněženka</a>
            <a href="stores.php" class="ghost-btn"><i class="fa-solid fa-shop"></i> E-shopy</a>
            <a href="invoices.php" class="ghost-btn"><i class="fa-solid fa-file-invoice"></i> Faktury</a>
            <a href="webhooks.php" class="ghost-btn"><i class="fa-solid fa-satellite-dish"></i> Webhooky</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Aktivní E-shopy</div>
            <div class="stat-value"><?php echo $totalStores; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Celkem Faktur</div>
            <div class="stat-value"><?php echo $totalInvoices; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Zaplaceno Faktur</div>
            <div class="stat-value" style="color: #13aa3d;"><?php echo $settledInvoices; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Přijato Celkem</div>
            <div class="stat-value"><?php echo number_format((float)$totalBtcVolume, 6, '.', ''); ?> BTC</div>
        </div>
    </div>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 18px;"><i class="fa-solid fa-clock-rotate-left" style="color:#748078;"></i> Poslední vygenerované faktury</h2>
            <a href="invoices.php" class="action-link">Zobrazit všechny &rarr;</a>
        </div>

        <?php if (empty($invoices)): ?>
            <p style="color: #748078; font-size: 14px; margin-top: 20px;">Zatím nebyly vygenerovány žádné faktury.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID Faktury</th>
                        <th>E-shop</th>
                        <th>Částka</th>
                        <th>Stav</th>
                        <th>Vytvořeno</th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <?php 
                            $createdRaw = $inv['created_at'] ?? null;
                            if (is_numeric($createdRaw)) {
                                $dateFormatted = date('d.m.Y H:i', (int)$createdRaw);
                            } elseif (!empty($createdRaw)) {
                                $dateFormatted = date('d.m.Y H:i', strtotime((string)$createdRaw));
                            } else {
                                $dateFormatted = '-';
                            }
                        ?>
                        <tr>
                            <td class="code"><?php echo htmlspecialchars($inv['id']); ?></td>
                            <td><strong><?php echo htmlspecialchars($inv['store_name'] ?? 'Neznámý'); ?></strong></td>
                            <td><strong><?php echo htmlspecialchars($inv['amount']); ?> BTC</strong></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars($inv['status']); ?>"><?php echo htmlspecialchars($inv['status']); ?></span></td>
                            <td style="color: #748078;"><?php echo $dateFormatted; ?></td>
                            <td>
                                <a href="../checkout/pay.php?id=<?php echo urlencode($inv['id']); ?>" target="_blank" class="action-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> Otevřít</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>