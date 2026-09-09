<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/vendor/autoload.php';

use BtcPayLite\{Database, ElectrumRPCFactory, ElectrumWallet, WalletXpubReader, XpubDerivationIdentity};

$options = getopt('', ['store:', 'apply', 'maintenance', 'help']);
if (isset($options['help']) || !isset($options['store'])) {
    fwrite(STDOUT, "Usage: php repair_store_xpub.php --store=ID [--apply --maintenance]\n"
        . "Default: inspect one wallet, print a plan, do not change DB.\n"
        . "Apply requires paused invoice/address writers for this wallet (--maintenance).\n");
    exit(isset($options['help']) ? 0 : 2);
}
try {
    if (isset($options['apply']) && !isset($options['maintenance'])) {
        throw new RuntimeException('Pause invoice/address creation for this wallet, then add --maintenance.');
    }
    $config = require __DIR__ . '/config.php';
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass'], (int) ($config['db_port'] ?? 3306));
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ?');
    $stmt->execute([$options['store']]); $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($before) || trim((string) $before['wallet_path']) === '') { throw new RuntimeException('Store with an explicit wallet path was not found.'); }
    // All Electrum operations are outside the short write transaction.
    $receive = (new WalletXpubReader(new ElectrumWallet(ElectrumRPCFactory::fromConfig($config))))->read($before['wallet_path']);
    $identity = XpubDerivationIdentity::describe($receive->xpub);
    if (trim((string) $before['xpub']) !== '' && XpubDerivationIdentity::describe($before['xpub'])['id'] !== $identity['id']) {
        throw new RuntimeException('Stored XPUB belongs to another key; automatic replacement refused.');
    }
    if ($before['address_source'] === 'xpub' && \BtcPayLite\XpubAddressGenerator::requireScriptType($before['xpub_script_type']) !== $receive->scriptType) {
        throw new RuntimeException('Stored XPUB script policy differs from the wallet; automatic replacement refused.');
    }
    $floor = max($receive->nextIndex, (int) $before['xpub_last_index']);
    $issued = $pdo->prepare('SELECT MAX(i.address_index) FROM invoices i INNER JOIN stores s ON s.id=i.store_id WHERE s.wallet_path=?');
    $issued->execute([$before['wallet_path']]); $lastIssued = $issued->fetchColumn();
    if ($lastIssued !== null && $lastIssued !== false) { $floor = max($floor, (int) $lastIssued + 1); }
    if (isset($options['apply'])) {
        $db->transactional(function (PDO $pdo) use ($before, $receive, $identity, &$floor): void {
            $stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ? FOR UPDATE'); $stmt->execute([$before['id']]);
            if ($stmt->fetch(PDO::FETCH_ASSOC) !== $before) { throw new RuntimeException('Store changed during inspection; retry.'); }
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(xpub_last_index), 0) FROM stores WHERE xpub IN (?, ?, ?, ?, ?, ?)');
            $stmt->execute($identity['aliases']); $floor = max($floor, (int) $stmt->fetchColumn());
            $stmt = $pdo->prepare('INSERT INTO xpub_address_sequences (key_hash,next_index) VALUES (?,?) ON DUPLICATE KEY UPDATE next_index=GREATEST(next_index,VALUES(next_index))');
            $stmt->execute([$identity['id'], $floor]);
            $stmt = $pdo->prepare('SELECT next_index FROM xpub_address_sequences WHERE key_hash=? FOR UPDATE');
            $stmt->execute([$identity['id']]); $floor = max($floor, (int) $stmt->fetchColumn());
            (new \BtcPayLite\WalletReceiveRegistry($pdo))->bind($receive->walletPath, $receive->xpub, $receive->scriptType, $floor);
            $stmt = $pdo->prepare("UPDATE stores SET address_source='xpub', xpub=?, xpub_script_type=?, xpub_last_index=? WHERE id=?");
            $stmt->execute([$receive->xpub, $receive->scriptType, $floor, $before['id']]);
        });
    }
    fwrite(STDOUT, json_encode(['store_id'=>$before['id'], 'key_hash'=>$identity['id'], 'script_type'=>$receive->scriptType,
        'minimum_next_index'=>$floor, 'applied'=>isset($options['apply'])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $exception) {
    // No config, RPC credentials, seed material or raw daemon response in output.
    fwrite(STDERR, 'XPUB repair failed (' . $exception::class . '): ' . ($exception instanceof \BtcPayLite\ElectrumRPCException ? 'Electrum inspection failed; check daemon configuration.' : $exception->getMessage()) . "\n");
    exit(1);
}
