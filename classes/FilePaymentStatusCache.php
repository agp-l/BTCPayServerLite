<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * File-backed payment status cache with TTL for multi-process PHP-FPM workers.
 */
class FilePaymentStatusCache implements PaymentStatusCacheInterface
{
    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir !== null && trim($cacheDir) !== ''
            ? rtrim($cacheDir, DIRECTORY_SEPARATOR)
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'btcpaylite_status_cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0770, true);
        }
    }

    public function get(string $key): ?AddressPaymentObservation
    {
        $safeKey = hash('sha256', $key);
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . $safeKey . '.json';

        if (!file_exists($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['expires_at']) || time() > $data['expires_at']) {
            @unlink($file);
            return null;
        }

        return new AddressPaymentObservation(
            confirmedReceivedSats: (int) ($data['confirmed_received_sats'] ?? 0),
            unconfirmedReceivedSats: (int) ($data['unconfirmed_received_sats'] ?? 0),
            transactions: is_array($data['transactions'] ?? null) ? $data['transactions'] : [],
            observedAt: (int) ($data['observed_at'] ?? time())
        );
    }

    public function put(string $key, AddressPaymentObservation $value, int $ttlSeconds = 5): void
    {
        $safeKey = hash('sha256', $key);
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . $safeKey . '.json';

        $data = $value->toArray();
        $data['expires_at'] = time() + max(1, $ttlSeconds);

        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
