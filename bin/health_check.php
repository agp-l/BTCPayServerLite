<?php

declare(strict_types=1);

namespace BtcPayLite;

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';

$database = new Database(
    $config['db_host'],
    $config['db_name'],
    $config['db_user'],
    $config['db_pass'],
    (int) ($config['db_port'] ?? 3306)
);

$rpc = new ElectrumRPC(
    $config['rpc_host'] ?? '127.0.0.1',
    (int) ($config['rpc_port'] ?? 7777),
    $config['rpc_user'] ?? '',
    $config['rpc_pass'] ?? ''
);

$healthService = new HealthService($database, $rpc);
$report = $healthService->check();

$isJson = in_array('--json', $argv, true);

if ($isJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['status'] === 'unhealthy' ? 1 : 0);
}

echo "========================================\n";
echo "  BTC Pay Lite - System Health Check\n";
echo "========================================\n";
echo "Overall Status : " . strtoupper($report['status']) . "\n\n";

echo "[Database]\n";
echo "  Connected   : " . ($report['database']['healthy'] ? 'YES' : 'NO') . "\n";
if ($report['database']['healthy']) {
    echo "  Driver      : " . $report['database']['driver'] . "\n";
    echo "  Version     : " . $report['database']['server_version'] . "\n";
} else {
    echo "  Error       : " . ($report['database']['error'] ?? 'Unknown error') . "\n";
}

echo "\n[Electrum Daemon]\n";
echo "  Connected   : " . ($report['electrum']['healthy'] ? 'YES' : 'NO') . "\n";
echo "  Endpoint    : " . $report['electrum']['endpoint'] . "\n";
if ($report['electrum']['healthy']) {
    echo "  Version     : " . $report['electrum']['version'] . "\n";
    echo "  Synced      : " . ($report['electrum']['synced'] ? 'YES' : 'NO') . "\n";
} else {
    echo "  Notice      : " . ($report['electrum']['error'] ?? 'Unreachable') . " (XPUB stores remain operational)\n";
}

echo "\n[Cryptography & Derivation]\n";
echo "  secp256k1   : " . ($report['crypto']['secp256k1'] ? 'OK' : 'FAIL') . "\n";
echo "  GMP Ext     : " . ($report['crypto']['gmp'] ? 'LOADED' : 'NOT LOADED') . "\n";
echo "  BCMath Ext  : " . ($report['crypto']['bcmath'] ? 'LOADED' : 'NOT LOADED') . "\n";

echo "\n[Queues & Processing]\n";
if (isset($report['queues']['error'])) {
    echo "  Error       : " . $report['queues']['error'] . "\n";
} else {
    echo "  Active Invoices    : " . ($report['queues']['active_monitored_invoices'] ?? 0) . "\n";
    echo "  Pending Deliveries : " . ($report['queues']['pending_webhook_deliveries'] ?? 0) . "\n";
    echo "  Failed Deliveries  : " . ($report['queues']['failed_webhook_deliveries'] ?? 0) . "\n";
}

echo "========================================\n";

exit($report['status'] === 'unhealthy' ? 1 : 0);
