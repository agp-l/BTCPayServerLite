<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';

use BtcPayLite\{AddressPaymentObservation, BlockchainProviderException, BlockchainProviderInterface,
    Database, ElectrumBlockchainProvider, ElectrumRPC, InstallationSchema, PaymentCheckPolicy,
    PaymentWorker, WebhookDeliveryRepository};

$now = time();
coreSame(600, PaymentCheckPolicy::interval($now, $now), 'Fresh invoice cadence');
coreSame(600, PaymentCheckPolicy::interval($now - 3599, $now, 'Processing'), 'First-hour payment cadence');
coreSame(1800, PaymentCheckPolicy::interval($now - 3600, $now), 'One-hour backoff');
coreSame(3600, PaymentCheckPolicy::interval($now - 21600, $now), 'Six-hour backoff');
coreSame(3600, PaymentCheckPolicy::interval($now, $now, 'Expired'), 'Expired invoice cadence');
coreSame(null, PaymentCheckPolicy::nextCheck($now, $now, 'Settled'), 'Settled invoice scanned again');

$rpc = new class extends ElectrumRPC {
    public int $calls = 0;
    public bool $fail = false;
    public function __construct() { parent::__construct('127.0.0.1', 1); }
    public function callNetwork(string $method, array $params = []): mixed {
        ++$this->calls;
        if ($this->fail) { throw new RuntimeException('test upstream failure'); }
        return ['confirmed' => '0', 'unconfirmed' => '0'];
    }
};
$dir = coreDirectory();
$observe = static fn (int $interval = 600) => (new ElectrumBlockchainProvider($rpc, 1, $dir))
    ->observeAddressAtInterval('test-address', 1, $interval);
$observe();
$observe();
coreSame(1, $rpc->calls, 'Separate requests bypassed ten-minute cache');
$cache = glob($dir . '/*.json')[0];
$retry = substr($cache, 0, -5) . '.retry';
$ageCache = static function (int $age) use ($cache, $retry): void {
    $data = json_decode(file_get_contents($cache), true);
    $data['time'] = time() - $age;
    file_put_contents($cache, json_encode($data));
    file_put_contents($retry, '0');
};
$ageCache(599); $observe();
coreSame(1, $rpc->calls, 'Refresh occurred before ten minutes');
$ageCache(600); $observe();
coreSame(2, $rpc->calls, 'Due refresh did not occur');
$ageCache(1799); $observe(1800);
coreSame(2, $rpc->calls, 'Adaptive thirty-minute cache bypassed');
$ageCache(1800); $observe(1800);
coreSame(3, $rpc->calls, 'Thirty-minute refresh did not occur');
$ageCache(3600); $rpc->fail = true;
try { $observe(3600); throw new LogicException('Expected upstream failure'); }
catch (BlockchainProviderException) {}
$before = $rpc->calls;
try { $observe(); throw new LogicException('Expected shared cooldown'); }
catch (BlockchainProviderException) {}
coreSame($before, $rpc->calls, 'Failure caused immediate retry from another process');
coreCheck((int) file_get_contents($retry) >= time() + 3598, 'Hourly cooldown was shortened');
echo "[PASS] Adaptive cadence, cross-request minimum cache and persistent failure cooldown\n";

if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Worker cadence requires MySQL (enabled in CI).\n"; return; }
$host = getenv('BTCPAY_TEST_MYSQL_HOST'); $port = (int) (getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass = getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$admin = new PDO("mysql:host=$host;port=$port", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'btcpay_cadence_' . bin2hex(random_bytes(5)); $admin->exec('CREATE DATABASE `' . $name . '`');
try {
    $db = new Database($host, $name, $user, $pass, $port); $pdo = $db->getPdo();
    (new InstallationSchema(dirname(__DIR__) . '/sql.sql'))->import($pdo);
    $pdo->exec("INSERT INTO stores (id,name,api_key) VALUES ('cadence','cadence','test-key')");
    $pdo->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,created_at,expires_at)
        VALUES ('cadence-invoice','cadence','test-address','0.00000002',?,?)")->execute([$now, $now + 172800]);
    $provider = new class implements BlockchainProviderInterface {
        public int $calls = 0;
        public int $sats = 0;
        public bool $fail = false;
        public function maxObservationDurationSeconds(): int { return 1; }
        public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation {
            ++$this->calls;
            if ($this->fail) { throw new RuntimeException('test error'); }
            return new AddressPaymentObservation($address, $this->sats, 0, $this->sats, time());
        }
    };
    $clock = static function () use (&$now): int { return $now; };
    $worker = new PaymentWorker($db, $provider, new WebhookDeliveryRepository($db), $clock);
    coreSame(1, $worker->run()['scanned'], 'First observation missing');
    coreSame(0, $worker->run()['scanned'], 'Repeated worker scanned immediately');
    $pdo->exec("UPDATE invoices SET next_check_at=0");
    $now += 599;
    coreSame(0, $worker->run()['scanned'], 'Old schedule bypassed minimum timestamp guard');
    ++$now;
    coreSame(1, $worker->run()['scanned'], 'Ten-minute boundary did not scan');
    $now += 3000;
    $worker->run();
    coreSame($now + 1800, (int) $pdo->query('SELECT next_check_at FROM invoices')->fetchColumn(), 'One-hour invoice did not slow');
    $now += 1800;
    $provider->sats = 1; $worker->run();
    coreSame('Processing', $pdo->query('SELECT status FROM invoices')->fetchColumn(), 'Partial payment lost');
    $now += 16200; $worker->run();
    coreSame($now + 3600, (int) $pdo->query('SELECT next_check_at FROM invoices')->fetchColumn(), 'Older invoice did not slow hourly');
    $now += 3600; $provider->fail = true; $worker->run();
    coreSame(0, $worker->run()['scanned'], 'Failure retried immediately');
    $now += 600;
    coreSame(0, $worker->run()['scanned'], 'Hourly invoice failure shortened adaptive backoff');
    $now += 3000; $provider->fail = false; $provider->sats = 2; $worker->run();
    coreSame('Settled', $pdo->query('SELECT status FROM invoices')->fetchColumn(), 'Slow monitoring lost settlement');
    coreSame(null, $pdo->query('SELECT next_check_at FROM invoices')->fetchColumn(), 'Settled invoice scheduled again');
    coreSame(0, $worker->run()['scanned'], 'Settled invoice queried again');
    // Local endpoint admission is not an observation and must not postpone the
    // invoice by its hourly cadence or spin across the rest of the due queue.
    $pdo->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,created_at,expires_at) VALUES ('deferred','cadence','deferred-address','0.00000002',?,?)")->execute([$now - 22000, $now + 86400]);
    $limited = new class implements BlockchainProviderInterface {
        public int $calls = 0;
        public function maxObservationDurationSeconds(): int { return 1; }
        public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation {
            ++$this->calls;
            throw new BlockchainProviderException('Budget exhausted', 'observe_address', 503, null, 'observation_budget');
        }
    };
    $result = (new PaymentWorker($db, $limited, new WebhookDeliveryRepository($db), $clock))->run(100);
    coreSame(1, $result['failed'], 'Admission pressure hidden');
    coreSame(1, $limited->calls, 'Budget pressure spun through queue');
    $deferred = $pdo->query("SELECT last_checked_at,next_check_at,payment_processing_token FROM invoices WHERE id='deferred'")->fetch(PDO::FETCH_ASSOC);
    coreSame(null, $deferred['last_checked_at'], 'Admission failure invented an observation');
    coreSame($now + 60, (int) $deferred['next_check_at'], 'Admission failure delayed invoice for an hour');
    coreSame(null, $deferred['payment_processing_token'], 'Deferred invoice retained lease');
    $snapshot = (new BtcPayLite\PaymentWorkerMonitor($pdo))->snapshot();
    coreCheck(isset($snapshot['oldest_due_age_seconds']), 'Queue lag not exposed');
    echo "[PASS] Real DB minimum guard, 10/30/60-minute scans, partial/settled state and bounded retry\n";
} finally { $admin->exec('DROP DATABASE `' . $name . '`'); }
