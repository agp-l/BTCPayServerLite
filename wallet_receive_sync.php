<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/vendor/autoload.php';

use BtcPayLite\{Database, ElectrumRPCFactory, ElectrumWallet, WalletReceiveSyncWorker, ReceiveSyncDiagnostics};
$options = getopt('', ['wallet:', 'limit:', 'max-addresses:', 'budget:', 'check-db', 'help']);
if (isset($options['help'])) {
    echo "Usage: php wallet_receive_sync.php [--wallet=/absolute/path] [--limit=2] [--max-addresses=25] [--budget=10] [--check-db]\n";
    echo "Registers reserved receive addresses in bounded batches. Does not issue invoices, sign, close wallets or change the gap limit.\n";
    echo "--check-db verifies the configured database without Electrum RPC or database changes.\n";
    exit;
}
try {
    $config = require __DIR__ . '/config.php';
    $db = new Database($config['db_host'],$config['db_name'],$config['db_user'],$config['db_pass'],(int)($config['db_port']??3306));
    $diagnostics = ReceiveSyncDiagnostics::inspect($db->getPdo());
    if (isset($options['check-db']) || !$diagnostics['ok']) {
        fwrite(isset($options['check-db']) ? STDOUT : STDERR, json_encode($diagnostics,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        exit($diagnostics['ok'] ? 0 : 1);
    }
    $worker = new WalletReceiveSyncWorker($db,new ElectrumWallet(ElectrumRPCFactory::fromConfig($config)));
    $limit = (int)($options['limit']??2); $batch = (int)($options['max-addresses']??25); $budget = (int)($options['budget']??10);
    $results = isset($options['wallet'])
        ? [$worker->synchronizeWallet((string)$options['wallet'],$batch,$budget)]
        : $worker->run($limit,$batch,$budget);
    $output=['wallets'=>$results];
    if ($results===[]) {
        $registered=(int)$db->getPdo()->query('SELECT COUNT(*) FROM wallet_receive_ranges')->fetchColumn();
        $output['idle_reason']=$registered===0 ? 'no_registered_wallets' : 'no_wallets_due';
        $output['registered_wallets']=$registered;
        $output['hint']=$registered===0
            ? 'No receive ranges are registered. This worker does not discover daemon wallets. For an existing XPUB store, run --wallet=/absolute/wallet/path to initialize its binding. Legacy Electrum stores first need the explicit XPUB repair workflow.'
            : 'No registered wallet needs work now. Completed ranges are checked again after five minutes; --wallet forces a specific wallet check.';
    }
    echo json_encode($output,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit(count(array_filter($results,static fn(array $r): bool=>$r['status']==='failed')) ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR,ReceiveSyncDiagnostics::error($exception)."\n"); exit(1);
}
