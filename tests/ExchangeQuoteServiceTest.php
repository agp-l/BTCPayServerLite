<?php

declare(strict_types=1);

use BtcPayLite\BitcoinMarketDataProvider;
use BtcPayLite\ExchangeQuoteService;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ExchangeQuoteTestMarketData implements BitcoinMarketDataProvider
{
    /** @var array<string,float> */
    private array $prices;

    /** @param array<string,float> $prices */
    public function __construct(array $prices)
    {
        $this->prices = $prices;
    }

    public function getRecommendedFees(): array
    {
        return ['economy' => 1, 'standard' => 2, 'priority' => 3];
    }

    public function getFiatPrice(string $currency): ?float
    {
        return $this->prices[$currency] ?? null;
    }
}

function quoteAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function quoteAssertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException|RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

$tests = [
    'quotes fiat in exact satoshis and applies exchange fee' => static function (): void {
        $service = new ExchangeQuoteService(
            new ExchangeQuoteTestMarketData(['CZK' => 2_000_000.00]),
            200
        );

        $quote = $service->quote('500.00', 'czk', 1_788_200_000);

        quoteAssertSame('CZK', $quote['currency'], 'Currency was not normalized.');
        quoteAssertSame('2000000.00', $quote['rate'], 'Fiat rate changed.');
        quoteAssertSame('0.00025000', $quote['grossAmount'], 'Gross BTC quote is incorrect.');
        quoteAssertSame('0.00000500', $quote['feeAmount'], 'Two-percent fee is incorrect.');
        quoteAssertSame('0.00024500', $quote['payoutAmount'], 'Net payout is incorrect.');
        quoteAssertSame(1_788_200_060, $quote['expiresAt'], 'Quote lifetime changed.');
    },
    'keeps direct BTC quotes exact' => static function (): void {
        $service = new ExchangeQuoteService(new ExchangeQuoteTestMarketData([]), 25);
        $quote = $service->quote('0.00100000', 'BTC', 1_788_200_000);

        quoteAssertSame('0.00100000', $quote['grossAmount'], 'Direct BTC amount changed.');
        quoteAssertSame('0.00000250', $quote['feeAmount'], 'BTC quote fee is incorrect.');
        quoteAssertSame('0.00099750', $quote['payoutAmount'], 'Direct BTC net amount is incorrect.');
    },
    'rejects unsupported precision and missing rates' => static function (): void {
        $service = new ExchangeQuoteService(new ExchangeQuoteTestMarketData([]), 0);

        quoteAssertThrows(
            static fn () => $service->quote('500.001', 'CZK'),
            'A fiat amount with more than two decimals was accepted.'
        );
        quoteAssertThrows(
            static fn () => $service->quote('50.00', 'EUR'),
            'A quote without a market rate was accepted.'
        );
    },
    'rejects an unsafe exchange fee' => static function (): void {
        quoteAssertThrows(
            static fn () => new ExchangeQuoteService(new ExchangeQuoteTestMarketData([]), 5_001),
            'An exchange fee above fifty percent was accepted.'
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

fwrite(STDOUT, "{$passed} ExchangeQuoteService tests passed.\n");
