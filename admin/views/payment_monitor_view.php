<?php

declare(strict_types=1);
if (!isset($html, $csrfToken)) { http_response_code(404); exit; }
$pageTitle = 'Kontrola plateb - BTCPay Lite';
$activeMenu = 'payment_monitor';
require __DIR__ . '/layout/header.php';
?>
<section class="page-header">
  <div class="page-header-copy"><p class="page-eyebrow">Provoz systému</p><h1>Kontrola plateb</h1><p>Ověřování faktur a poslední zaznamenané běhy.</p></div>
  <div class="page-actions"><a class="ghost-btn" href="<?= $routeUrl('/admin/payment_monitor') ?>">Obnovit přehled</a></div>
</section>
<?php if ($pageError !== null): ?>
<div class="alert alert-error" role="alert"><?= $html($pageError) ?></div>
<p><a href="<?= $routeUrl('/admin/database_upgrade') ?>">Otevřít aktualizaci systému</a></p>
<?php endif; ?>
<?php if ($notice !== null): ?><div class="alert alert-warning" role="status"><?= $html($notice) ?></div><?php endif; ?>
<?php if ($snapshot !== null): ?>
<section class="card">
  <h2 class="card-title">Automatické ověřování</h2>
  <p><strong><?= $html($automaticState) ?></strong></p>
  <p class="card-subtitle">Stav vychází ze skutečných CLI běhů. Nepotvrzuje, že je zapnutý cron nebo systemd timer. I ruční příkaz v terminálu se zaznamená jako CLI. Ruční tlačítko níže tento stav neovlivňuje.</p>
  <?php if (($snapshot['runs']['cli']['error_type'] ?? null) !== null): ?>
  <div class="alert alert-warning"><?= $html(\BtcPayLite\PaymentFailureDiagnostics::hint($snapshot['runs']['cli']['error_type'])) ?></div>
  <?php endif; ?>
  <p>Prázdný běh potvrzuje spuštění programu, nikoli úspěšnou kontrolu plateb. Předchozí chyba zůstává viditelná do další neprázdné úspěšné dávky.</p>
  <p>Za opožděný považujeme CLI běh starší než 2 minuty. Prázdná úspěšná dávka také potvrzuje spuštění workeru.</p>
  <p>Kontrola právě běží: <strong><?= $snapshot['running'] ? 'Ano' : 'Ne' ?></strong>.</p>
  <p>Faktury čekající na kontrolu: <strong><?= $snapshot['due'] ?></strong>. Propadlé rezervace po přerušeném běhu: <strong><?= $snapshot['stale_leases'] ?></strong>.</p>
  <p>Nejstarší plánovaná kontrola ve frontě: <?= $html($formatTime($snapshot['oldest_due_at'])) ?>. Nové faktury mohou čekat bez naplánovaného času.</p>
</section>
<section class="card">
  <h2 class="card-title">Zkontrolovat platby nyní</h2>
  <p>Spustí stejnou kontrolu jako automatický worker. Ověří nejvýše 20 aktuálně čekajících faktur v krátké dávce. Další kontrolu lze spustit po 15 sekundách; zbývající počet uvidíte v přehledu.</p>
  <p class="card-subtitle">Zaplacené faktury a faktury, které ještě nejsou na řadě, znovu neskenuje. Tlačítko nezapíná automatické spouštění. Otevření této stránky žádnou blockchain kontrolu nespouští.</p>
  <form method="post" action="<?= $routeUrl('/admin/payment_monitor') ?>">
    <input type="hidden" name="csrf_token" value="<?= $html($csrfToken) ?>">
    <input type="hidden" name="action" value="run">
    <button class="primary" type="submit" <?= $snapshot['running'] ? 'disabled' : '' ?>>Zkontrolovat platby nyní</button>
  </form>
</section>
<section class="card">
  <h2 class="card-title">Poslední běhy</h2>
  <div class="data-table-wrap"><table class="data-table">
    <thead><tr><th>Spuštění</th><th>Výsledek</th><th>Začátek / konec</th><th>Poslední úspěch / chyba</th><th>Zkontrolováno / změněno / chyb</th></tr></thead>
    <tbody>
    <?php foreach (['cli'=>'CLI / plánovač', 'manual'=>'Ruční tlačítko'] as $source=>$label): $run = $snapshot['runs'][$source] ?? null; ?>
      <tr><td><?= $html($label) ?></td>
      <?php if ($run === null): ?><td colspan="4">Zatím nezaznamenán žádný běh.</td>
      <?php else: ?>
        <td><?= $html($run['state'] === 'Running' && !$snapshot['running'] ? 'Přerušený běh' : $stateLabels[$run['state']]) ?><?php if ($run['error_type'] !== null): ?><br><code><?= $html($run['error_type']) ?></code><br><?= $html(\BtcPayLite\PaymentFailureDiagnostics::hint($run['error_type'])) ?><?php endif; ?></td>
        <td><?= $html($formatTime($run['started_at'])) ?><br><?= $html($formatTime($run['finished_at'])) ?></td>
        <td><?= $html($formatTime($run['last_success_at'])) ?><br><?= $html($formatTime($run['last_failed_at'])) ?></td>
        <td><?= (int)$run['scanned'] ?> / <?= (int)$run['transitioned'] ?> / <?= (int)$run['failed'] ?></td>
      <?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<section class="card">
  <h2 class="card-title">Nastavení automatického spouštění</h2>
  <p>Na serveru nastavte cron nebo systemd timer podle <a href="https://github.com/agp-l/BTCPayServerLite/blob/main/docs/PAYMENT_MONITORING.md">návodu pro kontrolu plateb</a>. Web nemění nastavení Linuxu.</p>
  <p>Kontrolní příkaz <code>php payment_worker.php --check</code> ukáže DB frontu a zaznamenané běhy bez RPC. Příkaz <code>php payment_worker.php</code> provede kontrolu plateb.</p>
  <p class="card-subtitle">Webhooky doručuje samostatný <code>webhook_cron.php</code>. Synchronizaci adres zajišťuje <code>wallet_receive_sync.php</code>. Jejich běh tento přehled zatím neměří; úspěšná kontrola plateb nepotvrzuje doručení webhooku.</p>
</section>
<?php require __DIR__ . '/layout/footer.php'; ?>
