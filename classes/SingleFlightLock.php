<?php

declare(strict_types=1);

namespace BtcPayLite;

use Throwable;

/**
 * Single-flight concurrency coordinator.
 *
 * Ensures only one concurrent request per Bitcoin address invokes the external
 * blockchain provider, while other concurrent callers await the cached result.
 */
class SingleFlightLock
{
    private string $lockDir;

    public function __construct(?string $lockDir = null)
    {
        $this->lockDir = $lockDir !== null && trim($lockDir) !== ''
            ? rtrim($lockDir, DIRECTORY_SEPARATOR)
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'btcpaylite_singleflight';

        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0770, true);
        }
    }

    /**
     * Executes the loader callback with coalesced concurrency protection.
     *
     * @param callable(): AddressPaymentObservation $loader
     */
    public function execute(
        string $address,
        PaymentStatusCacheInterface $cache,
        callable $loader,
        int $ttlSeconds = 5,
        int $lockTimeoutSeconds = 3
    ): AddressPaymentObservation {
        // Fast path 1: Check cache before attempting to lock
        $cached = $cache->get($address);
        if ($cached !== null) {
            return $cached;
        }

        $lockKey = hash('sha256', 'singleflight_' . $address);
        $lockFile = $this->lockDir . DIRECTORY_SEPARATOR . $lockKey . '.lock';
        $fp = @fopen($lockFile, 'c');
        if ($fp === false) {
            return $loader();
        }

        $startTime = microtime(true);
        $acquired = false;

        while ((microtime(true) - $startTime) < $lockTimeoutSeconds) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }

            // Await briefly then check cache in case leader finished
            usleep(30_000); // 30ms
            $recheck = $cache->get($address);
            if ($recheck !== null) {
                fclose($fp);
                return $recheck;
            }
        }

        if (!$acquired) {
            fclose($fp);
            // If lock timed out, check cache once more, else fall back to calling loader
            $fallback = $cache->get($address);
            return $fallback ?? $loader();
        }

        try {
            // Double check cache now that we hold the lock
            $doubleCheck = $cache->get($address);
            if ($doubleCheck !== null) {
                return $doubleCheck;
            }

            $observation = $loader();
            $cache->put($address, $observation, $ttlSeconds);

            return $observation;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
