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
        public array $targets=[]; public int $calls=0;
        public function call(string $method,array $params=[]): mixed {
            ++$this->calls;
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
    $profile = new \BtcPayLite\ProvisionedWallet('/wallets/admin_xpub', 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8', 20);
    $adminRepo = new PdoAdminOperationsRepository($db);
    $adminRepo->createStore('store_xpub', 'XPUB', 'xpub-key', $profile->walletPath, $profile);
    $pdo->exec("INSERT INTO users (id,email,password_hash) VALUES (3,'c@example.test','test-only')");
    $profileC = new \BtcPayLite\ProvisionedWallet('/wallets/C', $profile->xpub, 20);
    $adminRepo->createClientStore(3,'store_C','C','key_C','/wallets/C',time(),$profileC);
    $repo->createStore(3,'store_C2','C2','key_C2','/wallets/C');
    foreach (['store_xpub','store_C','store_C2'] as $id) {
        $stored = $adminRepo->fetchStore($id);
        coreSame('xpub',$stored['address_source'],'Provisioned/shared wallet lost XPUB source');
        coreSame($profile->xpub,$stored['xpub'],'Public key was not persisted');
        coreSame('p2pkh',$stored['xpub_script_type'],'Electrum legacy xpub script type was guessed as SegWit');
    }
    $before = $rpc->calls;
    $api->createInvoice('store_C2',['amount'=>'0.001'],'key_C2');
    coreSame($before,$rpc->calls,'Provisioned client XPUB invoice contacted Electrum');
    coreSame(20,(int)$pdo->query("SELECT address_index FROM invoices WHERE store_id='store_C2'")->fetchColumn(),'Provisioning reused a pre-existing receive address');
    echo "[PASS] Admin/client stores persist and reuse XPUB metadata; resulting invoice makes zero Electrum calls\n";
    echo "[PASS] Real tenant DB boundaries: assigned wallet, store reads/mutations, webhook deletion, API keys and Electrum store source\n";
} finally { $admin->exec('DROP DATABASE `'.$name.'`'); }
