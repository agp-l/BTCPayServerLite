<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';
use BtcPayLite\{BtcDashboard, ElectrumRPC, ElectrumRPCException, ElectrumWallet, WalletBalanceError, WalletLockManager};

$dir=coreDirectory();$port=random_int(20000,50000);
file_put_contents($dir.'/wallets.json',json_encode(['/wallets/wallet_1','/wallets/wallet_3']));
file_put_contents($dir.'/router.php', <<<'ROUTER'
<?php
$input=json_decode(file_get_contents('php://input'),true);
$method=$input['method'];$params=$input['params']??[];
file_put_contents(__DIR__.'/requests.jsonl',json_encode(['uri'=>$_SERVER['REQUEST_URI'],'method'=>$method,'params'=>$params])."\n",FILE_APPEND|LOCK_EX);
$wallets=json_decode(file_get_contents(__DIR__.'/wallets.json'),true);
$path=$params['wallet_path']??null;$error=null;$result=null;
if($method==='list_wallets') { $result=array_map(fn($p)=>['path'=>$p,'synchronized'=>true,'unlocked'=>true],$wallets); }
elseif($method==='load_wallet') {
    if(!in_array($path,$wallets,true)) { $wallets[]=$path;file_put_contents(__DIR__.'/wallets.json',json_encode($wallets)); }
    if($path==='/wallets/race') { $error=['code'=>1,'message'=>'another loader completed']; }
    else { $result=$path; }
} elseif($method==='getbalance' && $path==='/wallets/auth_fail') { http_response_code(401);exit('Unauthorized'); }
elseif($method==='getbalance' && in_array($path,$wallets,true)) {
    $result=['confirmed'=>$path==='/wallets/wallet_1'?'0.00008827':'0.00000002','unconfirmed'=>'0.00000000'];
} elseif(in_array($method,['listaddresses','listunspent','onchain_history'],true) && in_array($path,$wallets,true)) {
    $result=$method==='onchain_history'?['transactions'=>[]]:[];
} else { $error=['code'=>1,'message'=>'wrong or absent explicit wallet']; }
header('Content-Type: application/json');
echo json_encode(['jsonrpc'=>'2.0','id'=>$input['id']]+($error===null?['result'=>$result]:['error'=>$error]));
ROUTER);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$dir.'/router.php'],
    [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$dir);
coreCheck(is_resource($server),'Could not start RPC transport fixture');
try {
    for($i=0;$i<100;++$i) {
        $socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);
        if($socket!==false) { fclose($socket);break; } usleep(20000);
    }
    $rpc=new ElectrumRPC('127.0.0.1',$port,null,null,2,1);
    $wallet=new ElectrumWallet($rpc);
    try { $rpc->callWallet('list_wallets','/wallets/wallet_1');throw new LogicException('Daemon command accepted wallet context'); }
    catch(InvalidArgumentException $e) {}
    // A held mutation lock cannot block loading/reading an already-loaded wallet.
    (new WalletLockManager())->withWalletLock('/wallets/wallet_1', static function () use ($wallet): void {
        $wallet->loadWallet('/wallets/wallet_1');
        coreSame('0.00008827', $wallet->getWalletBalanceExact('/wallets/wallet_1')['confirmed'], 'Loaded read acquired the write lock');
    });
    coreConcurrent(2, static function () use ($port): bool {
        (new ElectrumWallet(new ElectrumRPC('127.0.0.1', $port, null, null, 2, 1)))->loadWallet('/wallets/first_load');
        return true;
    });
    $dashboard=new BtcDashboard($wallet,'/wallets',null,'/wallets/wallet_1');
    $wallet->loadWallet('/wallets/wallet_2');
    $wallet->loadWallet('/wallets/wallet_2');
    coreSame('0.00008827',$dashboard->balance()['confirmed_btc'],'Bound dashboard followed mutable active wallet');
    coreSame('0.00000002',$wallet->getWalletBalanceExact('/wallets/wallet_2')['confirmed'],'Wallet 2 balance');
    coreSame([],$dashboard->transactions(),'History used wrong wallet');
    coreSame([],$dashboard->addresses()['items'],'Addresses/UTXO used wrong wallet');
    $loaded=$wallet->getLoadedWallets();sort($loaded);
    coreSame(['/wallets/first_load','/wallets/wallet_1','/wallets/wallet_2','/wallets/wallet_3'],$loaded,'Opening one wallet evicted another');
    $wallet->loadWallet('/wallets/race');
    coreCheck(in_array('/wallets/race',$wallet->getLoadedWallets(),true),'Benign load race was not accepted');
    $results=coreConcurrent(2,static function(int $i)use($port): string {
        $wallet=new ElectrumWallet(new ElectrumRPC('127.0.0.1',$port,null,null,2,1));
        $path='/wallets/wallet_'.($i+1);$wallet->loadWallet($path);
        return $wallet->getWalletBalanceExact($path)['confirmed'];
    });
    coreSame(['0.00008827','0.00000002'],$results,'Concurrent requests crossed wallet contexts');
    try { $wallet->getWalletBalanceExact('/wallets/auth_fail');throw new RuntimeException('Authentication failure hidden'); }
    catch(ElectrumRPCException $e) {
        coreSame(ElectrumRPCException::TYPE_AUTHENTICATION,$e->getType(),'Authentication error classification');
        coreSame('Chyba přihlášení',WalletBalanceError::statusLabel($e),'Authentication must not become Offline');
    }
    $requests=array_map(static fn(string $line): array=>json_decode($line,true),file($dir.'/requests.jsonl',FILE_IGNORE_NEW_LINES));
    $loads=0; $firstLoads=0;
    foreach($requests as $request) {
        coreCheck($request['method']!=='close_wallet','Normal request closed a peer wallet');
        coreSame('/',$request['uri'],'Daemon endpoint acquired mutable URL context');
        if($request['method']==='list_wallets') { coreSame([],$request['params'],'Daemon list inherited wallet context'); }
        if(in_array($request['method'],['getbalance','listaddresses','listunspent','onchain_history'],true)) {
            coreCheck(isset($request['params']['wallet_path'])&&!isset($request['params']['wallet']),'Wallet command is missing upstream wallet_path');
        }
        if ($request['method']==='load_wallet' && $request['params']['wallet_path']==='/wallets/first_load') { ++$firstLoads; }
        if($request['method']==='load_wallet'&&$request['params']['wallet_path']==='/wallets/wallet_2') { ++$loads; }
    }
    coreSame(1,$loads,'Already-loaded wallet was loaded repeatedly');
    coreSame(1,$firstLoads,'Concurrent first load performed duplicate mutations');
    echo "[PASS] Actual JSON-RPC transport: three wallets retained, explicit paths, idempotent loading, benign race, parallel isolation and exact 0.00008827 BTC\n";
} finally {
    proc_terminate($server);fclose($pipes[0]);proc_close($server);
    foreach(glob($dir.'/*') as $file) { unlink($file); } rmdir($dir);
}
try { (new ElectrumWallet(new ElectrumRPC('127.0.0.1',$port,null,null,1,1)))->loadWallet('/wallets/wallet_1');throw new RuntimeException('Dead daemon hidden'); }
catch(ElectrumRPCException $e) { coreSame('Offline',WalletBalanceError::statusLabel($e),'Actual daemon failure must be Offline'); }
echo "[PASS] A stopped RPC daemon remains a transport/Offline error\n";
