<?php

declare(strict_types=1);

$pageTitle = 'Dashboard - BTCPay Lite';
$activeMenu = 'dashboard';
require __DIR__ . '/layout/header.php';

$summary = $dashboardSummary;
$statusClasses = [
    'New' => 'badge-New',
    'Processing' => 'badge-Processing',
    'Settled' => 'badge-Settled',
    'Expired' => 'badge-Expired',
];
?>

<section class="page-header">
  <div class="page-header-copy">
    <p class="page-eyebrow">Provozní přehled</p>
    <h1>Dashboard</h1>
    <p>Aktuální stav obchodů, faktur a přijatého bitcoinového objemu na jednom místě.</p>
  </div>
  <div class="page-actions">
    <a href="<?php echo $routeUrl('/admin/wallet'); ?>" class="ghost-btn">
      <i class="fa-solid fa-wallet" aria-hidden="true"></i> Otevřít peněženku
    </a>
    <a href="<?php echo $routeUrl('/admin/invoices'); ?>" class="primary">
      <i class="fa-solid fa-file-invoice" aria-hidden="true"></i> Všechny faktury
    </a>
  </div>
</section>

<?php if ($pageError !== null): ?>
  <div class="alert alert-error" role="alert">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <span><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
<?php endif; ?>

<section class="stats-grid" aria-label="Souhrnné statistiky">
  <article class="stat-card">
    <div class="stat-card-head">
      <span class="stat-icon"><i class="fa-solid fa-store" aria-hidden="true"></i></span>
    </div>
    <div class="stat-label">Aktivní obchody</div>
    <div class="stat-value"><?php echo number_format($summary['total_stores'], 0, ',', ' '); ?></div>
    <div class="stat-meta">Napojené projekty a e-shopy</div>
  </article>

  <article class="stat-card stat-card-blue">
    <div class="stat-card-head">
      <span class="stat-icon"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i></span>
    </div>
    <div class="stat-label">Všechny faktury</div>
    <div class="stat-value"><?php echo number_format($summary['total_invoices'], 0, ',', ' '); ?></div>
    <div class="stat-meta">Celkový počet vytvořených faktur</div>
  </article>

  <article class="stat-card">
    <div class="stat-card-head">
      <span class="stat-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
      <span class="badge badge-Settled"><?php echo $summary['settlement_rate']; ?> %</span>
    </div>
    <div class="stat-label">Zaplacené faktury</div>
    <div class="stat-value"><?php echo number_format($summary['settled_invoices'], 0, ',', ' '); ?></div>
    <div class="stat-meta">Podíl úspěšně vypořádaných plateb</div>
  </article>

  <article class="stat-card stat-card-amber">
    <div class="stat-card-head">
      <span class="stat-icon"><i class="fa-brands fa-bitcoin" aria-hidden="true"></i></span>
    </div>
    <div class="stat-label">Přijatý objem</div>
    <div class="stat-value code"><?php echo htmlspecialchars($summary['total_btc_volume'], ENT_QUOTES, 'UTF-8'); ?> BTC</div>
    <div class="stat-meta">Pouze potvrzené faktury</div>
  </article>
</section>

<section class="card">
  <div class="card-title">
    <span class="card-title-group">
      <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
      Poslední faktury
    </span>
    <a href="<?php echo $routeUrl('/admin/invoices'); ?>" class="action-link">
      Zobrazit vše <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
    </a>
  </div>
  <p class="card-subtitle">Dvacet nejnovějších databázových faktur napříč všemi obchody.</p>

  <?php if ($invoices === []): ?>
    <div class="empty-state">
      <div>
        <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
        <p>Zatím nebyla vytvořena žádná faktura.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="data-table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Faktura</th>
            <th>Obchod</th>
            <th>Částka</th>
            <th>Stav</th>
            <th>Vytvořeno</th>
            <th><span class="visually-hidden">Akce</span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($invoices as $invoice): ?>
          <?php
          $status = $invoice['status'];
          $statusClass = $statusClasses[$status] ?? 's-unknown';
          $invoiceUrl = htmlspecialchars(
              $urlManager->url('/pay', ['id' => $invoice['id']]),
              ENT_QUOTES,
              'UTF-8'
          );
          ?>
          <tr>
            <td><span class="code truncate" title="<?php echo htmlspecialchars($invoice['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($invoice['id'], ENT_QUOTES, 'UTF-8'); ?></span></td>
            <td><strong><?php echo htmlspecialchars($invoice['store_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
            <td><span class="code"><?php echo htmlspecialchars($invoice['amount'], ENT_QUOTES, 'UTF-8'); ?></span> BTC</td>
            <td><span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span></td>
            <td class="muted"><time datetime="<?php echo date('c', $invoice['created_at']); ?>"><?php echo date('d.m.Y H:i', $invoice['created_at']); ?></time></td>
            <td>
              <a href="<?php echo $invoiceUrl; ?>" target="_blank" rel="noopener" class="ghost-btn" aria-label="Otevřít fakturu <?php echo htmlspecialchars($invoice['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/layout/footer.php'; ?>
