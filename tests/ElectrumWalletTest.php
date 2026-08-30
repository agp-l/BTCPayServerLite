<?php

declare(strict_types=1);

use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumRPCException;
use BtcPayLite\ElectrumWallet;

require dirname(__DIR__) . '/vendor/autoload.php';

final class RecordingElectrumRPC extends ElectrumRPC
{
    /** @var array<string, list<mixed>> */
    private array $responses;

    /** @var list<array{scope: string, method: string, params: array, wallet_path?: string}> */
    public array $calls = [];

    /** @param array<string, list<mixed>> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function call(string $method, array $params = []): mixed
    {
        $this->calls[] = [
            'scope' => 'daemon',
            'method' => $method,
            'params' => $params,
        ];

        return $this->nextResponse($method);
    }

    public function callForWallet(string $method, string $walletPath, array $params = []): mixed
    {
        $this->calls[] = [
            'scope' => 'wallet',
            'method' => $method,
            'params' => $params,
            'wallet_path' => $walletPath,
        ];

        return $this->nextResponse($method);
    }

    private function nextResponse(string $method): mixed
    {
        if (!isset($this->responses[$method]) || $this->responses[$method] === []) {
            throw new RuntimeException("No fake response configured for '{$method}'.");
        }

        $response = array_shift($this->responses[$method]);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(string $expectedClass, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException(
            $message . ' Unexpected exception: ' . $throwable::class,
            0,
            $throwable
        );
    }

    throw new RuntimeException($message . ' No exception was thrown.');
}

$tests = [];

$tests['loads one wallet without closing another wallet'] = static function (): void {
    $rpc = new RecordingElectrumRPC([
        'list_wallets' => [[['path' => '/wallets/other']]],
        'load_wallet' => ['/wallets/store'],
    ]);
    $wallet = new ElectrumWallet($rpc);

    $wallet->loadWallet('/wallets/store');

    assertSameValue('/wallets/store', $wallet->getActiveWalletPath(), 'The requested wallet was not selected.');
    assertSameValue('list_wallets', $rpc->calls[0]['method'], 'Loaded wallets must be inspected first.');
    assertSameValue(
        ['wallet_path' => '/wallets/store'],
        $rpc->calls[1]['params'],
        'load_wallet did not receive the requested path.'
    );

    foreach ($rpc->calls as $call) {
        assertTrueValue($call['method'] !== 'close_wallet', 'loadWallet() must not close another wallet.');
    }
};

$tests['reuses a wallet already loaded in the daemon'] = static function (): void {
    $rpc = new RecordingElectrumRPC([
        'list_wallets' => [[['path' => '/wallets/store']]],
    ]);
    $wallet = new ElectrumWallet($rpc);

    $wallet->loadWallet('/wallets/store');

    assertSameValue(1, count($rpc->calls), 'An already loaded wallet must not be loaded again.');
};

$tests['scopes wallet commands with an explicit wallet path'] = static function (): void {
    $rpc = new RecordingElectrumRPC([
        'list_wallets' => [[['path' => '/wallets/store']]],
        'getbalance' => [['confirmed' => '1.25000000', 'unconfirmed' => '-0.01000000']],
    ]);
    $wallet = new ElectrumWallet($rpc);
    $wallet->loadWallet('/wallets/store');

    $balance = $wallet->getWalletBalance();

    assertSameValue(['confirmed' => 1.25, 'unconfirmed' => -0.01], $balance, 'Balance normalization failed.');
    assertSameValue('wallet', $rpc->calls[1]['scope'], 'getbalance must be wallet-scoped.');
    assertSameValue('/wallets/store', $rpc->calls[1]['wallet_path'], 'getbalance used the wrong wallet.');
};

$tests['accepts the boolean validateaddress response'] = static function (): void {
    $rpc = new RecordingElectrumRPC(['validateaddress' => [true]]);
    $wallet = new ElectrumWallet($rpc);

    assertTrueValue($wallet->validateAddress('bc1qexample'), 'A true validateaddress result was rejected.');
    assertSameValue('daemon', $rpc->calls[0]['scope'], 'validateaddress is a daemon-wide command.');
};

$tests['preserves exact satoshis in address balances'] = static function (): void {
    $rpc = new RecordingElectrumRPC([
        'getaddressbalance' => [[
            'confirmed' => '0.10000001',
            'unconfirmed' => '-0.00000001',
        ]],
    ]);
    $wallet = new ElectrumWallet($rpc);

    $balance = $wallet->getAddressBalanceExact('bc1qexample');

    assertSameValue(
        ['confirmed' => '0.10000001', 'unconfirmed' => '-0.00000001'],
        $balance,
        'The exact balance lost satoshi precision.'
    );
    assertSameValue('daemon', $rpc->calls[0]['scope'], 'getaddressbalance must be daemon-wide.');
};

$tests['uses the Electrum expiry parameter for payment requests'] = static function (): void {
    $rpc = new RecordingElectrumRPC([
        'list_wallets' => [[['path' => '/wallets/store']]],
        'add_request' => [['address' => 'bc1qrequest']],
    ]);
    $wallet = new ElectrumWallet($rpc);
    $wallet->loadWallet('/wallets/store');

    $wallet->createPaymentRequest('0.001', 'Order 42', 900);

    assertSameValue(
        ['amount' => '0.001', 'memo' => 'Order 42', 'expiry' => 900],
        $rpc->calls[1]['params'],
        'add_request parameters do not match Electrum.'
    );
    assertSameValue('wallet', $rpc->calls[1]['scope'], 'add_request must be wallet-scoped.');
};

$tests['gets and deletes payment requests in the active wallet'] = static function (): void {
    $rpc = new RecordingElectrumRPC([
        'list_wallets' => [[['path' => '/wallets/store']]],
        'get_request' => [['request_id' => 'a1b2c3d4e5', 'status' => 0]],
        'delete_request' => [null],
    ]);
    $wallet = new ElectrumWallet($rpc);
    $wallet->loadWallet('/wallets/store');

    $request = $wallet->getPaymentRequest('a1b2c3d4e5');
    $wallet->deletePaymentRequest('a1b2c3d4e5');

    assertSameValue('a1b2c3d4e5', $request['request_id'], 'get_request returned the wrong request.');
    assertSameValue('wallet', $rpc->calls[1]['scope'], 'get_request must be wallet-scoped.');
    assertSameValue(
        ['request_id' => 'a1b2c3d4e5'],
        $rpc->calls[2]['params'],
        'delete_request received the wrong request ID.'
    );
};

$tests['falls back to history only for method-not-found'] = static function (): void {
    $missingMethod = new ElectrumRPCException(
        'Method not found',
        ElectrumRPCException::TYPE_REMOTE,
        'onchain_history',
        1,
        200,
        -32601
    );
    $rpc = new RecordingElectrumRPC([
        'list_wallets' => [[['path' => '/wallets/store']]],
        'onchain_history' => [$missingMethod],
        'history' => [[['txid' => str_repeat('a', 64)]]],
    ]);
    $wallet = new ElectrumWallet($rpc);
    $wallet->loadWallet('/wallets/store');

    $history = $wallet->listTransactions();

    assertSameValue(str_repeat('a', 64), $history[0]['txid'], 'The history fallback returned bad data.');
    assertSameValue('history', $rpc->calls[2]['method'], 'The legacy history fallback was not used.');
    assertSameValue('wallet', $rpc->calls[2]['scope'], 'The history fallback must be wallet-scoped.');
};

$tests['keeps payto scoped and broadcast daemon-wide'] = static function (): void {
    $txid = str_repeat('b', 64);
    $rpc = new RecordingElectrumRPC([
        'list_wallets' => [[['path' => '/wallets/store']]],
        'payto' => ['deadbeef'],
        'broadcast' => [$txid],
    ]);
    $wallet = new ElectrumWallet($rpc);
    $wallet->loadWallet('/wallets/store');

    $actualTxid = $wallet->sendPayment('bc1qdestination', '0.00100000', null, 2);

    assertSameValue($txid, $actualTxid, 'sendPayment() returned the wrong transaction ID.');
    assertSameValue('wallet', $rpc->calls[1]['scope'], 'payto must be wallet-scoped.');
    assertSameValue(
        ['destination' => 'bc1qdestination', 'amount' => '0.00100000', 'feerate' => 2],
        $rpc->calls[1]['params'],
        'payto parameters are incorrect.'
    );
    assertSameValue('daemon', $rpc->calls[2]['scope'], 'broadcast must not receive wallet_path.');
};

$tests['rejects an amount with sub-satoshi precision'] = static function (): void {
    $rpc = new RecordingElectrumRPC([
        'list_wallets' => [[['path' => '/wallets/store']]],
    ]);
    $wallet = new ElectrumWallet($rpc);
    $wallet->loadWallet('/wallets/store');

    assertThrows(
        InvalidArgumentException::class,
        static fn () => $wallet->createTransaction('bc1qdestination', '0.000000001'),
        'A string amount with sub-satoshi precision must be rejected.'
    );
    assertThrows(
        InvalidArgumentException::class,
        static fn () => $wallet->createTransaction('bc1qdestination', 0.000000009),
        'A float amount with sub-satoshi precision must not be silently rounded.'
    );
};

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, "[PASS] {$name}\n");
    } catch (Throwable $throwable) {
        fwrite(STDERR, "[FAIL] {$name}: {$throwable->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "{$passed} ElectrumWallet contract tests passed.\n");
