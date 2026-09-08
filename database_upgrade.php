<?php

declare(strict_types=1);
use BtcPayLite\{AuthManager, Database, DatabaseMigrationManager};
ini_set('display_errors','0');
require __DIR__.'/vendor/autoload.php';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','POST'],true)) { http_response_code(405); exit; }
AuthManager::startSession();
if (!AuthManager::hasRole('admin')) { http_response_code(403); echo 'Přihlaste se jako administrátor přes <a href="login">přihlášení</a> a vraťte se na tuto stránku.'; exit; }
$error=null; $notice=null; $report=null;
try {
    if (!is_file(__DIR__.'/config.php')) { throw new RuntimeException('Chybí config.php. Pro novou instalaci použijte install.php.'); }
    $config=require __DIR__.'/config.php';
    $db=new Database($config['db_host'],$config['db_name'],$config['db_user'],$config['db_pass'],(int)($config['db_port'] ?? 3306));
    $pdo=$db->getPdo();
    // Revalidate account/role/session revocation against DB on every request, including POST.
    $stmt=$pdo->prepare('SELECT role,status,session_version FROM users WHERE id=?'); $stmt->execute([$_SESSION['user_id']]);
    $user=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || $user['role']!=='admin' || $user['status']!=='active' || (int)$user['session_version']!==(int)($_SESSION['session_version'] ?? 0)) {
        http_response_code(403); echo 'Relace administrátora již není platná. Přihlaste se znovu.'; exit;
    }
    $manager=new DatabaseMigrationManager($pdo,__DIR__);
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        if (($_POST['backup'] ?? '')!=='1' || ($_POST['maintenance'] ?? '')!=='1') { throw new RuntimeException('Před změnou potvrďte zálohu a zastavení zápisů aplikace.'); }
        $manager->apply(is_string($_POST['migration'] ?? null) ? $_POST['migration'] : '',is_string($_POST['plan_hash'] ?? null) ? $_POST['plan_hash'] : '',$_SESSION['user_id']);
        $notice='Migrace dokončena. Níže je nová kontrola schématu.';
    }
    $report=$manager->inspect();
    $storeEnvironment=\BtcPayLite\StoreCreationDiagnostics::environment($config);
} catch (Throwable $exception) {
    http_response_code(400);
    if ($exception instanceof PDOException || $exception instanceof \BtcPayLite\DatabaseException) {
        $error='Databázová kontrola selhala. Ověřte DB konfiguraci a oprávnění. SQLSTATE: '.($exception instanceof PDOException ? (string)$exception->getCode() : 'connection');
    } else { $error=$exception->getMessage(); }
}
$html=static fn(mixed $text): string=>htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$csrf=AuthManager::csrfToken();
?>
<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aktualizace databáze · BTCPay Lite</title>
<style>body{font:16px/1.5 system-ui,sans-serif;background:#f4f6f8;color:#182230;margin:0}main{max-width:1100px;margin:32px auto;padding:24px;background:white;border-radius:12px}h1{margin-top:0}table{border-collapse:collapse;width:100%;font-size:14px}td,th{padding:10px;text-align:left;border-bottom:1px solid #ddd;vertical-align:top;overflow-wrap:anywhere}button{padding:9px 16px;cursor:pointer;background:#174b8e;color:white;border:0;border-radius:5px}label{display:block;margin:12px 0}.notice{background:#e4f5e9;padding:16px}.error{background:#ffe8e8;padding:16px}.scroll{overflow-x:auto}code{overflow-wrap:anywhere}small{color:#475467}</style>
<main><h1>Aktualizace databáze</h1><p><a href="admin">Zpět do administrace</a> · <a href="database_upgrade.php">Obnovit kontrolu</a></p>
<p>Otevření stránky pouze kontroluje strukturu. Změny se spustí jednotlivě tlačítkem. Electrum se nepoužívá.</p>
<?php if ($error!==null): ?><p class="error"><?= $html($error) ?></p><?php endif ?>
<?php if ($notice!==null): ?><p class="notice"><?= $html($notice) ?></p><?php endif ?>
<?php if ($report!==null): $schema=$report['schema']; ?>
<p>Databáze: <strong><?= $html($schema['database']) ?></strong>. <?= $schema['ok'] ? 'Kontrolované části odpovídají sql.sql.' : 'Byly nalezeny rozdíly vůči sql.sql.' ?></p>
<p><small><?= $html($schema['scope']) ?> Dodatečné tabulky zůstávají beze změny.</small></p>
<?php if (isset($storeEnvironment)): ?>
<h2>Prostředí pro vytváření obchodů</h2>
<p>PHP <?= $html($storeEnvironment['php_version']) ?> (<?= $html($storeEnvironment['php_sapi']) ?>), uživatel procesu: <strong><?= $html($storeEnvironment['process_user']) ?></strong>.<br>Načtené php.ini: <code><?= $html($storeEnvironment['php_ini']) ?></code></p>
<p>Kontrola se provádí přímo v PHP webového serveru. Nevytváří peněženku ani nespouští Electrum. Dostupnost jeho Python závislostí ověří až samotné spuštění.</p>
<div class="scroll"><table><tr><th>Požadavek</th><th>Stav</th><th>Podrobnosti</th></tr>
<?php foreach ($storeEnvironment['checks'] as $check): ?><tr><td><?= $html($check['name']) ?></td><td><?= $check['ok'] ? 'OK' : 'Vyžaduje opravu' ?></td><td><?= $html($check['detail']) ?></td></tr><?php endforeach ?></table></div>
<?php endif ?>
<?php if ($schema['differences']!==[]): ?><div class="scroll"><table><tr><th>Tabulka / prvek</th><th>Rozdíl</th><th>Očekáváno</th><th>Nalezeno</th></tr>
<?php foreach ($schema['differences'] as $diff): ?><tr><td><?= $html($diff['table'].'.'.$diff['name']) ?></td><td><?= $html($diff['kind']) ?></td><td><?= $html($diff['expected']) ?></td><td><?= $html($diff['actual']) ?></td></tr><?php endforeach ?></table></div><?php endif ?>
<?php if ($schema['extra_tables']!==[]): ?><p>Dodatečné tabulky: <?= $html(implode(', ',$schema['extra_tables'])) ?></p><?php endif ?>
<h2>Migrace</h2><p>Před spuštěním exportujte zálohu v phpMyAdmin a zastavte API zápisy i payment, webhook, payout a receive workery. Tato stránka je sama nezastavuje. DDL může být uložené i po pozdější chybě; neslibuje rollback celé migrace.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= $html($csrf) ?>"><input type="hidden" name="plan_hash" value="<?= $html($report['plan_hash']) ?>">
<label><input type="checkbox" name="backup" value="1" required> Mám aktuální export databáze pro obnovu.</label>
<label><input type="checkbox" name="maintenance" value="1" required> Zastavil jsem zápisy aplikace a workery.</label>
<div class="scroll"><table><tr><th>Soubor</th><th>Stav</th><th>Postup</th></tr>
<?php $labels=['Pending'=>'Čeká','Present'=>'Struktura přítomna','Applied'=>'Provedeno nástrojem','Blocked'=>'Vyžaduje kontrolu','Manual'=>'Ruční postup']; foreach ($report['migrations'] as $migration): ?>
<tr><td><code><?= $html($migration['file']) ?></code><details><summary>Zobrazit SQL</summary><pre><?= $html($migration['sql']) ?></pre></details></td><td><?= $html($labels[$migration['state']]) ?></td><td><?= $html($migration['reason']) ?>
<?php if ($migration['state']==='Pending'): ?><p><button name="migration" value="<?= $html($migration['file']) ?>">Spustit tuto migraci</button></p><?php endif ?></td></tr>
<?php endforeach ?></table></div></form>
<p>Automatický katalog zahrnuje migrace 001–008. Starší datové migrace a neznámé nové soubory vyžadují vlastní postup; nástroj je nespouští podle názvu. Rozdíly nemaže ani neopravuje odhadnutými ALTER příkazy.</p>
<?php endif ?></main></html>
