<?php

declare(strict_types=1);

use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\BtcInvoiceManagerException;
use BtcPayLite\ElectrumWallet;

require dirname(__DIR__) . '/vendor/autoload.php';

final class InvoiceTestWallet extends ElectrumWallet
{
    /** @var list<array{amount: int|float|string, memo: string, expiry: int|null}> */
    public array $createdRequests = [];

    /** @var array<string, array<string, mixed>> */
    public array $paymentRequests = [];

    /** @var array<string, array{confirmed: int|float|string, unconfirmed: int|float|string}> */
    public array $balances = [];

    /** @var list<string> */
    public array $deletedRequests = [];

    /** @var array<string, mixed> */
    private array $nextCreatedRequest;

    /** @param array<string, mixed> $nextCreatedRequest */
    public function __construct(array $nextCreatedRequest)
    {
        $this->nextCreatedRequest = $nextCreatedRequest;
    }

    public function createPaymentRequest(
        int|float|string $amount,
        string $memo = '',
        ?int $expirationSeconds = null
    ): array {
        $this->createdRequests[] = [
            'amount' => $amount,
            'memo' => $memo,
            'expiry' => $expirationSeconds,
        ];

        return $this->nextCreatedRequest;
    }

    public function getPaymentRequest(string $requestId): array
    {
        if (!isset($this->paymentRequests[$requestId])) {
            throw new RuntimeException('Missing fake payment request.');
        }

        return $this->paymentRequests[$requestId];
    }

    public function deletePaymentRequest(string $requestId): void
    {
        $this->deletedRequests[] = $requestId;
    }

    public function getAddressBalanceExact(string $address): array
    {
        return $this->balances[$address] ?? [
            'confirmed' => '0.00000000',
            'unconfirmed' => '0.00000000',
        ];
    }
}

function invoiceAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function invoiceAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function invoiceAssertThrows(string $expectedClass, callable $callback, string $message): void
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

function newInvoiceTestManager(InvoiceTestWallet $wallet, callable $clock): BtcInvoiceManager
{
    return new BtcInvoiceManager($wallet, str_repeat('k', 32), null, $clock);
}

$tests = [];

$tests['creates a signed token from a reserved Electrum request'] = static function (): void {
    $now = 1_700_000_000;
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qreserved',
        'request_id' => 'a1b2c3d4e5',
    ]);
    $manager = newInvoiceTestManager($wallet, static fn (): int => $now);

    $invoice = $manager->createStatelessInvoice('0.001', 'Order 42', ['order_id' => '42'], 15);
    $decoded = $manager->decodeStatelessToken($invoice['token']);

    invoiceAssertSame(2, $decoded['ver'], 'The token version is missing.');
    invoiceAssertSame('bc1qreserved', $decoded['a'], 'The reserved address is missing.');
    invoiceAssertSame('a1b2c3d4e5', $decoded['r'], 'The Electrum request ID is missing.');
    invoiceAssertSame('0.00100000', $decoded['v'], 'The amount is not canonical.');
    invoiceAssertSame($now + 900, $decoded['e'], 'The expiration timestamp is incorrect.');
    invoiceAssertSame('0.00100000', $wallet->createdRequests[0]['amount'], 'Electrum received an imprecise amount.');
    invoiceAssertSame(900, $wallet->createdRequests[0]['expiry'], 'Electrum received the wrong expiry.');
    invoiceAssertTrue(str_starts_with($invoice['bip21_uri'], 'bitcoin:bc1qreserved?'), 'The BIP21 URI is invalid.');
};

$tests['rejects a token whose signature was changed'] = static function (): void {
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qreserved',
        'request_id' => 'a1b2c3d4e5',
    ]);
    $manager = newInvoiceTestManager($wallet, static fn (): int => 1_700_000_000);
    $invoice = $manager->createStatelessInvoice('0.001', 'Order 42');
    $lastCharacter = substr($invoice['token'], -1);
    $tampered = substr($invoice['token'], 0, -1) . ($lastCharacter === '0' ? '1' : '0');

    invoiceAssertThrows(
        BtcInvoiceManagerException::class,
        static fn () => $manager->decodeStatelessToken($tampered),
        'A token with a modified signature was accepted.'
    );
};

$tests['continues to decode legacy padded tokens'] = static function (): void {
    $secret = str_repeat('k', 32);
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qunused',
        'request_id' => 'ffffffffff',
    ]);
    $manager = new BtcInvoiceManager($wallet, $secret, null, static fn (): int => 1_700_000_000);
    $payload = [
        'a' => 'bc1qlegacy',
        'v' => '0.01000000',
        'd' => 'Legacy',
        'p' => [],
        't' => 1_700_000_000,
        'e' => 1_700_000_900,
    ];
    $encoded = strtr(base64_encode((string) json_encode($payload)), '+/', '-_');
    $token = $encoded . '.' . hash_hmac('sha256', $encoded, $secret);

    $decoded = $manager->decodeStatelessToken($token);

    invoiceAssertSame('bc1qlegacy', $decoded['a'], 'The legacy address changed.');
    invoiceAssertTrue(!isset($decoded['r']), 'A legacy token gained a fake request ID.');
};

$tests['requires a payment request ID in version two tokens'] = static function (): void {
    $secret = str_repeat('k', 32);
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qunused',
        'request_id' => 'eeeeeeeeee',
    ]);
    $manager = new BtcInvoiceManager($wallet, $secret, null, static fn (): int => 1_700_000_000);
    $payload = [
        'ver' => 2,
        'a' => 'bc1qinvalid',
        'v' => '0.01000000',
        'd' => 'Missing request',
        'p' => [],
        't' => 1_700_000_000,
        'e' => 1_700_000_900,
    ];
    $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    $token = $encoded . '.' . hash_hmac('sha256', $encoded, $secret);

    invoiceAssertThrows(
        BtcInvoiceManagerException::class,
        static fn () => $manager->decodeStatelessToken($token),
        'A version two token without a payment request ID was accepted.'
    );
};

$tests['maps an unconfirmed Electrum request to pending mempool'] = static function (): void {
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qpending',
        'request_id' => '1111111111',
    ]);
    $wallet->paymentRequests['1111111111'] = ['status' => 7];
    $manager = newInvoiceTestManager($wallet, static fn (): int => 1_700_000_000);
    $invoice = $manager->createStatelessInvoice('0.001', 'Pending');

    $status = $manager->checkStatelessPaymentStatus($invoice['token']);

    invoiceAssertSame('pending_mempool', $status['status'], 'An unconfirmed request has the wrong status.');
    invoiceAssertSame('0.00100000', $status['payment']['received_total'], 'Expected payment was not recognized.');
    invoiceAssertSame('0.00000000', $status['payment']['missing_amount'], 'A paid amount is still reported missing.');
};

$tests['keeps a paid Electrum request paid after its address was spent'] = static function (): void {
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qpaid',
        'request_id' => '9999999999',
    ]);
    $wallet->paymentRequests['9999999999'] = ['status' => 3];
    $manager = newInvoiceTestManager($wallet, static fn (): int => 1_700_000_000);
    $invoice = $manager->createStatelessInvoice('0.001', 'Paid');

    $status = $manager->checkStatelessPaymentStatus($invoice['token']);

    invoiceAssertSame('paid', $status['status'], 'A paid request regressed after its address was spent.');
    invoiceAssertSame('0.00100000', $status['payment']['received_total'], 'The paid amount was lost.');
};

$tests['calculates partial payments in integer satoshis'] = static function (): void {
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qpartial',
        'request_id' => '2222222222',
    ]);
    $wallet->paymentRequests['2222222222'] = ['status' => 0];
    $wallet->balances['bc1qpartial'] = [
        'confirmed' => '0.00000001',
        'unconfirmed' => '0.00000002',
    ];
    $manager = newInvoiceTestManager($wallet, static fn (): int => 1_700_000_000);
    $invoice = $manager->createStatelessInvoice('0.00000005', 'Partial');

    $status = $manager->checkStatelessPaymentStatus($invoice['token']);

    invoiceAssertSame('underpaid', $status['status'], 'A partial payment has the wrong status.');
    invoiceAssertSame('0.00000003', $status['payment']['received_total'], 'Received satoshis are incorrect.');
    invoiceAssertSame('0.00000002', $status['payment']['missing_amount'], 'Missing satoshis are incorrect.');
};

$tests['expires an unpaid request deterministically'] = static function (): void {
    $now = 1_700_000_000;
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qexpired',
        'request_id' => '3333333333',
    ]);
    $wallet->paymentRequests['3333333333'] = ['status' => 1];
    $manager = newInvoiceTestManager($wallet, static function () use (&$now): int {
        return $now;
    });
    $invoice = $manager->createStatelessInvoice('0.001', 'Expired', [], 1);
    $now += 61;

    $status = $manager->checkStatelessPaymentStatus($invoice['token']);

    invoiceAssertSame('expired', $status['status'], 'An expired request has the wrong status.');
    invoiceAssertSame(0, $status['seconds_remaining'], 'Expired invoice time must be zero.');
};

$tests['rejects sub-satoshi invoice amounts before touching Electrum'] = static function (): void {
    $wallet = new InvoiceTestWallet([
        'address' => 'bc1qunused',
        'request_id' => '4444444444',
    ]);
    $manager = newInvoiceTestManager($wallet, static fn (): int => 1_700_000_000);

    invoiceAssertThrows(
        InvalidArgumentException::class,
        static fn () => $manager->createStatelessInvoice('0.000000001', 'Invalid'),
        'A sub-satoshi invoice amount was accepted.'
    );
    invoiceAssertSame(0, count($wallet->createdRequests), 'Electrum was mutated before amount validation.');
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

fwrite(STDOUT, "{$passed} BtcInvoiceManager tests passed.\n");
