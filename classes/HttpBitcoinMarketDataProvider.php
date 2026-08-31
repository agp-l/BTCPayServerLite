<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use RuntimeException;

final class HttpBitcoinMarketDataProvider implements BitcoinMarketDataProvider
{
    private const FEES_URL = 'https://mempool.space/api/v1/fees/recommended';
    private const PRICES_URL = 'https://blockchain.info/ticker';
    private const CONNECT_TIMEOUT_MS = 1000;
    private const REQUEST_TIMEOUT_MS = 2500;
    private const MAX_RESPONSE_BYTES = 1048576;

    /** @var Closure(string):string */
    private Closure $request;

    public function __construct(?callable $request = null)
    {
        $this->request = $request === null
            ? Closure::fromCallable([$this, 'request'])
            : Closure::fromCallable($request);
    }

    public function getRecommendedFees(): array
    {
        $data = $this->requestJson(self::FEES_URL);

        return [
            'economy' => $this->feeRate($data['hourFee'] ?? null),
            'standard' => $this->feeRate($data['halfHourFee'] ?? null),
            'priority' => $this->feeRate($data['fastestFee'] ?? null),
        ];
    }

    public function getFiatPrice(string $currency): ?float
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new RuntimeException('Fiat currency must be a three-letter ISO code.');
        }

        $data = $this->requestJson(self::PRICES_URL);
        $entry = $data[$currency] ?? null;
        if (!is_array($entry) || !isset($entry['last']) || !is_numeric($entry['last'])) {
            return null;
        }

        $price = (float) $entry['last'];

        return is_finite($price) && $price > 0 ? $price : null;
    }

    /** @return array<string, mixed> */
    private function requestJson(string $url): array
    {
        $body = ($this->request)($url);
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Market data provider returned an invalid response size.');
        }

        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Market data provider returned an invalid JSON document.');
        }

        return $decoded;
    }

    private function feeRate(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Market data provider returned an invalid fee rate.');
        }

        $rate = (int) $value;
        if ($rate < 1 || $rate > 10000) {
            throw new RuntimeException('Market data provider returned a fee rate outside the allowed range.');
        }

        return $rate;
    }

    private function request(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required for market data requests.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Market data request could not be initialized.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::REQUEST_TIMEOUT_MS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'BTCPayServerLite/1.0',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                $error !== '' ? 'Market data request failed.' : 'Market data provider returned HTTP ' . $status . '.'
            );
        }

        return $body;
    }
}
