<?php

declare(strict_types=1);
require __DIR__.'/support/CoreTestSupport.php';
use BtcPayLite\{AdminOperationsService, ClientDashboardService, Database, ElectrumCliWalletProvisioner, InstallationSchema,
    PdoAdminOperationsRepository, PdoClientDashboardRepository, ProvisionedWallet, StoreCreationDiagnostics, StoreCreationException,
    StoreWalletProvisioner, WebhookEndpointPolicy, XpubRuntime};

foreach (XpubRuntime::requirements() as $requirement) { coreCheck($requirement['ok'],'Test runtime must have XPUB dependencies'); }
$error=new PDOException('sql-password-marker'); $error->errorInfo=['42S22',1054,'sql-password-marker'];
$message=StoreCreationDiagnostics::message(new RuntimeException('wrapper',0,$error));
coreCheck(str_contains($message,'database_1054') && !str_contains($message,'sql-password-marker'),'Unsafe or missing DB diagnosis');
$typed=new StoreCreationException('wallet_directory','Adresář není zapisovatelný.');
coreCheck(str_contains(StoreCreationDiagnostics::message($typed),'wallet_directory'),'Lost provisioning reason');
$environment=StoreCreationDiagnostics::environment(['electrum_cli_path'=>'/missing-electrum','electrum_data_dir'=>'/missing-data','store_wallets_dir'=>'/missing-wallets']);
coreSame(PHP_SAPI,$environment['php_sapi'],'Wrong runtime in diagnostics');
coreSame(false,$environment['checks'][3]['ok'],'Missing executable was accepted');
// Separate PHP without extensions proves a missing GMP error rather than a fatal call or wallet mutation.
$script='require '.var_export(dirname(__DIR__).'/vendor/autoload.php',true).'; try { BtcPayLite\\XpubRuntime::assertAvailable(); exit(2); } catch (BtcPayLite\\StoreCreationException $e) { echo $e->reason; }';
$process=proc_open([PHP_BINARY,'-n','-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
coreCheck(is_resource($process),'No PHP runtime fixture'); fclose($pipes[0]);
$out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
coreSame(0,proc_close($process),'Minimal runtime failed: '.$err); coreSame('xpub_gmp_missing',$out,'Missing GMP was not actionable');
try { (new ElectrumCliWalletProvisioner('/missing-electrum','/missing-data','/missing-wallets'))->provision('store_'.str_repeat('a',32)); throw new LogicException('Missing executable accepted'); }
catch (StoreCreationException $e) { coreSame('electrum_executable',$e->reason,'Missing CLI is not actionable'); }
echo "[PASS] Missing GMP, executable and SQL schema errors are safe and actionable before wallet creation\n";
if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Store DB integration requires MySQL.\n"; return; }
$host=getenv('BTCPAY_TEST_MYSQL_HOST'); $port=(int)(getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user=getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass=getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$name='btcpay_store_create_'.bin2hex(random_bytes(5));
$admin=new PDO("mysql:host=$host;port=$port",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `'.$name.'`');
try {
    $db=new Database($host,$name,$user,$pass,$port); $pdo=$db->getPdo();
    (new InstallationSchema(dirname(__DIR__).'/sql.sql'))->import($pdo);
    $pdo->exec("INSERT INTO users (email,password_hash,role) VALUES ('client@example.test','unused','client')");
    $id=(int)$pdo->lastInsertId();
    $key='xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';
    $provisioner=new class($key) implements StoreWalletProvisioner {
        public int $calls=0;
        public function __construct(private string $key) {}
        public function provision(string $storeId): ProvisionedWallet { ++$this->calls; return new ProvisionedWallet('/wallets/'.$storeId,$this->key,20); }
        public function discard(string $path): void { throw new LogicException('Successful store should not discard its wallet'); }
    };
    $policy=new WebhookEndpointPolicy();
    $adminService=new AdminOperationsService(new PdoAdminOperationsRepository($db),$provisioner,$policy);
    $store=$adminService->createClientStore($id,'First store');
    $second=$adminService->createClientStore($id,'Second store');
    $clientService=new ClientDashboardService(new PdoClientDashboardRepository($db),$policy);
    $third=$clientService->createStore($id,'Client store');
    coreSame(1,$provisioner->calls,'Sharing a wallet must not provision it again');
    $rows=$pdo->query('SELECT wallet_path,address_source,xpub,xpub_last_index FROM stores')->fetchAll(PDO::FETCH_ASSOC);
    coreSame(3,count($rows),'Admin and client store inserts failed');
    foreach ($rows as $row) {
        coreSame($store['wallet_path'],$row['wallet_path'],'Client ownership changed');
        coreSame('xpub',$row['address_source'],'XPUB store fell back to Electrum');
        coreSame($key,$row['xpub'],'Public receive metadata lost');
        coreSame(20,(int)$row['xpub_last_index'],'Receive high water lost');
    }
    coreSame(1,(int)$pdo->query('SELECT COUNT(*) FROM client_wallets')->fetchColumn(),'Duplicate wallet assignment');
    $pdo->exec('ALTER TABLE stores DROP COLUMN xpub_last_index');
    try { $clientService->createStore($id,'Broken schema'); throw new LogicException('Missing column accepted'); }
    catch (\BtcPayLite\ClientDashboardException $e) { coreCheck(str_contains($e->getMessage(),'database_1054'),'Client still hides SQL cause'); }
    try { $adminService->createClientStore($id,'Broken schema'); throw new LogicException('Missing column accepted'); }
    catch (\BtcPayLite\AdminOperationsException $e) { coreCheck(str_contains($e->getMessage(),'database_1054'),'Admin still hides SQL cause'); }
    echo "[PASS] Admin first/shared and client stores persist XPUB on real DB; schema failures identify cause in both flows\n";
} finally { $admin->exec('DROP DATABASE IF EXISTS `'.$name.'`'); }
