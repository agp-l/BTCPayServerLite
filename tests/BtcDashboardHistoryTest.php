<?php

declare(strict_types=1);

require __DIR__ . '/support/CoreTestSupport.php';

use BtcPayLite\{BtcDashboard, ElectrumRPC, ElectrumWallet};

/** Electrum's RPC history schema, including signed satoshis and null mempool timestamps. */
final class HistoryElectrumRPC extends ElectrumRPC
{
    public array $history = [];
    public array $outputs = [];
    public array $calls = [];
    public array $balance = ['confirmed' => '0.00000000', 'unconfirmed' => '0.00002001'];

    public function __construct()
    {
        parent::__construct('127.0.0.1', 7777);
    }

    public function call(string $method, array $params = []): mixed
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        return match ($method) {
            'getbalance' => $this->balance,
            'listaddresses' => !empty($params['change']) ? ['bc1change'] : ['bc1receive'],
            'onchain_history' => $this->history,
            'gettransaction' => $params['txid'],
            'deserialize' => ['outputs' => $this->outputs[$params['tx']] ?? [
                ['address' => 'bc1receive', 'value_sats' => 2001],
            ]],
            default => throw new LogicException('Unexpected RPC: ' . $method),
        };
    }
}

function historyRow(string $id, int $amount, int $confirmations, ?int $timestamp): array
{
    return [
        'txid' => str_repeat($id, 64), 'amount_sat' => $amount,
        'incoming' => $amount > 0, 'confirmations' => $confirmations,
        'timestamp' => $timestamp, 'height' => $confirmations > 0 ? 900000 : 0,
    ];
}

$rpc = new HistoryElectrumRPC();
$dashboard = new BtcDashboard(new ElectrumWallet($rpc), '/wallets', null, '/wallets/store');
$rpc->history = [
    historyRow('a', 3000, 100, 1788000000),
    historyRow('b', 4000, 3, 1788100000),
    historyRow('c', 2001, 0, null),
    historyRow('d', 1, 0, null),
];
$transactions = $dashboard->transactions();
coreSame(['d', 'c', 'b', 'a'], array_map(static fn(array $tx): string => $tx['txid'][0], $transactions), 'Pending receipts disappeared below mined payments');
coreSame('incoming', $transactions[0]['direction'], 'A one-satoshi receipt was marked outgoing');
coreSame('0.00000001', $transactions[0]['amount_btc'], 'Satoshis were interpreted as BTC');
coreSame(null, $transactions[0]['timestamp'], 'A block time was invented for a pending transaction');
coreSame(0, $transactions[0]['confirmations'], 'A mempool receipt was marked confirmed');
echo "[PASS] pending incoming receipts sort first, newest first, with exact satoshis and no fabricated block time\n";

$rpc->history[3]['confirmations'] = 1;
$rpc->history[3]['timestamp'] = 1788200000;
$transactions = $dashboard->transactions();
coreSame(['c', 'd', 'b', 'a'], array_map(static fn(array $tx): string => $tx['txid'][0], $transactions), 'Confirmation did not move the receipt into dated history');
coreSame(1, $transactions[1]['confirmations'], 'Confirmation count was lost');
echo "[PASS] the same receipt moves from pending into confirmed history on the next read\n";

$rpc->history = [historyRow('e', -5100, 0, null)];
$rpc->outputs[str_repeat('e', 64)] = [
    ['address' => 'bc1recipient', 'value_sats' => 5000],
    ['address' => 'bc1change', 'value_sats' => 900],
];
$outgoing = $dashboard->transactions()[0];
coreSame('outgoing', $outgoing['direction'], 'External payment with change was marked internal');
coreSame(5100, $outgoing['amount_sats'], 'Outgoing signed delta was lost');
coreSame(['recipient', 'change'], array_column($outgoing['outputs'], 'ownership'), 'Change was mistaken for a new receipt');
echo "[PASS] external sends stay outgoing and their change remains a wallet output\n";

$rpc->history = [historyRow('f', -100, 0, null)];
$rpc->outputs[str_repeat('f', 64)] = [
    ['address' => 'bc1receive', 'value_sats' => 5000],
    ['address' => 'bc1change', 'value_sats' => 900],
];
$internal = $dashboard->transactions();
coreSame(1, count($internal), 'A self-transfer was counted twice');
coreSame('internal', $internal[0]['direction'], 'A transfer between own addresses was marked as an external send');
coreSame('0.00000100', $internal[0]['amount_btc'], 'A self-transfer manufactured an incoming balance');
echo "[PASS] a self-transfer is one internal movement whose balance change is only the fee\n";

$rpc->outputs[str_repeat('f', 64)][] = ['address' => null, 'value_sats' => 1];
coreSame('outgoing', $dashboard->transactions()[0]['direction'], 'An unrecognized output was silently treated as owned');
$rpc->outputs[str_repeat('f', 64)] = [];
coreSame('outgoing', $dashboard->transactions()[0]['direction'], 'Missing output details imply self-transfer');
echo "[PASS] internal detection stays conservative when output details are incomplete\n";

$rpc->history = [historyRow('a', 1, 2, 1788000000) + ['bc_value' => '0.5']];
coreSame(1, $dashboard->transactions()[0]['amount_sats'], 'Decimal compatibility field overrode canonical satoshis');
$rpc->history = ['transactions' => [[
    'tx_hash' => str_repeat('b', 64), 'value' => '-0.00000001',
    'confirmations' => '2', 'timestamp' => '1788000000',
]]];
$rpc->outputs[str_repeat('b', 64)] = [['address' => 'bc1recipient', 'value_sats' => 1]];
coreSame('outgoing', $dashboard->transactions()[0]['direction'], 'Legacy signed BTC history broke');
coreSame(1, $dashboard->transactions()[0]['amount_sats'], 'Legacy decimal BTC lost precision');
echo "[PASS] canonical integer satoshis and older decimal history envelopes both work\n";

foreach ([1.5, '1e8', '999999999999999999999', 2100000000000001] as $invalid) {
    $rpc->history = [historyRow('a', 1, 0, null)];
    $rpc->history[0]['amount_sat'] = $invalid;
    $rejected = false;
    try {
        $dashboard->transactions();
    } catch (RuntimeException|InvalidArgumentException $exception) {
        $rejected = true;
    }
    coreCheck($rejected, 'Invalid satoshi amount was accepted');
}
echo "[PASS] fractional, scientific and overflowing satoshi amounts are rejected\n";

coreSame(2001, $dashboard->balance()['unconfirmed_sats'], 'Pending receipt balance was lost');
$rpc->balance['unconfirmed'] = '-0.00000001';
coreSame(-1, $dashboard->balance()['unconfirmed_sats'], 'Negative pending balance was shown as incoming');
foreach ($rpc->calls as $call) {
    if ($call['method'] !== 'deserialize') {
        coreSame('/wallets/store', $call['params']['wallet_path'] ?? null, 'History crossed into another wallet');
    }
}
echo "[PASS] signed pending balance and history stay scoped to the selected wallet\n";
echo "8 BtcDashboard history tests passed.\n";
