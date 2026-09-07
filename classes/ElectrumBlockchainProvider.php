<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use Throwable;

/**
 * Monitors Bitcoin addresses using Electrum daemon RPC calls without loading
 * or locking any wallet.
 *
 * All network queries (getaddressbalance, getaddresshistory) are daemon-level
 * and safe to execute concurrently across multiple worker threads.
 * Includes short TTL caching (2 seconds) and per-address single-flight coalescing
 * to safely handle burst reloads without overloading the Electrum daemon.
 */
class ElectrumBlockchainProvider implements BlockchainProviderInterface
{
    private const DEFAULT_CACHE_TTL_SECONDS = 2;

    private ElectrumRPC $rpc;
    private int $ttlSeconds;
    private string $cacheDir;

    /** @var array<string, array{time: int, observation: AddressPaymentObservation}> */
    private static array $memoryCache = [];

    public function __construct(
        ElectrumRPC $rpc,
        int $ttlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
        ?string $cacheDir = null
    ) {
        $this->rpc = $rpc;
        $this->ttlSeconds = max(1, $ttlSeconds);
        $this->cacheDir = $cacheDir !== null && $cacheDir !== ''
            ? rtrim($cacheDir, '/\\')
            : sys_get_temp_dir();
    }

    public static function clearMemoryCache(): void
    {
        self::$memoryCache = [];
    }

    public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation
    {
        $address = trim($address);
        if ($address === '') {
            throw new BlockchainProviderException('Address cannot be empty.', 'observe_address', 400);
        }

        $now = time();
        $cacheKey = $address;

        // 1. Fast in-memory single-flight / burst check
        if (isset(self::$memoryCache[$cacheKey])) {
            $entry = self::$memoryCache[$cacheKey];
            if (($now - $entry['time']) < $this->ttlSeconds) {
                return $entry['observation'];
            }
        }

        // 2. Cross-process single-flight coalescing using file lock
        $addressHash = hash('sha256', $address);
        $lockPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'bprov_' . substr($addressHash, 0, 32) . '.lock';
        $lockHandle = @fopen($lockPath, 'c');

        if ($lockHandle !== false) {
            $startTime = microtime(true);
            $locked = false;

            do {
                if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                usleep(10000); // 10ms wait
            } while ((microtime(true) - $startTime) < 1.5);

            if ($locked) {
                try {
                    // Re-check cache in case another worker just refreshed it while we waited
                    $cached = $this->readFileCache($addressHash);
                    if ($cached !== null) {
                        self::$memoryCache[$cacheKey] = ['time' => $cached->getObservedAt(), 'observation' => $cached];
                        return $cached;
                    }

                    $observation = $this->queryElectrum($address);
                    $this->writeFileCache($addressHash, $observation);
                    self::$memoryCache[$cacheKey] = ['time' => time(), 'observation' => $observation];
                    return $observation;
                } finally {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                }
            } else {
                fclose($lockHandle);
            }
        }

        // If lock acquisition timed out or failed, try reading cached value
        $cached = $this->readFileCache($addressHash);
        if ($cached !== null) {
            return $cached;
        }

        $observation = $this->queryElectrum($address);
        self::$memoryCache[$cacheKey] = ['time' => time(), 'observation' => $observation];
        return $observation;
    }

    private function queryElectrum(string $address): AddressPaymentObservation
    {
        try {
            /** @var array<string, mixed> $balance */
            $balance = $this->rpc->callDaemon('getaddressbalance', ['address' => $address]);
            if (!is_array($balance)) {
                throw new BlockchainProviderException('Electrum returned an invalid balance response.', 'observe_address');
            }

            $confirmedBtc = (string) ($balance['confirmed'] ?? '0');
            $unconfirmedBtc = (string) ($balance['unconfirmed'] ?? '0');

            $confirmedSats = BitcoinAmount::fromBtc($confirmedBtc)->toSatoshis();
            $unconfirmedSats = BitcoinAmount::fromBtc($unconfirmedBtc)->toSatoshis();
        } catch (BlockchainProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BlockchainProviderException(
                'Failed to query address balance: ' . $exception->getMessage(),
                'observe_address',
                500,
                $exception
            );
        }

        $historyCount = 0;
        try {
            $history = $this->rpc->callDaemon('getaddresshistory', ['address' => $address]);
            if (is_array($history)) {
                $historyCount = count($history);
            }
        } catch (Throwable) {
            // Address history query failure is non-fatal; we proceed with balance data
            $historyCount = 0;
        }

        return new AddressPaymentObservation(
            $address,
            $confirmedSats,
            $unconfirmedSats,
            max($confirmedSats + $unconfirmedSats, 0),
            $historyCount,
            time()
        );
    }

    private function readFileCache(string $addressHash): ?AddressPaymentObservation
    {
        $cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'bprov_' . substr($addressHash, 0, 32) . '.cache';
        if (!file_exists($cacheFile)) {
            return null;
        }

        $content = @file_get_contents($cacheFile);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['time'], $data['address'])) {
            return null;
        }

        if ((time() - (int) $data['time']) >= $this->ttlSeconds) {
            return null;
        }

        return new AddressPaymentObservation(
            (string) $data['address'],
            (int) ($data['confirmed'] ?? 0),
            (int) ($data['unconfirmed'] ?? 0),
            (int) ($data['total'] ?? 0),
            (int) ($data['history_count'] ?? 0),
            (int) $data['time']
        );
    }

    private function writeFileCache(string $addressHash, AddressPaymentObservation $observation): void
    {
        $cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'bprov_' . substr($addressHash, 0, 32) . '.cache';
        $payload = json_encode([
            'address' => $observation->getAddress(),
            'confirmed' => $observation->getConfirmedSatoshis(),
            'unconfirmed' => $observation->getUnconfirmedSatoshis(),
            'total' => $observation->getTotalReceivedSatoshis(),
            'history_count' => $observation->getHistoryCount(),
            'time' => $observation->getObservedAt(),
        ]);

        if ($payload !== false) {
            @file_put_contents($cacheFile, $payload, LOCK_EX);
        }
    }
}
