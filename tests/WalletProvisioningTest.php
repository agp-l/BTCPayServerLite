<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';
use BtcPayLite\{AddressGenerationContext, AddressIndexStoreInterface, ElectrumCliWalletProvisioner, ElectrumWalletException,
    ProvisionedWallet, WalletXpubReader, XpubAddressGenerator, XpubDerivationIdentity};

$key = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';
$indices = new class implements AddressIndexStoreInterface {
    private int $next=0;
    public function reserveNextIndex(string $storeId): int { return $this->next++; }
};
$generator = new XpubAddressGenerator($key,$indices,'p2pkh');
$addresses = [$generator->generateAddress(new AddressGenerationContext('test'))->getAddress(),$generator->generateAddress(new AddressGenerationContext('test'))->getAddress()];
$dir = coreDirectory(); mkdir($dir.'/wallets'); mkdir($dir.'/data');
file_put_contents($dir.'/public.json',json_encode(['key'=>$key,'addresses'=>$addresses]));
$script = <<<'CLI'
<?php
file_put_contents(__DIR__.'/calls.jsonl',json_encode($argv)."\n",FILE_APPEND);
$wallet=$argv[array_search('-w',$argv,true)+1];
$public=json_decode(file_get_contents(__DIR__.'/public.json'),true);
if(in_array('create',$argv,true)) { file_put_contents($wallet,'test wallet'); echo 'SECRET-SEED-MUST-NOT-BE-RETAINED'; }
elseif(in_array('getmpk',$argv,true)) { echo $public['key']; }
elseif(in_array('listaddresses',$argv,true)) { echo json_encode($public['addresses']); }
else { exit(1); }
CLI;
file_put_contents($dir.'/electrum', '#!'.PHP_BINARY."\n".$script); chmod($dir.'/electrum',0700);
try {
    $provisioner=new ElectrumCliWalletProvisioner($dir.'/electrum',$dir.'/data',$dir.'/wallets');
    $receive=$provisioner->provision('store_'.str_repeat('a',32));
    coreSame('xpub',$receive->columns()['address_source'],'Provisioning did not choose XPUB');
    coreSame('p2pkh',$receive->scriptType,'Electrum public-key script mismatch');
    coreSame(2,$receive->nextIndex,'Provisioning lost existing receive high water');
    coreCheck(!str_contains(serialize($receive),'SECRET-SEED'),'Provisioning retained seed output');
    $calls=array_map(static fn($s)=>json_decode($s,true),file($dir.'/calls.jsonl',FILE_IGNORE_NEW_LINES));
    coreSame(3,count($calls),'Expected one create and two public offline inspections');
    foreach($calls as $call) { coreCheck(in_array('--offline',$call,true),'Provisioning contacted the daemon'); }
    $provisioner->discard($receive->walletPath);
    file_put_contents($dir.'/public.json',json_encode(['key'=>$key,'addresses'=>['wrong-address']]));
    try { $provisioner->provision('store_'.str_repeat('b',32));throw new LogicException('Wrong receive branch accepted'); }
    catch(ElectrumWalletException $e) { coreSame('unsupported_xpub',$e->getOperation(),'Wrong branch error category'); }
    coreSame([],glob($dir.'/wallets/*'),'Failed provisioning left an unregistered wallet');
    foreach(['', 'xprv-invalid', 'Zpub-multisig'] as $unsupported) {
        try { new ProvisionedWallet('/wallets/test',$unsupported);throw new LogicException('Unsupported key accepted'); }
        catch(ElectrumWalletException $e) {}
    }
    $identity=XpubDerivationIdentity::describe($key);
    foreach($identity['aliases'] as $alias) { coreSame($identity['id'],XpubDerivationIdentity::describe($alias)['id'],'Public-key alias restarted sequence'); }
    echo "[PASS] Actual CLI provisioning: public-only result, offline export, script/branch validation, cleanup, alias identity\n";
} finally {
    foreach(glob($dir.'/wallets/*') as $file) { unlink($file); }
    rmdir($dir.'/wallets'); rmdir($dir.'/data');
    foreach(glob($dir.'/*') as $file) { unlink($file); } rmdir($dir);
}
