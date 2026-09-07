<?php

declare(strict_types=1);

require __DIR__ . '/support/CoreTestSupport.php';

use BtcPayLite\{AddressGenerationContext, AddressPaymentObservation, BtcStatelessInvoiceManager, BtcStatelessService,
    BtcStatelessTokenCodec, ElectrumAddressGenerator, ElectrumBlockchainProvider, ElectrumRPC, ElectrumRPCFactory,
    ElectrumWallet, InvoiceStateMachine, WalletLockManager, BlockchainProviderException};

final class CoreRecordingRPC extends ElectrumRPC
{
    public array $calls = [];
    public function __construct(private string $dir, public int $delay = 100000)
    { parent::__construct('127.0.0.1', 7777); }
    public function call(string $method, array $params = []): mixed
    {
        $this->calls[] = [$method, $params];
        if ($method === 'list_wallets') { return ['/wallets/shared']; }
        if (in_array($method, ['createnewaddress', 'add_request'], true)) {
            $marker = $this->dir . '/mutating';
            $active = @fopen($marker, 'x');
            coreCheck($active !== false, 'Stateless and Greenfield wallet mutations overlapped!');
            coreSame('/wallets/shared', $params[$this->getWalletParamKey()], 'Explicit wallet target');
            usleep($this->delay); fclose($active); unlink($marker);
            return $method === 'add_request' ? ['address' => 'bc1qcore', 'request_id' => 'req_core'] : 'bc1qcore';
        }
        return true;
    }
    public function callNetwork(string $method, array $params = []): mixed
    {
        coreSame('getaddressbalance', $method, 'Only one walletless balance RPC per refresh');
        coreIncrement($this->dir . '/queries');
        usleep($this->delay);
        return ['confirmed' => '0.00000002', 'unconfirmed' => '-0.00000001'];
    }
    public function callDaemon(string $method, array $params = []): mixed
    {
        coreCheck($method !== 'getaddressbalance', 'Blockchain command used daemon routing');
        return parent::callDaemon($method, $params);
    }
}
$dir = coreDirectory();
$token = (new BtcStatelessTokenCodec(str_repeat('s', 32)))->encode([
    'ver' => 2, 'a' => 'bc1qcore', 'r' => 'request-must-not-be-read', 'v' => '0.00000002',
    'd' => 'core', 'p' => [], 't' => time(), 'e' => time() + 600,
]);
$checkStatus = static function (string $cache, int $delay) use ($dir, $token): array {
    $rpc = new CoreRecordingRPC($dir, $delay);
    $wallet = new ElectrumWallet($rpc); // deliberately never loaded
    $provider = new ElectrumBlockchainProvider($rpc, 10, $cache);
    $manager = new BtcStatelessInvoiceManager($wallet, str_repeat('s', 32), null, $provider);
    $status = (new BtcStatelessService([], $wallet, $manager))->checkStatus($token);
    coreSame([], $rpc->calls, 'Status touched wallet/daemon commands');
    return $status;
};
$results = coreConcurrent(100, static fn (): array => $checkStatus($dir . '/cache', 300000));
coreSame(1, (int) file_get_contents($dir . '/queries'), '100 statuses must cause exactly one refresh RPC');
foreach ($results as $result) { coreSame('paid', $result['status'], 'Walletless token status'); }
echo "[PASS] 100 concurrent walletless statuses, one refresh RPC, no loaded wallet/config\n";

// Cold slow refresh: waiters time out with backpressure, never fan out into RPCs.
file_put_contents($dir . '/queries', '0');
$results = coreConcurrent(100, static function () use ($dir, $checkStatus): int {
    try { $checkStatus($dir . '/slow', 2200000); return 200; }
    catch (BtcPayLite\BtcStatelessServiceException $e) { return $e->getCode(); }
});
coreSame(1, (int) file_get_contents($dir . '/queries'), 'Timeout caused an RPC stampede');
coreCheck(in_array(503, $results, true), 'Slow refresh did not produce bounded backpressure');
echo "[PASS] Cold single-flight timeout returns 503 without refresh fan-out\n";

$rpc = new CoreRecordingRPC($dir, 0);
$observation = (new ElectrumBlockchainProvider($rpc, 2, $dir . '/semantics'))->observeAddress('bc1qnegative');
coreSame(2, $observation->getConfirmedBalanceSatoshis(), 'Confirmed current balance');
coreSame(-1, $observation->getMempoolDeltaSatoshis(), 'Signed mempool delta');
coreSame(1, $observation->getCurrentBalanceSatoshis(), 'Current net balance');
$cacheFile = glob($dir . '/semantics/*.json')[0];
$data = json_decode(file_get_contents($cacheFile), true); $data['time'] = time() - 5;
file_put_contents($cacheFile, json_encode($data));
$lock = fopen(substr($cacheFile, 0, -5) . '.lock', 'c'); flock($lock, LOCK_EX);
$queries = (int) file_get_contents($dir . '/queries');
try {
    $stale = (new ElectrumBlockchainProvider($rpc, 2, $dir . '/semantics'))->observeAddress('bc1qnegative');
    coreSame($data['time'], $stale->getObservedAt(), 'Stale fallback replaced its original timestamp');
    coreSame($queries, (int) file_get_contents($dir . '/queries'), 'Stale fallback queried Electrum');
} finally { flock($lock, LOCK_UN); fclose($lock); }
echo "[PASS] Lock timeout uses bounded valid stale cache with no RPC\n";
$zero = new AddressPaymentObservation('bc1qcore', 0, 0, 0, time());
$partial = new AddressPaymentObservation('bc1qcore', 0, 1, 1, time());
$paid = new AddressPaymentObservation('bc1qcore', 2, 0, 2, time());
foreach (['New', 'Processing', 'Expired', 'Settled'] as $from) {
    foreach ([$zero, $partial, $paid] as $obs) {
        $next = InvoiceStateMachine::next($from, 2, $obs, time() - 1, time());
        InvoiceStateMachine::assertTransition($from, $next);
        coreCheck($from !== 'Processing' || $next !== 'New', 'Processing regressed');
        coreCheck($from !== 'Settled' || $next === 'Settled', 'Settled regressed');
    }
}
coreSame('Processing', InvoiceStateMachine::next('Expired', 2, $partial, 1, time()), 'Late partial payment lost');
coreSame('Settled', InvoiceStateMachine::next('Expired', 2, $paid, 1, time()), 'Late confirmed payment lost');
echo "[PASS] State machine terminality, partial/late payment and signed balance semantics\n";

coreConcurrent(2, static function (int $i) use ($dir): bool {
    $wallet = new ElectrumWallet(new CoreRecordingRPC($dir));
    if ($i === 0) {
        // Greenfield's default factory path and stateless's default manager must share backend/domain.
        $generator = (new BtcPayLite\AddressGeneratorFactory($wallet))->createElectrumGenerator();
        $generator->generateAddress(new AddressGenerationContext('store', '/wallets/shared'));
    } else {
        (new BtcStatelessInvoiceManager($wallet, str_repeat('s', 32)))->createStatelessInvoice('0.001', 'same wallet', [], 15, '/wallets/shared');
    }
    return true;
});
$locks = new WalletLockManager($dir . '/independent');
$locks->withWalletLock('/wallets/A', static function () use ($locks): void {
    $start = microtime(true);
    $locks->withWalletLock('/wallets/B', static fn () => true, 0);
    coreCheck(microtime(true) - $start < .1, 'Wallet A blocked wallet B');
});
echo "[PASS] Shared stateless/Greenfield mutation lock and independent wallets\n";

foreach (['wallet', 'wallet_path'] as $key) {
    $configured = ElectrumRPCFactory::fromConfig(['rpc_host' => '127.0.0.1', 'rpc_port' => 7777, 'rpc_wallet_param_key' => $key]);
    coreSame($key, $configured->getWalletParamKey(), 'Production dialect configuration ignored');
    $rpc->setWallet('/wallets/wrong');
    $rpc->setWalletParamKey($key);
    $rpc->callWallet('getbalance', '/wallets/A', ['wallet' => '/wallets/A', 'wallet_path' => '/wallets/A']);
    coreSame(['getbalance', [$key => '/wallets/A']], end($rpc->calls), 'Wallet alias or active wallet leaked into explicit RPC');
}
echo "[PASS] Both explicit RPC dialects, no active-wallet routing\n";
