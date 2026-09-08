<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/vendor/autoload.php';

use BtcPayLite\{Database, ElectrumRPCFactory, ElectrumWallet, WalletReceiveSyncWorker};
$options = getopt('', ['wallet:', 'limit:', 'max-addresses:', 'budget:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php wallet_receive_sync.php [--wallet=/absolute/path] [--limit=2] [--max-addresses=25] [--budget=10]\n";
    echo "Registers reserved receive addresses in bounded batches. Does not issue invoices, sign, close wallets or change the gap limit.\n";
    exit;
}
try {
    $config = require __DIR__ . '/config.php';
    $db = new Database($config['db_host'],$config['db_name'],$config['db_user'],$config['db_pass'],(int)($config['db_port']??3306));
    $worker = new WalletReceiveSyncWorker($db,new ElectrumWallet(ElectrumRPCFactory::fromConfig($config)));
    $limit = (int)($options['limit']??2); $batch = (int)($options['max-addresses']??25); $budget = (int)($options['budget']??10);
    $results = isset($options['wallet'])
        ? [$worker->synchronizeWallet((string)$options['wallet'],$batch,$budget)]
        : $worker->run($limit,$batch,$budget);
    echo json_encode(['wallets'=>$results],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit(count(array_filter($results,static fn(array $r): bool=>$r['status']==='failed')) ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR,'Receive synchronization failed: '.$exception::class."\n"); exit(1);
}
