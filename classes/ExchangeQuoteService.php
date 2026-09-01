<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use RuntimeException;

/**
 * Creates short-lived, informational exchange quotes using integer satoshis.
 *
 * Fiat inputs are intentionally limited to two decimal places and a bounded
 * value so the calculation remains exact without requiring ext-bcmath.
 */
final class ExchangeQuoteService
{
    private const MAX_FIAT_MINOR = 100_000_000; // 1,000,000.00
    private const QUOTE_LIFETIME_SECONDS = 60;

    private BitcoinMarketDataProvider $marketData;
    private int $feeBasisPoints;

    public function __construct(BitcoinMarketDataProvider $marketData, int $feeBasisPoints = 0)
    {
        if ($feeBasisPoints < 0 || $feeBasisPoints > 5_000) {
            throw new InvalidArgumentException('Exchange fee must be between 0 and 5000 basis points.');
        }

        $this->marketData = $marketData;
        $this->feeBasisPoints = $feeBasisPoints;
    }

    /** @return array<string,mixed> */
    public function quote(string $amount, string $currency, ?int $now = null): array
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter code.');
        }

        $amount = trim($amount);
        if ($currency === 'BTC') {
            $gross = BitcoinAmount::fromBtc($amount);
            $rate = '1.00000000';
        } elseif ($currency === 'SAT') {
            if (!ctype_digit($amount) || $amount === '0') {
                throw new InvalidArgumentException('SAT amount must be a positive integer.');
            }
            $satoshis = (int) $amount;
            if ((string) $satoshis !== ltrim($amount, '0') && !preg_match('/\A0*[1-9][0-9]*\z/D', $amount)) {
                throw new InvalidArgumentException('SAT amount is invalid.');
            }
            $gross = BitcoinAmount::fromSatoshis($satoshis);
            $rate = '100000000';
        } else {
            $minor = $this->fiatMinorUnits($amount);
            try {
                $price = $this->marketData->getFiatPrice($currency);
            } catch (RuntimeException $exception) {
                throw new RuntimeException('Exchange rate is temporarily unavailable.', 0, $exception);
            }
            if ($price === null || !is_finite($price) || $price <= 0) {
                throw new RuntimeException('Exchange rate is not available for this currency.');
            }

            $priceMinor = (int) round($price * 100, 0, PHP_ROUND_HALF_UP);
            if ($priceMinor < 1) {
                throw new RuntimeException('Exchange rate is invalid.');
            }
            $grossSatoshis = intdiv($minor * BitcoinAmount::SATOSHIS_PER_BTC, $priceMinor);
            if ($grossSatoshis < 1 || $grossSatoshis > BitcoinAmount::MAX_SATOSHIS) {
                throw new InvalidArgumentException('Quoted Bitcoin amount is outside the supported range.');
            }
            $gross = BitcoinAmount::fromSatoshis($grossSatoshis);
            $rate = number_format($priceMinor / 100, 2, '.', '');
        }

        if (!$gross->isPositive()) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        $feeSatoshis = $this->feeSatoshis($gross->satoshis());
        $net = BitcoinAmount::fromSatoshis($gross->satoshis() - $feeSatoshis);
        if (!$net->isPositive()) {
            throw new InvalidArgumentException('Exchange fee consumes the entire quoted amount.');
        }

        $timestamp = $now ?? time();
        if ($timestamp < 1) {
            throw new InvalidArgumentException('Quote timestamp is invalid.');
        }

        return [
            'id' => 'quote_' . bin2hex(random_bytes(16)),
            'currency' => $currency,
            'amount' => $amount,
            'rate' => $rate,
            'grossAmount' => $gross->toBtcString(),
            'feeBasisPoints' => $this->feeBasisPoints,
            'feeAmount' => BitcoinAmount::fromSatoshis($feeSatoshis)->toBtcString(),
            'payoutAmount' => $net->toBtcString(),
            'payoutCurrency' => 'BTC',
            'createdAt' => $timestamp,
            'expiresAt' => $timestamp + self::QUOTE_LIFETIME_SECONDS,
        ];
    }

    private function fiatMinorUnits(string $amount): int
    {
        if (!preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/D', $amount, $matches)) {
            throw new InvalidArgumentException('Fiat amount must use at most two decimal places.');
        }

        $minor = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($minor < 1 || $minor > self::MAX_FIAT_MINOR) {
            throw new InvalidArgumentException('Fiat amount is outside the supported range.');
        }

        return $minor;
    }

    private function feeSatoshis(int $grossSatoshis): int
    {
        if ($this->feeBasisPoints === 0) {
            return 0;
        }

        $whole = intdiv($grossSatoshis, 10_000) * $this->feeBasisPoints;
        $remainder = $grossSatoshis % 10_000;
        $fraction = intdiv(($remainder * $this->feeBasisPoints) + 9_999, 10_000);

        return $whole + $fraction;
    }
}
