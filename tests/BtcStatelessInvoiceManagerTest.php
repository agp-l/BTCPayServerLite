<?php

declare(strict_types=1);

use BtcPayLite\BtcStatelessInvoiceManager;
use BtcPayLite\ElectrumWallet;

require dirname(__DIR__) . '/vendor/autoload.php';

final class StatelessKernelTestWallet extends ElectrumWallet
{
    /** @var list<array{amount: int|float|string, memo: string, expiry: int|null}> */
    public array $created = [];

    /** @var array<string, array<string, mixed>> */
    public array $requests = [];

    /** @var array<string, array{confirmed: string, unconfirmed: string}> */
    public array $balances = [];

    public function __construct()
    {
    }

    public function createPaymentRequest(
        int|float|string $amount,
        string $memo = '',
        ?int $expirationSeconds = null
    ): array {
        $this->created[] = ['amount' => $amount, 'memo' => $memo, 'expiry' => $expirationSeconds];

        return ['address' => 'bc1qstandalone', 'request_id' => 'standalone-request'];
    }

    public function getPaymentRequest(string $requestId): array
    {
        return $this->requests[$requestId] ?? ['status' => 0];
    }

    public function getAddressBalanceExact(string $address): array
    {
        return $this->balances[$address] ?? [
            'confirmed' => '0.00000000',
            'unconfirmed' => '0.00000000',
        ];
    }

    public function deletePaymentRequest(string $requestId): void
    {
    }
}

function kernelAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function kernelAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tests = [];

$tests['creates a database-free signed invoice'] = static function (): void {
    $wallet = new StatelessKernelTestWallet();
    $manager = new BtcStatelessInvoiceManager(
        $wallet,
        str_repeat('s', 32),
        static fn (): int => 1_700_000_000
    );

    $created = $manager->createStatelessInvoice(
        '0.00000001',
        'Email invoice',
        ['order_id' => 'MAIL-42', 'wallet' => 'merchant_wallet'],
        15
    );
    $invoice = $manager->decodeStatelessToken($created['token']);

    kernelAssertSame('0.00000001', $invoice['v'], 'One satoshi was not preserved.');
    kernelAssertSame('standalone-request', $invoice['r'], 'Electrum request was not embedded.');
    kernelAssertSame(1_700_000_900, $invoice['e'], 'Expiration is incorrect.');
    kernelAssertSame('0.00000001', $wallet->created[0]['amount'], 'Electrum amount changed.');
    kernelAssertTrue(str_starts_with($created['bip21_uri'], 'bitcoin:bc1qstandalone?'), 'BIP21 URI is missing.');
};

$tests['detects a partial payment exactly'] = static function (): void {
    $wallet = new StatelessKernelTestWallet();
    $wallet->requests['standalone-request'] = ['status' => 0];
    $wallet->balances['bc1qstandalone'] = [
        'confirmed' => '0.00000001',
        'unconfirmed' => '0.00000002',
    ];
    $manager = new BtcStatelessInvoiceManager(
        $wallet,
        str_repeat('s', 32),
        static fn (): int => 1_700_000_000
    );

    $created = $manager->createStatelessInvoice('0.00000005', 'Partial');
    $status = $manager->checkStatelessPaymentStatus($created['token']);

    kernelAssertSame('underpaid', $status['status'], 'Partial payment status is wrong.');
    kernelAssertSame('0.00000003', $status['payment']['received_total'], 'Received amount is wrong.');
    kernelAssertSame('0.00000002', $status['payment']['missing_amount'], 'Missing amount is wrong.');
};

$tests['keeps a paid request paid after address funds move'] = static function (): void {
    $wallet = new StatelessKernelTestWallet();
    $wallet->requests['standalone-request'] = ['status' => 3];
    $manager = new BtcStatelessInvoiceManager(
        $wallet,
        str_repeat('s', 32),
        static fn (): int => 1_700_000_000
    );

    $created = $manager->createStatelessInvoice('0.001', 'Paid');
    $status = $manager->checkStatelessPaymentStatus($created['token']);

    kernelAssertSame('paid', $status['status'], 'Paid request regressed.');
    kernelAssertSame('0.00100000', $status['payment']['received_total'], 'Paid total was lost.');
};

$tests['has no database dependency'] = static function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/classes/BtcStatelessInvoiceManager.php');
    kernelAssertTrue(is_string($source), 'Kernel source could not be read.');
    kernelAssertTrue(
        !preg_match('/\\bDatabase\\b|PDO|invoices\\s/i', $source),
        'Standalone kernel contains a database dependency.'
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

fwrite(STDOUT, "{$passed} stateless invoice kernel tests passed.\n");

