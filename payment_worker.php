<?php

declare(strict_types=1);

use BtcPayLite\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

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

    if (in_array('--check', $argv, true)) {
        $response = (new \BtcPayLite\PaymentWorkerMonitor($database->getPdo()))->snapshot();
        $response['automatic_state'] = \BtcPayLite\PaymentWorkerMonitor::automaticState($response);
        $response['scope'] = 'Database and recorded runs only; no blockchain RPC or invoice changes.';
        $statusCode = 200;
    } else {
        $response = \BtcPayLite\PaymentWorkerRunner::fromConfig($database, $config)->run('cli');
        $statusCode = ($response['busy'] || $response['success']) ? 200 : 500;
    }
    $response['timestamp'] = time();
} catch (Throwable $exception) {
    $statusCode = 500;
    $response = [
        'success' => false,
        'error' => $exception instanceof PDOException && in_array((string)$exception->getCode(), ['42S02','42S22'], true)
            ? 'Database migration required; open admin/database_upgrade.' : 'Payment worker failed; check database, RPC and cache permissions.',
        'error_type' => $exception::class,
        'timestamp' => time(),
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($statusCode === 200 ? 0 : 1);
