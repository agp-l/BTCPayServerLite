<?php

declare(strict_types=1);

use BtcPayLite\Database;
use BtcPayLite\ElectrumBlockchainProvider;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\PaymentWorker;
use BtcPayLite\WebhookDeliveryRepository;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';

try {
    $config = require __DIR__ . '/config.php';
    if (!is_array($config)) {
        throw new RuntimeException('Configuration file could not be loaded.');
    }

    $databasePort = (int) ($config['db_port'] ?? 3306);
    $database = new Database(
        (string) $config['db_host'],
        (string) $config['db_name'],
        (string) $config['db_user'],
        (string) ($config['db_pass'] ?? ''),
        $databasePort
    );

    $rpcScheme = (string) ($config['rpc_scheme'] ?? 'http');
    $rpc = new ElectrumRPC(
        (string) $config['rpc_host'],
        (int) ($config['rpc_port'] ?? 7777),
        (string) ($config['rpc_user'] ?? ''),
        (string) ($config['rpc_pass'] ?? ''),
        30,
        5,
        strtolower($rpcScheme)
    );

    $blockchain = new ElectrumBlockchainProvider($rpc);
    $webhookRepository = new WebhookDeliveryRepository($database);
    $worker = new PaymentWorker($database, $blockchain, $webhookRepository);

    $stats = $worker->run(100);
    $statusCode = 200;
    $response = [
        'success' => true,
        'stats' => $stats,
        'timestamp' => time(),
    ];
} catch (Throwable $exception) {
    $statusCode = 500;
    $response = [
        'success' => false,
        'error' => $exception->getMessage(),
        'timestamp' => time(),
    ];
}

if (!$isCli) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($statusCode === 200 ? 0 : 1);
