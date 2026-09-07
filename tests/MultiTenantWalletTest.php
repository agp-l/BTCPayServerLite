<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';
use BtcPayLite\{Database, InstallationSchema, PdoClientDashboardRepository, PdoAdminOperationsRepository,
    BtcInvoiceManager, ElectrumWallet, ElectrumRPC, GreenfieldApiRepository, GreenfieldApiService, GreenfieldApiException};
if(!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Tenant integration requires BTCPAY_TEST_MYSQL_HOST (enabled in CI).\n";return; }
$host=getenv('BTCPAY_TEST_MYSQL_HOST');$port=(int)(getenv('BTCPAY_TEST_MYSQL_PORT')?:3306);
$user=getenv('BTCPAY_TEST_MYSQL_USER')?:'root';$pass=getenv('BTCPAY_TEST_MYSQL_PASS')?:'';
$name='btcpay_tenant_'.bin2hex(random_bytes(5));
$admin=new PDO("mysql:host={$host};port={$port}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `'.$name.'`');
try {
    $db=new Database($host,$name,$user,$pass,$port);$pdo=$db->getPdo();
    (new InstallationSchema(dirname(__DIR__).'/sql.sql'))->import($pdo);
    $pdo->exec("INSERT INTO users (id,email,password_hash) VALUES (1,'a@example.test','test-only'),(2,'b@example.test','test-only')");
    $repo=new PdoClientDashboardRepository($db);
    $repo->assignWallet(1,'/wallets/A',time());$repo->assignWallet(2,'/wallets/B',time());
    $repo->createStore(1,'store_A','A','key_A','/wallets/A');$repo->createStore(2,'store_B','B','key_B','/wallets/B');
    coreSame('/wallets/A',$repo->findAssignedWallet(1),'Client A wallet');
    coreSame('/wallets/B',$repo->findAssignedWallet(2),'Client B wallet');
    coreSame(['store_A'],array_column($repo->fetchStores(1),'id'),'Client A can see another store');
    coreCheck(!$repo->ownsStore(2,'store_A'),'Client B owns A store');
    coreCheck(!$repo->updateStoreName(2,'store_A','stolen'),'Client B renamed A store');
    coreCheck(!$repo->rotateStoreApiKey(2,'store_A','stolen-key'),'Client B changed A API key');
    coreCheck(!$repo->deleteEmptyStore(2,'store_A'),'Client B deleted A store');
    try { $repo->createStore(1,'store_wrong','Wrong','wrong-key','/wallets/B');throw new LogicException('Cross-wallet assignment accepted'); }
    catch(RuntimeException $e) { coreCheck(!($e instanceof LogicException),'Cross-wallet assignment was accepted'); }
    $webhook=$repo->findOrCreateWebhook('store_A','https://example.test/hook',time());
    coreCheck(!$repo->deleteWebhook(2,$webhook['id']),'Client B deleted A webhook');
    $rpc=new class('127.0.0.1',1) extends ElectrumRPC {
        public array $targets=[];
        public function call(string $method,array $params=[]): mixed {
            if($method==='list_wallets') { return ['/wallets/A','/wallets/B']; }
            if($method==='createnewaddress') {
                $this->targets[]=$params['wallet_path']??'';
                return $params['wallet_path']==='/wallets/A'?'bc1qtenant000000000000000000000000000a':'bc1qtenant000000000000000000000000000b';
            }
            throw new LogicException('Unexpected Electrum operation: '.$method);
        }
    };
    $wallet=new ElectrumWallet($rpc);
    $api=new GreenfieldApiService(new GreenfieldApiRepository($db),$db,$wallet,
        new BtcInvoiceManager($wallet,str_repeat('s',32),$db),'admin-test-key','http://localhost');
    try { $api->createInvoiceWithIdempotency('store_B',['amount'=>'0.001'],'key_A','tenant-isolation');throw new LogicException('Cross-store API key accepted'); }
    catch(GreenfieldApiException $e) { coreSame(401,$e->getHttpStatus(),'Cross-store API authorization status'); }
    coreSame(0,(int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),'Unauthorized invoice was created');
    coreSame(0,(int)$pdo->query('SELECT COUNT(*) FROM api_idempotency_keys')->fetchColumn(),'Unauthorized key reserved a resource');
    coreSame([],$rpc->targets,'Unauthorized request reserved an address');
    $rpc->setWallet('/wallets/B'); // Compatibility selection must not override authenticated store A.
    $api->createInvoice('store_A',['amount'=>'0.001'],'key_A');
    $api->createInvoice('store_B',['amount'=>'0.001'],'key_B');
    coreSame(['/wallets/A','/wallets/B'],$rpc->targets,'Invoice generation ignored the authenticated store wallet');
    coreSame('bc1qtenant000000000000000000000000000a',$pdo->query("SELECT btc_address FROM invoices WHERE store_id='store_A'")->fetchColumn(),'Store A invoice used wallet B');
    (new PdoAdminOperationsRepository($db))->createStore('store_admin','Admin','admin-store-key','/wallets/admin');
    coreSame('electrum',$pdo->query("SELECT address_source FROM stores WHERE id='store_admin'")->fetchColumn(),'Provisioned Electrum store was marked XPUB');
    echo "[PASS] Real tenant DB boundaries: assigned wallet, store reads/mutations, webhook deletion, API keys and Electrum store source\n";
} finally { $admin->exec('DROP DATABASE `'.$name.'`'); }
