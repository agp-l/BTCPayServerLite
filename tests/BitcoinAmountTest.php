<?php

declare(strict_types=1);

use BtcPayLite\BitcoinAmount;

require dirname(__DIR__) . '/vendor/autoload.php';

function amountAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function amountAssertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

$tests = [
    'one satoshi is represented exactly' => static function (): void {
        $amount = BitcoinAmount::fromBtc('0.00000001');
        amountAssertSame(1, $amount->satoshis(), 'One satoshi was parsed incorrectly.');
        amountAssertSame('0.00000001', $amount->toBtcString(), 'One satoshi was formatted incorrectly.');
    },
    'signed balances remain exact' => static function (): void {
        $amount = BitcoinAmount::fromBtc('-0.00000001');
        amountAssertSame(-1, $amount->satoshis(), 'A negative satoshi was parsed incorrectly.');
        amountAssertSame('-0.00000001', $amount->toBtcString(), 'A negative satoshi was formatted incorrectly.');
    },
    'integer arithmetic does not use floats' => static function (): void {
        $first = BitcoinAmount::fromBtc('0.10000001');
        $second = BitcoinAmount::fromBtc('0.20000002');
        amountAssertSame('0.30000003', $first->add($second)->toBtcString(), 'Satoshi addition failed.');
    },
    'sub-satoshi strings are rejected' => static function (): void {
        amountAssertThrows(
            static fn () => BitcoinAmount::fromBtc('0.000000001'),
            'A sub-satoshi decimal string was accepted.'
        );
    },
    'sub-satoshi floats are not rounded' => static function (): void {
        amountAssertThrows(
            static fn () => BitcoinAmount::fromBtc(0.000000009),
            'A sub-satoshi float was silently rounded.'
        );
    },
];

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

fwrite(STDOUT, "{$passed} BitcoinAmount tests passed.\n");
