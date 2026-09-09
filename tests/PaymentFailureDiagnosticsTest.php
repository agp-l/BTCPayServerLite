<?php

declare(strict_types=1);
require __DIR__.'/support/CoreTestSupport.php';
use BtcPayLite\{ElectrumRPC, ElectrumRPCException, ElectrumBlockchainProvider, BlockchainProviderException, PaymentFailureDiagnostics};
final class DiagnosticRPC extends ElectrumRPC
{
    public int $calls=0;
    public mixed $result=['confirmed'=>'0','unconfirmed'=>'0'];
    public ?Throwable $failure=null;
    public function __construct() { parent::__construct('127.0.0.1',1); }
    public function callNetwork(string $method,array $params=[]): mixed {
        ++$this->calls;
        if ($this->failure!==null) { throw $this->failure; }
        return $this->result;
    }
}
$dir=coreDirectory();
$expect=static function(string $reason,callable $run): void {
    try { $run(); throw new LogicException('Expected failure '.$reason); }
    catch (BlockchainProviderException $e) {
        coreSame(503,$e->getCode(),'Backpressure contract changed');
        coreSame($reason,PaymentFailureDiagnostics::code($e),'Wrong diagnostic');
        coreCheck(!str_contains(json_encode(PaymentFailureDiagnostics::details($e)),'secret'),'Raw upstream details exposed');
    }
};
try {
    $rpc=new DiagnosticRPC();
    file_put_contents($dir.'/file','not a directory');
    $expect('cache_directory',fn()=>(new ElectrumBlockchainProvider($rpc,2,$dir.'/file/cache'))->observeAddress('test-address'));
    coreSame(0,$rpc->calls,'Unavailable cache queried RPC');
    $key=hash('sha256','balance-v2|'.$rpc->getEndpoint().'|test-address');
    mkdir($dir.'/lock'); mkdir($dir.'/lock/'.$key.'.lock');
    $expect('cache_lock_open',fn()=>(new ElectrumBlockchainProvider($rpc,2,$dir.'/lock'))->observeAddress('test-address'));
    coreSame(0,$rpc->calls,'Failed lock open queried RPC');
    mkdir($dir.'/busy'); $lock=fopen($dir.'/busy/'.$key.'.lock','c'); flock($lock,LOCK_EX);
    try { $expect('cache_lock_timeout',fn()=>(new ElectrumBlockchainProvider($rpc,2,$dir.'/busy'))->observeAddress('test-address')); }
    finally { flock($lock,LOCK_UN); fclose($lock); }
    coreSame(0,$rpc->calls,'Lock timeout queried RPC');
    mkdir($dir.'/write'); mkdir($dir.'/write/'.$key.'.json');
    $expect('cache_write',fn()=>(new ElectrumBlockchainProvider($rpc,2,$dir.'/write'))->observeAddress('test-address'));
    foreach (['authentication'=>'rpc_authentication','transport'=>'rpc_timeout','protocol'=>'rpc_protocol','remote'=>'rpc_method_unavailable'] as $type=>$reason) {
        $rpc->failure=new ElectrumRPCException('secret response',$type,'getaddressbalance',httpStatus:401,rpcCode:-32601,rpcData:['secret'=>'raw'],curlCode:28);
        $expect($reason,fn()=>(new ElectrumBlockchainProvider($rpc,2,$dir.'/'.$type))->observeAddress('test-address'));
    }
    $rpc->failure=null; $rpc->result=['unexpected'=>true];
    $expect('invalid_balance',fn()=>(new ElectrumBlockchainProvider($rpc,2,$dir.'/invalid'))->observeAddress('test-address'));
    $rpc->result=['confirmed'=>'not-money','unconfirmed'=>'0'];
    $expect('invalid_balance',fn()=>(new ElectrumBlockchainProvider($rpc,2,$dir.'/amount'))->observeAddress('test-address'));
    echo "[PASS] Cache/RPC diagnostics preserve backpressure, avoid uncoalesced RPC and omit secrets\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($dir);
}
