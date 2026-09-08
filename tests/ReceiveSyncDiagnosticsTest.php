<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';
use BtcPayLite\{InstallationSchema, ReceiveSyncDiagnostics};

foreach ([1146=>'required table',1054=>'required column',1267=>'collations',1045=>'denied',9876=>'Database operation failed'] as $code=>$hint) {
    $error = new PDOException('private-password-and-sql-values');
    $error->errorInfo = ['HY000',$code,'private-password-and-sql-values'];
    $message = ReceiveSyncDiagnostics::error(new RuntimeException('wrapper',0,$error));
    coreCheck(str_contains($message,'SQLSTATE=HY000') && str_contains($message,'driver_code='.$code),'Lost structured PDO codes');
    coreCheck(str_contains($message,$hint),'Missing actionable PDO hint');
    coreCheck(!str_contains($message,'private-password'),'Raw exception details leaked');
}
echo "[PASS] Wrapped PDO errors retain safe codes and actionable hints without SQL values\n";
if (!getenv('BTCPAY_TEST_MYSQL_HOST')) {
    echo "[SKIP] Receive CLI integration requires BTCPAY_TEST_MYSQL_HOST (enabled in CI).\n"; return;
}
$host=getenv('BTCPAY_TEST_MYSQL_HOST'); $port=(int)(getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user=getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass=getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$admin=new PDO("mysql:host={$host};port={$port}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='btcpay_receive_diag_'.bin2hex(random_bytes(5)); $root=coreDirectory();
$admin->exec('CREATE DATABASE `'.$name.'`');
try {
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$name}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    copy(dirname(__DIR__).'/wallet_receive_sync.php',$root.'/wallet_receive_sync.php');
    symlink(dirname(__DIR__).'/vendor',$root.'/vendor');
    // Deliberately no RPC configuration: checking the database must not need it.
    file_put_contents($root.'/config.php','<?php return '.var_export([
        'db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass,
    ],true).';');
    $run=static function (bool $check=true) use ($root): array {
        $command=[PHP_BINARY,$root.'/wallet_receive_sync.php'];
        if ($check) { $command[]='--check-db'; }
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        coreCheck(is_resource($process),'Cannot start receive CLI'); fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process),$stdout,$stderr];
    };
    [$code,$out,$err]=$run(); $report=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    coreSame(1,$code,'Empty schema must fail'); coreSame('',$err,'Check mode writes report to stdout');
    coreSame($name,$report['database'],'Wrong configured database');
    coreSame(['xpub_address_sequences','wallet_receive_ranges','stores'],$report['missing_tables'],'Missing tables not identified');
    coreSame(['migrations/006_shared_xpub_address_sequences.sql','migrations/007_wallet_receive_ranges.sql'],$report['migration_files'],'Missing migration paths');
    [$code,$out,$err]=$run(false);
    coreSame(1,$code,'Normal sync must stop on incomplete schema'); coreSame('',$out,'Failure should use stderr');
    coreSame(false,json_decode($err,true,512,JSON_THROW_ON_ERROR)['ok'],'Normal CLI failed to report schema before RPC construction');
    echo "[PASS] Missing schema is identified by actual CLI before RPC configuration is required\n";

    (new InstallationSchema(dirname(__DIR__).'/sql.sql'))->import($pdo);
    // Reproduce an existing installation that has not imported migrations 006/007.
    $pdo->exec('DROP TABLE wallet_receive_ranges, xpub_address_sequences');
    [$code,$out]=$run(); $report=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    coreSame(['xpub_address_sequences','wallet_receive_ranges'],$report['missing_tables'],'Upgrade diagnosis is inaccurate');
    foreach ($report['migration_files'] as $migration) { $pdo->exec(file_get_contents(dirname(__DIR__).'/'.$migration)); }
    [$code,$out,$err]=$run(); $report=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    coreSame(0,$code,'Migration import did not restore CLI check'); coreSame('',$err,'Healthy schema emitted errors');
    coreSame(true,$report['ok'],'Healthy schema rejected'); coreSame([],$report['migration_files'],'Healthy schema asks for migrations');
    coreSame(PHP_BINARY,$report['php_binary'],'Actual CLI binary missing');
    foreach (['stores','wallet_receive_ranges','xpub_address_sequences'] as $table) {
        coreSame(0,(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),'Read-only check wrote rows');
    }
    echo "[PASS] Importing 006/007 restores --check-db without RPC configuration or database writes\n";

    $pdo->exec('ALTER TABLE wallet_receive_ranges DROP COLUMN checked_at');
    [$code,$out]=$run(); $report=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    coreSame(1,$code,'Partial existing table was accepted');
    coreSame(['wallet_receive_ranges'=>['checked_at']],$report['missing_columns'],'Missing column diagnosis is inaccurate');
    coreSame([],$report['migration_files'],'CREATE TABLE IF NOT EXISTS cannot repair an existing partial table');
    echo "[PASS] Partial existing tables require column repair instead of misleading CREATE TABLE advice\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `'.$name.'`');
    foreach (['config.php','wallet_receive_sync.php','vendor'] as $file) { if (file_exists($root.'/'.$file)) { unlink($root.'/'.$file); } }
    rmdir($root);
}
