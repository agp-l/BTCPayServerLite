<?php

declare(strict_types=1);

require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/ElectrumRPC.php';
require_once __DIR__ . '/classes/ElectrumRpcDialect.php';
require_once __DIR__ . '/classes/CircuitBreaker.php';
require_once __DIR__ . '/classes/AddressPaymentObservation.php';
require_once __DIR__ . '/classes/BlockchainProviderInterface.php';
require_once __DIR__ . '/classes/ElectrumBlockchainProvider.php';
require_once __DIR__ . '/classes/WebhookOutboxRepository.php';
require_once __DIR__ . '/classes/PaymentWorker.php';

use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumBlockchainProvider;
use BtcPayLite\PaymentWorker;
use BtcPayLite\WebhookOutboxRepository;

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo "Configuration file missing.\n";
    exit(1);
}

$config = require $configFile;
$db = new Database($config['db']);

$electrumHost = $config['electrum']['host'] ?? '127.0.0.1';
$electrumPort = (int) ($config['electrum']['port'] ?? 7777);
$electrumUser = $config['electrum']['user'] ?? null;
$electrumPass = $config['electrum']['password'] ?? null;

$rpc = new ElectrumRPC($electrumHost, $electrumPort, $electrumUser, $electrumPass);
$blockchain = new ElectrumBlockchainProvider($rpc);
$outbox = new WebhookOutboxRepository($db);
$worker = new PaymentWorker($db, $blockchain, $outbox);

$iterations = isset($argv[1]) && $argv[1] === '--once' ? 1 : 10;
for ($i = 0; $i < $iterations; $i++) {
    $processed = $worker->runOnce();
    if ($iterations > 1 && $processed === 0) {
        sleep(2);
    }
}
