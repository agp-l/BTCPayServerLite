<?php
// admin/views/index_view.php
declare(strict_types=1);

$pageTitle = 'Přehled - BTCPay Lite';
$activeMenu = 'dashboard';
require __DIR__ . '/layout/header.php';
?>

<style>
/* Specifické styly pouze pro Dashboard */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px; }
.stat-card { background: #ffffff; border: 1px solid #e5eae7; border-radius: 16px; padding: 20px; box-shadow: 0 4px 15px rgba(20,45,28,.04); }
.stat-label { font-size: 12px; font-weight: 700; color: #748078; text-transform: uppercase; margin-bottom: 5px; }
.stat-value { font-size: 24px; font-weight: 800; color: #17201a; }

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
.action-link { color: #20b948; text-decoration: none; font-weight: 600; font-size: 13px; }
.action-link:hover { text-decoration: underline; }
</style>

<div class="page-header">
    <h1><i class="fa-solid fa-chart-pie" style="color:#2fd35a;"></i> Přehled systému</h1>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Aktivní E-shopy</div>
        <div class="stat-value"><?php echo htmlspecialchars((string)$totalStores); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Celkem Faktur</div>
        <div class="stat-value"><?php echo htmlspecialchars((string)$totalInvoices); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Zaplaceno Faktur</div>
        <div class="stat-value" style="color: #13aa3d;"><?php echo htmlspecialchars((string)$settledInvoices); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Přijato Celkem</div>
        <div class="stat-value"><?php echo htmlspecialchars(number_format((float)$totalBtcVolume, 6, '.', '')); ?> BTC</div>
    </div>
</div>

<div class="card">
    <div class="card-title" style="margin-bottom: 0;">
        <span style="display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-clock-rotate-left" style="color:#748078;"></i> Poslední DB faktury
        </span>
        <a href="invoices.php" class="action-link">Zobrazit všechny &rarr;</a>
    </div>

    <?php if (empty($invoices)): ?>
        <p style="color: #748078; font-size: 14px; margin-top: 20px;">Zatím nebyly vygenerovány žádné faktury.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
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
                            <td class="code"><?php echo htmlspecialchars(substr($inv['id'], 0, 15)) . '...'; ?></td>
                            <td><strong><?php echo htmlspecialchars($inv['store_name'] ?? 'Neznámý'); ?></strong></td>
                            <td><strong><?php echo htmlspecialchars($inv['amount']); ?> BTC</strong></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars($inv['status']); ?>"><?php echo htmlspecialchars($inv['status']); ?></span></td>
                            <td style="color: #748078;"><?php echo $dateFormatted; ?></td>
                            <td>
                                <a href="../checkout/pay.php?id=<?php echo urlencode($inv['id']); ?>" target="_blank" class="action-link">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Otevřít
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>