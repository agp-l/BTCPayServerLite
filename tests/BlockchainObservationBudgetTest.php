<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';
use BtcPayLite\{BlockchainObservationBudget, BlockchainProviderException, ElectrumBlockchainProvider, ElectrumRPC, ElectrumRPCException};

$dir = coreDirectory(); $now = 1_700_000_000;
$clock = static function () use (&$now): int { return $now; };
$budget = new BlockchainObservationBudget($dir, 'endpoint', 3, $clock);
$a = $budget->acquire(1); $b = $budget->acquire(1);
try { $budget->acquire(1); throw new LogicException('Concurrency exceeded.'); }
catch (BlockchainProviderException $e) { coreSame('observation_concurrency', $e->getReason(), 'Wrong concurrency cause'); }
$budget->release($a); $budget->release($b);
$c = $budget->acquire(1); $budget->release($c);
try { $budget->acquire(1); throw new LogicException('Rolling budget exceeded.'); }
catch (BlockchainProviderException $e) { coreSame('observation_budget', $e->getReason(), 'Wrong budget cause'); }
$now += 59;
try { (new BlockchainObservationBudget($dir, 'endpoint', 3, $clock))->acquire(1); throw new LogicException('New process bypassed budget.'); }
catch (BlockchainProviderException) {}
++$now; $token = $budget->acquire(1); // Exact rolling-window boundary, no sleep.
$budget->release($token, new ElectrumRPCException('private error', 'authentication', 'getaddresshistory', 1, 401));
try { $budget->acquire(1); throw new LogicException('Upstream failure circuit bypassed.'); }
catch (BlockchainProviderException $e) { coreSame('upstream_circuit_open', $e->getReason(), 'Wrong circuit cause'); }
$now += 60; $token = $budget->acquire(1); $budget->release($token);
$deadA = $budget->acquire(1); $deadB = $budget->acquire(1); // Simulated killed processes.
$now += 61; $token = $budget->acquire(1); $budget->release($token);
echo "[PASS] Shared rolling budget, endpoint circuit, fixed concurrency and killed-observer recovery\n";

$rpc = new class extends ElectrumRPC {
    public int $calls = 0;
    public function __construct() { parent::__construct('127.0.0.1', 1); }
    public function callNetwork(string $method, array $params = []): mixed { ++$this->calls; return ['confirmed'=>'0','unconfirmed'=>'0']; }
};
$cache = coreDirectory(); $limited = 0; $started = microtime(true);
for ($i = 0; $i < 1000; ++$i) {
    try { (new ElectrumBlockchainProvider($rpc, 600, $cache))->observeAddress('load-address-' . $i); }
    catch (BlockchainProviderException $e) {
        coreSame('observation_budget', BtcPayLite\PaymentFailureDiagnostics::code($e), 'Load failure lost typed budget cause'); ++$limited;
    }
}
coreSame(60, $rpc->calls, '1000 different cold addresses exceeded aggregate budget');
coreSame(940, $limited, 'Unadmitted addresses were sent upstream');
(new ElectrumBlockchainProvider($rpc, 600, $cache))->observeAddress('load-address-0');
coreSame(60, $rpc->calls, 'Cached status consumed another admission/RPC');
echo '[PASS] 1000 distinct cold requests: 60 mock RPCs, 940 bounded deferrals; ' . round(microtime(true)-$started, 3) . "s fixture time (not production throughput)\n";

$parallel = coreDirectory();
$results = coreConcurrent(100, static function (int $i) use ($parallel): int {
    $rpc = new class($parallel) extends ElectrumRPC {
        public function __construct(private string $dir) { parent::__construct('127.0.0.1', 1); }
        public function callNetwork(string $method, array $params = []): mixed {
            $counter = fopen($this->dir . '/active', 'c+'); flock($counter, LOCK_EX);
            $data = json_decode(stream_get_contents($counter), true) ?: ['active'=>0,'max'=>0,'calls'=>0];
            ++$data['active']; ++$data['calls']; $data['max'] = max($data['max'], $data['active']);
            rewind($counter); ftruncate($counter, 0); fwrite($counter, json_encode($data)); flock($counter, LOCK_UN);
            usleep(100000);
            flock($counter, LOCK_EX); rewind($counter); $data = json_decode(stream_get_contents($counter), true);
            --$data['active']; rewind($counter); ftruncate($counter, 0); fwrite($counter, json_encode($data)); flock($counter, LOCK_UN); fclose($counter);
            return ['confirmed'=>'0','unconfirmed'=>'0'];
        }
    };
    try { (new ElectrumBlockchainProvider($rpc, 600, $parallel))->observeAddress('parallel-address-' . $i); return 200; }
    catch (BlockchainProviderException) { return 503; }
});
$data = json_decode(file_get_contents($parallel . '/active'), true);
coreSame(2, $data['max'], 'Parallel endpoint exceeded two active observers');
coreSame(0, $data['active'], 'Admission leaked after successful observers');
coreSame(count(array_filter($results, static fn ($code) => $code===200)), $data['calls'], 'Denied callers queried upstream');
echo "[PASS] 100 distinct concurrent PHP processes: maximum two active observations, no denied-call fan-out\n";
