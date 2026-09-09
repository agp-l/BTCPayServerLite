<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';

use BtcPayLite\{AddressGenerationException, ElectrumRPCException, StoreCreationException, WalletAddressError, WalletBusyException};

$secret = 'PRIVATE-MATERIAL-MUST-NOT-APPEAR';
$pdo = new PDOException($secret);
$pdo->errorInfo = ['42S02', 1146, $secret];
$wrapped = new AddressGenerationException($secret, 'xpub', 500, $pdo);
coreCheck(str_contains(WalletAddressError::message($wrapped), 'address_database_1146'), 'Wrapped schema failure was hidden');
foreach ([
    [$wrapped, 'address_database_1146'],
    [new StoreCreationException('xpub_gmp_missing', $secret), 'xpub_gmp_missing'],
    [new AddressGenerationException($secret, 'electrum', 503, new WalletBusyException($secret)), 'address_wallet_lock'],
    [new AddressGenerationException($secret, 'electrum', 500, new ElectrumRPCException($secret, ElectrumRPCException::TYPE_REMOTE, 'createnewaddress')), 'address_electrum_rpc'],
    [new AddressGenerationException($secret, 'xpub', 409), 'address_xpub'],
    [new RuntimeException($secret), 'address_generation_failed'],
] as [$error, $code]) {
    $message = WalletAddressError::message($error);
    coreCheck(str_contains($message, '[' . $code . ']'), 'Wrong address failure category');
    coreCheck(!str_contains($message, $secret), 'Error disclosed raw exception material');
}
$file = tempnam(sys_get_temp_dir(), 'address-error-');
$old = ini_get('error_log');
try {
    ini_set('error_log', $file);
    WalletAddressError::log($wrapped);
    $log = file_get_contents($file);
    coreCheck(str_contains($log, '1146') && str_contains($log, 'PDOException'), 'Log lost wrapped DB cause');
    coreCheck(!str_contains($log, $secret), 'Log disclosed exception material');
} finally { ini_set('error_log', $old); unlink($file); }
echo "[PASS] Address errors distinguish wrapped SQL, XPUB runtime, wallet lock and RPC without leaking messages\n";
