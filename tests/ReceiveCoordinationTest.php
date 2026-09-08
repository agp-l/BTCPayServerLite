<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';
use BtcPayLite\{AddressGenerationContext, AddressGenerationException, AddressGeneratorFactory, AddressIndexStoreInterface,
    AddressPaymentObservation, BlockchainProviderInterface, BtcDashboard, BtcInvoiceManager, BtcStatelessInvoiceManager,
    BtcStatelessTokenCodec, Database, ElectrumRPC, ElectrumRPCException, ElectrumWallet, GreenfieldApiRepository,
    GreenfieldApiService, InstallationSchema, WalletLockManager, WalletReceiveCoordinator, WalletReceiveRegistry,
    WalletReceiveSyncWorker, XpubAddressGenerator, XpubDerivationIdentity};
if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Receive coordination requires MariaDB (enabled in CI).\n"; return; }
$name='btcpay_receive_'.bin2hex(random_bytes(5));
$config=['db_host'=>getenv('BTCPAY_TEST_MYSQL_HOST'),'db_port'=>(int)(getenv('BTCPAY_TEST_MYSQL_PORT')?:3306),
    'db_name'=>$name,'db_user'=>getenv('BTCPAY_TEST_MYSQL_USER')?:'root','db_pass'=>getenv('BTCPAY_TEST_MYSQL_PASS')?:''];
function receiveDb(array $c): Database { return new Database($c['db_host'],$c['db_name'],$c['db_user'],$c['db_pass'],$c['db_port']); }
final class NoReceiveRpc extends ElectrumRPC {
    public function __construct() { parent::__construct('127.0.0.1',1); }
    public function call(string $method,array $params=[]): mixed { throw new LogicException('Unexpected RPC during allocation: '.$method); }
}
final class NoReceiveLock extends WalletLockManager {
    public function withWalletLock(string $walletPath,callable $callback,int $timeoutSeconds=3): mixed { throw new LogicException('Allocation acquired wallet lock'); }
}
final class ReceiveProvider implements BlockchainProviderInterface {
    public function maxObservationDurationSeconds(): int { return 1; }
    public function observeAddress(string $address,int $expectedSatoshis=0): AddressPaymentObservation
    { return new AddressPaymentObservation($address,$expectedSatoshis,0,$expectedSatoshis,time()); }
}
function receiveDerived(string $key,int $index): string {
    $store=new class($index) implements AddressIndexStoreInterface {
        public function __construct(private int $index) {}
        public function reserveNextIndex(string $storeId): int { return $this->index; }
    };
    return (new XpubAddressGenerator($key,$store,'p2pkh'))->generateAddress(new AddressGenerationContext('fixture'))->getAddress();
}
$admin=new PDO("mysql:host={$config['db_host']};port={$config['db_port']}",$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `'.$name.'`'); $admin=null;
$key='xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';
try {
    $db=receiveDb($config);$pdo=$db->getPdo();(new InstallationSchema(dirname(__DIR__).'/sql.sql'))->import($pdo);
    $stmt=$pdo->prepare("INSERT INTO stores (id,name,api_key,wallet_path,address_source,xpub,xpub_script_type,xpub_last_index) VALUES ('shared','Shared','shared-key','/wallets/shared','xpub',?,'p2pkh',2)");$stmt->execute([$key]);
    $stmt=null;$pdo=null;$db=null;
    $addresses=coreConcurrent(60,static function(int $i) use ($config): string {
        $db=receiveDb($config);$wallet=new ElectrumWallet(new NoReceiveRpc());
        $allocator=WalletReceiveCoordinator::allocatorFromConfig($config);
        if ($i%3===0) {
            $factory=new AddressGeneratorFactory($wallet,$db,new NoReceiveLock());
            $manager=new BtcInvoiceManager($wallet,str_repeat('s',32),$db,null,$factory);
            $api=new GreenfieldApiService(new GreenfieldApiRepository($db),$db,$wallet,$manager,'','https://checkout.example');
            $invoice=$api->createInvoice('shared',['amount'=>'0.00000002'],'shared-key');
            $stmt=$db->getPdo()->query('SELECT btc_address FROM invoices WHERE id='.$db->getPdo()->quote($invoice['id']));
            return $stmt->fetchColumn();
        }
        if ($i%3===1) { return (new BtcDashboard($wallet,'/wallets',null,'/wallets/shared',$allocator))->newAddress(); }
        $manager=new BtcStatelessInvoiceManager($wallet,str_repeat('s',32),null,new ReceiveProvider(),new NoReceiveLock(),$allocator);
        $invoice=$manager->createStatelessInvoice('0.00000002','stateless',[],15,'/wallets/shared');
        $token=$manager->decodeStatelessToken($invoice['token']);
        coreSame(3,$token['ver'],'Coordinated token is not address-only');coreCheck(!isset($token['r']),'Invented Electrum request ID');
        return $token['a'];
    });
    coreSame(60,count(array_unique($addresses)),'Mixed Greenfield/admin/stateless paths reused an address');
    $db=receiveDb($config);$pdo=$db->getPdo();$identity=XpubDerivationIdentity::describe($key);
    coreSame(62,(int)$pdo->query('SELECT next_index FROM xpub_address_sequences')->fetchColumn(),'Three paths did not share one sequence');
    coreSame(20,(int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),'Stateless/admin allocation unexpectedly inserted invoice rows');
    $throwingAllocator=static function(string $path): ?\BtcPayLite\GeneratedAddress { throw new LogicException('Status touched allocation/database'); };
    $kernel=new BtcStatelessInvoiceManager(new ElectrumWallet(new NoReceiveRpc()),str_repeat('s',32),null,new ReceiveProvider(),null,$throwingAllocator);
    $codec=new BtcStatelessTokenCodec(str_repeat('s',32));
    foreach ([1,2,3] as $version) {
        $payload=['ver'=>$version,'a'=>$addresses[0],'v'=>'0.00000002','d'=>'test','p'=>[],'t'=>time(),'e'=>time()+900];
        if ($version===2) { $payload['r']='legacy-request'; }
        coreSame('paid',$kernel->checkStatelessPaymentStatus($codec->encode($payload))['status'],'Old/new token status touched wallet/allocator');
    }
    try { (new AddressGeneratorFactory(new ElectrumWallet(new NoReceiveRpc()),$db))->forStore(['address_source'=>'electrum','wallet_path'=>'/wallets/shared']);throw new LogicException('Mixed legacy generator accepted'); }
    catch(AddressGenerationException $e) { coreSame(409,$e->getCode(),'Mixed source error'); }
    $snapshot=$pdo->query("SELECT * FROM stores WHERE id='shared'")->fetch();
    $generator=(new AddressGeneratorFactory(new ElectrumWallet(new NoReceiveRpc()),$db))->forStore($snapshot);
    $pdo->exec("UPDATE stores SET xpub_script_type='p2wpkh' WHERE id='shared'");
    try { $generator->generateAddress(new AddressGenerationContext('shared','/wallets/shared'));throw new LogicException('Stale generator snapshot accepted'); }
    catch(AddressGenerationException $e) { coreSame(409,$e->getCode(),'Stale receive configuration was not rejected'); }
    coreSame(62,(int)$pdo->query('SELECT next_index FROM xpub_address_sequences')->fetchColumn(),'Stale snapshot consumed an index');
    $pdo->exec("UPDATE stores SET xpub_script_type='p2pkh' WHERE id='shared'");
    $factory=new \BtcPayLite\BtcStatelessFactory(['secret_key'=>str_repeat('s',32),'rpc_host'=>'127.0.0.1','rpc_port'=>1,
        'db_host'=>'127.0.0.1','db_name'=>'must_not_open','db_port'=>0]);
    (new ReflectionProperty($factory,'blockchainProvider'))->setValue($factory,new ReceiveProvider());
    coreSame('paid',$factory->service()->checkStatus($codec->encode($payload))['status'],'Production stateless status opened invalid DB configuration');
    $rpc=new class($db,$key) extends ElectrumRPC {
        public int $known=2;public int $created=0;public bool $failAfterMutation=false;public bool $wrongKey=false;
        public function __construct(private Database $db,private string $key) { parent::__construct('127.0.0.1',1); }
        public function call(string $method,array $params=[]): mixed {
            coreCheck(!$this->db->getPdo()->inTransaction(),'Sync RPC inside database transaction');
            if ($method==='list_wallets') { return ['/wallets/shared','/wallets/other']; }
            coreSame('/wallets/shared',$params['wallet_path']??'','Sync used wrong wallet');
            if ($method==='getmpk') { return $this->wrongKey ? 'invalid-key' : $this->key; }
            if ($method==='listaddresses') { return array_map(fn(int $i): string=>receiveDerived($this->key,$i),range(0,$this->known-1)); }
            if ($method==='createnewaddress') {
                $address=receiveDerived($this->key,$this->known++);++$this->created;
                if ($this->failAfterMutation) { $this->failAfterMutation=false;throw new ElectrumRPCException('Injected lost response',ElectrumRPCException::TYPE_TRANSPORT,$method); }
                return $address;
            }
            throw new LogicException('Unexpected sync RPC: '.$method);
        }
    };
    $worker=new WalletReceiveSyncWorker($db,new ElectrumWallet($rpc));
    $first=$worker->synchronizeWallet('/wallets/shared',3,30);
    coreSame(3,$first['created'],'Sync ignored its batch limit');coreSame(5,$first['registered_next_index'],'Sync count');
    $rpc->failAfterMutation=true;
    try { $worker->synchronizeWallet('/wallets/shared',3,30);throw new LogicException('Lost mutation response hidden'); }
    catch(ElectrumRPCException $e) {}
    coreSame(6,$rpc->known,'Failure fixture did not mutate the daemon');
    $next=$worker->synchronizeWallet('/wallets/shared',1,30);
    coreSame(7,$next['registered_next_index'],'Recovery replayed an uncertain mutation');coreSame(1,$next['created'],'Recovery ignored live state');
    $pdo->exec('UPDATE wallet_receive_ranges SET registered_next_index=999');
    $rpc->known=2; // Restore/restart with an older wallet file.
    $restored=$worker->synchronizeWallet('/wallets/shared',2,30);
    coreSame(4,$restored['registered_next_index'],'Worker trusted stale progress after wallet restore');
    $rpc->wrongKey=true; $before=$rpc->created;
    try { $worker->synchronizeWallet('/wallets/shared',2,30);throw new LogicException('Wrong wallet key accepted'); }
    catch(\BtcPayLite\ElectrumWalletException $e) {}
    coreSame($before,$rpc->created,'Wrong wallet was mutated');$rpc->wrongKey=false;
    (new WalletLockManager())->withWalletLock('/wallets/shared',static function() use($worker,$rpc):void {
        $before=$rpc->created;$results=$worker->run(1,2,30);
        coreSame('busy',$results[0]['status'],'Parallel sync worker stole the wallet lock');coreSame($before,$rpc->created,'Busy sync performed RPC mutation');
    });
    $pdo->exec('DELETE FROM invoices');$pdo->exec('DELETE FROM stores');
    $after=(new WalletReceiveCoordinator($db))->allocate('/wallets/shared');
    coreSame(62,$after->getIndex(),'Deleting stores forgot the wallet receive range');
    echo "[PASS] 60 mixed creations: unique addresses, zero wallet RPC/locks, v1/v2/v3 walletless status, legacy guard and durable binding\n";
    echo "[PASS] Bounded synchronization, lost RPC response, older wallet restore, wrong key rejection and competing sync worker\n";
} finally {
    $admin=new PDO("mysql:host={$config['db_host']};port={$config['db_port']}",$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admin->exec('DROP DATABASE `'.$name.'`');
}
