<?php

declare(strict_types=1);

use BtcPayLite\ElectrumRPCException;
use BtcPayLite\WalletBalanceError;

require_once __DIR__ . '/../vendor/autoload.php';

$transport = new ElectrumRPCException(
    'connection refused',
    ElectrumRPCException::TYPE_TRANSPORT,
    'list_wallets',
    1,
    curlCode: 7
);
if (!str_contains(WalletBalanceError::message($transport), 'není dostupné')) {
    throw new RuntimeException('Transport failure does not have an actionable message.');
}
echo "[PASS] explains an unavailable Electrum daemon\n";

$missingWallet = new ElectrumRPCException(
    'wallet not found',
    ElectrumRPCException::TYPE_REMOTE,
    'load_wallet',
    2,
    rpcCode: 1
);
if (!str_contains(WalletBalanceError::message($missingWallet), 'soubor peněženky')) {
    throw new RuntimeException('Wallet load failure does not identify the assigned file.');
}
echo "[PASS] explains an unavailable wallet file\n";

echo "2 wallet balance error tests passed.\n";
