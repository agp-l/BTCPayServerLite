<?php

declare(strict_types=1);
require __DIR__.'/support/CoreTestSupport.php';
use BtcPayLite\{DatabaseMigrationManager, InstallationSchema};
if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Database upgrade integration requires MySQL.\n"; return; }
$host=getenv('BTCPAY_TEST_MYSQL_HOST'); $port=(int)(getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user=getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass=getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$admin=new PDO("mysql:host={$host};port={$port}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='btcpay_upgrade_'.bin2hex(random_bytes(5)); $root=coreDirectory();
$admin->exec('CREATE DATABASE `'.$name.'`');
try {
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$name}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $source=dirname(__DIR__); copy($source.'/sql.sql',$root.'/sql.sql'); mkdir($root.'/migrations');
    foreach (glob($source.'/migrations/*.sql') as $file) { copy($file,$root.'/migrations/'.basename($file)); }
    (new InstallationSchema($root.'/sql.sql'))->import($pdo);
    $manager=new DatabaseMigrationManager($pdo,$root);
    $state=static function (string $file) use ($manager): string { foreach ($manager->inspect()['migrations'] as $entry) { if ($entry['file']===$file) { return $entry['state']; } } throw new RuntimeException('Missing migration'); };
    coreSame(true,$manager->inspect()['schema']['ok'],'Fresh schema comparison failed');
    coreSame('Present',$state('003_add_payment_worker_lease_and_observation.sql'),'Renamed legacy observation fields should be recognized');
    coreSame(0,(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(),'GET inspection invents migration history');
    $pdo->exec('DROP TABLE wallet_receive_ranges,xpub_address_sequences,schema_migrations');
    $m6='006_shared_xpub_address_sequences.sql'; $m7='007_wallet_receive_ranges.sql';
    coreSame('Pending',$state($m6),'Missing 006 not offered'); coreSame('Blocked',$state($m7),'007 dependency missing');
    $plan=$manager->inspect()['plan_hash'];
    $lock='schema:'.substr(hash('sha256',$name),0,56);
    $q=$admin->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$lock]);
    try { $manager->apply($m6,$plan,1); throw new LogicException('Concurrent migration accepted'); }
    catch (RuntimeException $e) { coreCheck(str_contains($e->getMessage(),'Jiný proces'),'Unexpected lock error'); }
    $q=$admin->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]);
    $manager->apply($m6,$plan,1);
    coreSame('Applied',$state($m6),'Missing durable applied record');
    try { $manager->apply($m7,$plan,1); throw new LogicException('Stale plan accepted'); }
    catch (RuntimeException $e) { coreCheck(str_contains($e->getMessage(),'změnily'),'Unexpected stale-plan error'); }
    $manager->apply($m7,$manager->inspect()['plan_hash'],1);
    coreSame(true,$manager->inspect()['schema']['ok'],'006/007 did not restore fresh schema');
    try { $manager->apply($m6,$manager->inspect()['plan_hash'],1); throw new LogicException('Migration replay accepted'); }
    catch (RuntimeException $e) { coreCheck(str_contains($e->getMessage(),'bezpečně'),'Unexpected replay error'); }
    coreSame(2,(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(),'Duplicate journal rows');
    echo "[PASS] Fresh comparison, 006/007 upgrade, durable history, stale plans and concurrent/repeated execution guards\n";

    $pdo->exec('ALTER TABLE wallet_receive_ranges DROP COLUMN checked_at');
    coreSame(false,$manager->inspect()['schema']['ok'],'Partial schema falsely passed');
    $pdo->exec('ALTER TABLE wallet_receive_ranges ADD checked_at BIGINT UNSIGNED NOT NULL DEFAULT 0');
    $pdo->exec("ALTER TABLE xpub_address_sequences MODIFY key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL");
    coreCheck(in_array('collation',array_column($manager->inspect()['schema']['differences'],'kind'),true),'Collation drift ignored');
    $pdo->exec("ALTER TABLE xpub_address_sequences MODIFY key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL");
    $pdo->exec("UPDATE schema_migrations SET state='Running' WHERE migration='$m6'");
    coreSame('Blocked',$state($m6),'Interrupted migration replay allowed');
    $pdo->exec("UPDATE schema_migrations SET state='Applied' WHERE migration='$m6'");
    file_put_contents($root.'/migrations/'.$m6,"\n-- changed\n",FILE_APPEND);
    coreSame('Blocked',$state($m6),'Changed historical migration checksum accepted');
    copy($source.'/migrations/'.$m6,$root.'/migrations/'.$m6);
    echo "[PASS] Partial schema, collation drift, interrupted execution and checksum changes are visible\n";

    // Actual failed multi-statement DDL persists its first step and must not be replayed.
    $pdo->exec('DROP TABLE wallet_receive_ranges');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration='$m7'");
    file_put_contents($root.'/migrations/'.$m7,"\nINVALID SQL;\n",FILE_APPEND);
    try { $manager->apply($m7,$manager->inspect()['plan_hash'],1); throw new LogicException('Broken migration accepted'); }
    catch (RuntimeException $e) { coreCheck(str_contains($e->getMessage(),'Migrace selhala'),'Failure not actionable'); }
    $record=$pdo->query("SELECT * FROM schema_migrations WHERE migration='$m7'")->fetch(PDO::FETCH_ASSOC);
    coreSame('Failed',$record['state'],'Failed migration not durable'); coreSame(1,(int)$record['completed_statements'],'Lost completed step');
    coreSame('Blocked',$state($m7),'Failed migration replay allowed');
    copy($source.'/migrations/'.$m7,$root.'/migrations/'.$m7);
    $pdo->exec("DELETE FROM schema_migrations WHERE migration='$m7'");
    echo "[PASS] DDL failure after a committed step is journaled and never automatically replayed\n";

    foreach (['database_upgrade.php', 'index.php', 'admin/database_upgrade.php', 'admin/views/database_upgrade_view.php',
        'admin/views/layout/header.php', 'admin/views/layout/footer.php', 'pages/error.php'] as $file) {
        if (!is_dir(dirname($root.'/'.$file))) { mkdir(dirname($root.'/'.$file),0700,true); }
        copy($source.'/'.$file,$root.'/'.$file);
    }
    symlink($source.'/vendor',$root.'/vendor');
    symlink($source.'/assets',$root.'/assets');
    file_put_contents($root.'/router.php','<?php if (is_file(__DIR__.parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH))) { return false; } $_SERVER["SCRIPT_NAME"]="/index.php"; require __DIR__."/index.php";');
    file_put_contents($root.'/config.php','<?php return '.var_export(['db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass],true).';');
    $pdo->exec("INSERT INTO users (email,password_hash,role) VALUES ('admin@example.test','unused','admin')");
    $id=(int)$pdo->lastInsertId();
    // Test-only authenticated session fixture. Production still revalidates role/status/version in DB.
    file_put_contents($root.'/session.php','<?php require __DIR__."/vendor/autoload.php"; BtcPayLite\\AuthManager::startSession(); $_SESSION=["user_id"=>'.$id.',"role"=>"admin","session_version"=>1,"auth_issued_at"=>time(),"auth_last_activity"=>time(),"auth_seen_recorded_at"=>time()];');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); coreCheck($socket!==false,'No test port');
    $httpPort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    $log=$root.'/http.log';
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$root,$root.'/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
    coreCheck(is_resource($server),'No HTTP fixture');
    try {
        $ready=false;
        for ($i=0;$i<100;$i++) { $s=@fsockopen('127.0.0.1',$httpPort,$errno,$errstr,.1); if ($s!==false) { fclose($s); $ready=true; break; } usleep(20000); }
        coreCheck($ready,'HTTP fixture not ready');
        $request=static function (string $path,?array $post=null,string $cookie='') use ($httpPort): array {
            $options=['method'=>$post===null?'GET':'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>15,'header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n"];
            if ($post!==null) { $options['content']=http_build_query($post); }
            $body=file_get_contents('http://127.0.0.1:'.$httpPort.'/'.$path,false,stream_context_create(['http'=>$options]));
            return [$body,$http_response_header];
        };
        [$body,$headers]=$request('database_upgrade.php');
        coreCheck(str_contains($headers[0],'308'),'Legacy bookmark no longer redirects');
        coreCheck(str_contains(implode("\n",$headers),'/admin/database_upgrade'),'Legacy target mismatch');
        [$body,$headers]=$request('database_upgrade.php',['migration'=>$m7]);
        coreCheck(str_contains($headers[0],'308'),'Legacy POST must retain its method on redirect');
        [$body,$headers]=$request('admin/database_upgrade'); coreCheck(str_contains($headers[0],'303'),'Anonymous migration page allowed');
        [$body,$headers]=$request('admin/database_upgrade.php'); coreCheck(str_contains($headers[0],'404'),'Direct controller allowed');
        [$body,$headers]=$request('session.php'); $cookie='';
        foreach ($headers as $header) { if (preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m)) { $cookie=$m[1]; } }
        $pdo->exec('DROP TABLE wallet_receive_ranges');
        [$body,$headers]=$request('admin/database_upgrade',null,$cookie);
        coreCheck(str_contains($headers[0],'200') && str_contains($body,'Aktualizace databáze'),'Admin preview unavailable');
        coreSame('Pending',$state($m7),'GET executed migration');
        coreCheck(str_contains($body,'admin-shell') && str_contains($body,'/assets/admin.css'), 'Shared layout missing');
        coreCheck(str_contains($body,'admin-nav-link is-active') && str_contains($body,'Aktualizace systému'), 'Menu context missing');
        coreCheck(str_contains($body,'action="http://127.0.0.1:'.$httpPort.'/admin/database_upgrade"'),'Form target mismatch');
        coreCheck(!str_contains($body,'<style>'), 'Standalone styles returned');
        [$asset,$assetHeaders]=$request('assets/admin.css');
        coreCheck(str_contains($assetHeaders[0],'200') && str_contains($asset,'.admin-shell'), 'Admin stylesheet unavailable');
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$body,$csrf);
        preg_match('/name="plan_hash" value="([a-f0-9]+)"/',$body,$plan);
        $post=['migration'=>$m7,'plan_hash'=>$plan[1],'backup'=>'1','maintenance'=>'1'];
        [$body,$headers]=$request('admin/database_upgrade',$post,$cookie); coreCheck(str_contains($headers[0],'400'),'Missing CSRF accepted');
        coreSame('Pending',$state($m7),'Missing CSRF changed DB');
        $post['csrf_token']=$csrf[1];
        $pdo->exec("UPDATE users SET status='suspended' WHERE id=$id");
        [$body,$headers]=$request('admin/database_upgrade',$post,$cookie); coreCheck(str_contains($headers[0],'303'),'Suspended admin session accepted');
        coreSame('Pending',$state($m7),'Suspended admin changed schema');
        $pdo->exec("UPDATE users SET status='active' WHERE id=$id");
        // The shared front controller invalidated the suspended session; sign in again.
        [$body,$headers]=$request('session.php');
        foreach ($headers as $header) { if (preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m)) { $cookie=$m[1]; } }
        [$body,$headers]=$request('admin/database_upgrade',null,$cookie);
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$body,$csrf);
        $post['csrf_token']=$csrf[1];
        [$body,$headers]=$request('admin/database_upgrade',$post,$cookie);
        coreCheck(str_contains($headers[0],'200') && str_contains($body,'Migrace dokončena'),'Authorized POST failed: '.$body);
        coreSame('Applied',$state($m7),'Authorized POST did not apply migration');
        coreSame(true,$manager->inspect()['schema']['ok'],'Post-upgrade comparison failed');
        echo "[PASS] Actual HTTP: anonymous denied, admin GET read-only, CSRF enforced, revoked account denied, authorized POST upgrades\n";
    } finally { proc_terminate($server); fclose($pipes[0]); proc_close($server); }
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `'.$name.'`');
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
