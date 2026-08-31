<?php

declare(strict_types=1);

use BtcPayLite\HttpBitcoinMarketDataProvider;

require_once __DIR__ . '/../classes/BitcoinMarketDataProvider.php';
require_once __DIR__ . '/../classes/HttpBitcoinMarketDataProvider.php';

$provider = new HttpBitcoinMarketDataProvider(
    static function (string $url): string {
        if (str_contains($url, '/fees/')) {
            return '{"hourFee":2,"halfHourFee":4,"fastestFee":7}';
        }

        return '{"CZK":{"last":1234567.89},"USD":{"last":50000}}';
    }
);

$fees = $provider->getRecommendedFees();
if ($fees !== ['economy' => 2, 'standard' => 4, 'priority' => 7]) {
    throw new RuntimeException('Recommended fees were not normalized.');
}
echo "[PASS] normalizes recommended fee rates\n";

if ($provider->getFiatPrice('czk') !== 1234567.89) {
    throw new RuntimeException('Fiat price was not normalized.');
}
echo "[PASS] normalizes fiat currency codes and prices\n";

if ($provider->getFiatPrice('EUR') !== null) {
    throw new RuntimeException('A missing fiat currency did not return null.');
}
echo "[PASS] treats a missing fiat currency as unavailable\n";

$invalid = new HttpBitcoinMarketDataProvider(static fn (string $url): string => '{"hourFee":0,"halfHourFee":2,"fastestFee":3}');
try {
    $invalid->getRecommendedFees();
    throw new RuntimeException('An invalid fee rate was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'An invalid fee rate was accepted.') {
        throw $exception;
    }
}
echo "[PASS] rejects fee rates outside the allowed range\n";

echo "4 Bitcoin market data provider tests passed.\n";
