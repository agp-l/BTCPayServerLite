<?php

declare(strict_types=1);
require __DIR__.'/support/CoreTestSupport.php';
use BtcPayLite\{Database,InstallationSchema,XpubDerivationIdentity};
if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Legacy repair requires MySQL\n"; return; }
$host=getenv('BTCPAY_TEST_MYSQL_HOST');$port=(int)(getenv('BTCPAY_TEST_MYSQL_PORT')?:3306);
$user=getenv('BTCPAY_TEST_MYSQL_USER')?:'root';$pass=getenv('BTCPAY_TEST_MYSQL_PASS')?:'';
$name='btcpay_repair_'.bin2hex(random_bytes(5));$root=coreDirectory();
$admin=new PDO("mysql:host=$host;port=$port",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$name.'`');
try {
    $db=new Database($host,$name,$user,$pass,$port);$pdo=$db->getPdo();
    (new InstallationSchema(dirname(__DIR__).'/sql.sql'))->import($pdo);
    $key='xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';
    $pdo->exec("INSERT INTO stores (id,name,api_key,wallet_path) VALUES ('repair','Test','test-only','/wallets/test')");
    $stmt=$pdo->prepare('INSERT INTO xpub_address_sequences (key_hash,next_index) VALUES (?,100)');$stmt->execute([XpubDerivationIdentity::describe($key)['id']]);
    copy(dirname(__DIR__).'/repair_store_xpub.php',$root.'/repair_store_xpub.php');symlink(dirname(__DIR__).'/vendor',$root.'/vendor');
    file_put_contents($root.'/config.php','<?php return '.var_export(['db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass,'rpc_host'=>'127.0.0.1','rpc_port'=>1],true).';');
    // Substitute only live wallet inspection; exercise the actual CLI + DB transaction.
    file_put_contents($root.'/fixture.php','<?php namespace BtcPayLite; final class WalletXpubReader { public function __construct($wallet) {} public function read($path) { return new ProvisionedWallet($path,'.var_export($key,true).',2); } }');
    $run=static function() use($root): array {
        $p=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$root.'/fixture.php',$root.'/repair_store_xpub.php','--store=repair','--apply','--maintenance'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    [$code,$out,$err]=$run();coreSame(0,$code,'Repair CLI failed: '.$err);
    coreSame(100,json_decode($out,true)['minimum_next_index'],'Repair report missed shared high water');
    coreSame(100,(int)$pdo->query('SELECT xpub_last_index FROM stores')->fetchColumn(),'Repair stored a lower floor');
    $pdo->exec("UPDATE stores SET xpub_script_type='p2wpkh'");[$code,$out,$err]=$run();coreSame(1,$code,'Conflicting explicit XPUB policy accepted');
    coreSame('p2wpkh',$pdo->query('SELECT xpub_script_type FROM stores')->fetchColumn(),'Conflicting policy was overwritten');
    coreSame(100,(int)$pdo->query('SELECT next_index FROM xpub_address_sequences')->fetchColumn(),'Repair decreased shared sequence');
    echo "[PASS] Actual repair CLI preserves shared high water and rejects explicit script mismatch\n";
} finally {
    $admin->exec('DROP DATABASE `'.$name.'`');foreach(glob($root.'/*') as $file){unlink($file);}rmdir($root);
}
