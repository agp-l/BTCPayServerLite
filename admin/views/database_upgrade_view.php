<?php

declare(strict_types=1);

// This template is rendered only by the authenticated admin controller.
if (!isset($html, $csrfToken)) {
    http_response_code(404);
    exit;
}
$pageTitle = 'Aktualizace systému - BTCPay Lite';
$activeMenu = 'database_upgrade';
require __DIR__ . '/layout/header.php';
?>
<section class="page-header">
  <div class="page-header-copy">
    <p class="page-eyebrow">Údržba instance</p>
    <h1>Aktualizace systému</h1>
    <p>Aktualizace databáze a kontrola prostředí této instalace.</p>
  </div>
  <div class="page-actions">
    <a class="ghost-btn" href="<?= $routeUrl('/admin/database_upgrade') ?>"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Obnovit kontrolu</a>
  </div>
</section>
<section class="card database-upgrade-card">
<p>Otevření stránky pouze kontroluje strukturu. Změny se spustí jednotlivě tlačítkem. Electrum se nepoužívá.</p>
<?php if ($error!==null): ?><p class="alert alert-error" role="alert"><?= $html($error) ?></p><?php endif ?>
<?php if ($notice!==null): ?><p class="alert alert-success" role="status"><?= $html($notice) ?></p><?php endif ?>
<?php if ($report!==null): $schema=$report['schema']; ?>
<p>Databáze: <strong><?= $html($schema['database']) ?></strong>. <?= $schema['ok'] ? 'Kontrolované části odpovídají sql.sql.' : 'Byly nalezeny rozdíly vůči sql.sql.' ?></p>
<p><small><?= $html($schema['scope']) ?> Dodatečné tabulky zůstávají beze změny.</small></p>
<?php if (isset($storeEnvironment)): ?>
</section>
<section class="card database-upgrade-card">
<h2 class="card-title">Prostředí pro vytváření obchodů</h2>
<p>Opakovatelné nasazení: <a href="https://github.com/agp-l/BTCPayServerLite/blob/main/docs/DEPLOYMENT.md">návod a uložené instalační kroky</a>. Lokální kontrolu spustíte <code>php bin/deployment.php --check</code>. Plán oprávnění připraví <code>php bin/deployment.php --help</code>.</p>
<p>PHP <?= $html($storeEnvironment['php_version']) ?> (<?= $html($storeEnvironment['php_sapi']) ?>), uživatel procesu: <strong><?= $html($storeEnvironment['process_user']) ?></strong>.<br>Načtené php.ini: <code><?= $html($storeEnvironment['php_ini']) ?></code></p>
<p>Kontrola se provádí přímo v PHP webového serveru. Nevytváří peněženku ani nespouští Electrum. Dostupnost jeho Python závislostí ověří až samotné spuštění.</p>
<div class="data-table-wrap"><table class="data-table">
<tr><th>Požadavek</th><th>Stav</th><th>Podrobnosti</th></tr>

<?php foreach ($storeEnvironment['checks'] as $check): ?>
<tr><td><?= $html($check['name']) ?></td><td><?= $check['ok'] ? 'OK' : 'Vyžaduje opravu' ?></td><td><?= $html($check['detail']) ?></td></tr>
<?php endforeach ?>
</table></div>
</section>
<section class="card database-upgrade-card">
<h2 class="card-title">Porovnání schématu</h2>
<?php endif ?>
<?php if ($schema['differences']!==[]): ?><div class="data-table-wrap"><table class="data-table">
<tr><th>Tabulka / prvek</th><th>Rozdíl</th><th>Očekáváno</th><th>Nalezeno</th></tr>

<?php foreach ($schema['differences'] as $diff): ?>
<tr><td><?= $html($diff['table'].'.'.$diff['name']) ?></td><td><?= $html($diff['kind']) ?></td><td><?= $html($diff['expected']) ?></td><td><?= $html($diff['actual']) ?></td></tr>
<?php endforeach ?>
</table></div><?php endif ?>
<?php if ($schema['extra_tables']!==[]): ?><p>Dodatečné tabulky: <?= $html(implode(', ',$schema['extra_tables'])) ?></p><?php endif ?>
</section>
<section class="card database-upgrade-card">
<h2 class="card-title">Migrace</h2><p>Před spuštěním exportujte zálohu v phpMyAdmin a zastavte API zápisy i payment, webhook, payout a receive workery. Tato stránka je sama nezastavuje. DDL může být uložené i po pozdější chybě; neslibuje rollback celé migrace.</p>
<form method="post" action="<?= $routeUrl('/admin/database_upgrade') ?>" class="form-stack"><input type="hidden" name="csrf_token" value="<?= $html($csrfToken) ?>"><input type="hidden" name="plan_hash" value="<?= $html($report['plan_hash']) ?>">
<label><input type="checkbox" name="backup" value="1" required> Mám aktuální export databáze pro obnovu.</label>
<label><input type="checkbox" name="maintenance" value="1" required> Zastavil jsem zápisy aplikace a workery.</label>
<div class="data-table-wrap"><table class="data-table">
<tr><th>Soubor</th><th>Stav</th><th>Postup</th></tr>

<?php $labels=['Pending'=>'Čeká','Present'=>'Struktura přítomna','Applied'=>'Provedeno nástrojem','Blocked'=>'Vyžaduje kontrolu','Manual'=>'Ruční postup']; foreach ($report['migrations'] as $migration): ?>

<tr><td><code><?= $html($migration['file']) ?></code><details><summary>Zobrazit SQL</summary><pre><?= $html($migration['sql']) ?></pre></details></td><td><?= $html($labels[$migration['state']]) ?></td><td><?= $html($migration['reason']) ?>
<?php if ($migration['state']==='Pending'): ?><p><button type="submit" class="primary" name="migration" value="<?= $html($migration['file']) ?>">Spustit tuto migraci</button></p><?php endif ?></td></tr>

<?php endforeach ?>
</table></div></form>
<p>Automatický katalog zahrnuje migrace 001–009. Starší datové migrace a neznámé nové soubory vyžadují vlastní postup; nástroj je nespouští podle názvu. Rozdíly nemaže ani neopravuje odhadnutými ALTER příkazy.</p>
<?php endif ?>
</section>
<?php require __DIR__ . '/layout/footer.php'; ?>
