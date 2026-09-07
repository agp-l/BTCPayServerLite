<?php

declare(strict_types=1);

namespace BtcPayLite;

use Throwable;

/** Walletless current balance query with bounded per-address single-flight. */
class ElectrumBlockchainProvider implements BlockchainProviderInterface
{
    private const LOCK_WAIT_SECONDS = 1.5;
    private const FAILURE_COOLDOWN_SECONDS = 2;
    private array $memoryCache = [];
    private string $cacheDir;

    public function __construct(
        private ElectrumRPC $rpc,
        private int $ttlSeconds = 2,
        ?string $cacheDir = null,
        private int $staleSeconds = 30
    ) {
        $this->ttlSeconds = max(1, $ttlSeconds);
        $this->staleSeconds = max($this->ttlSeconds, $staleSeconds);
        $this->cacheDir = $cacheDir ?? (getenv('BTCPAY_BLOCKCHAIN_CACHE_DIR') ?: dirname(__DIR__) . '/var/blockchain');
    }

    public function maxObservationDurationSeconds(): int
    {
        // One bounded HTTP RPC, plus lock wait and local cache overhead.
        return $this->rpc->getTimeoutSeconds() + 3;
    }

    public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation
    {
        $address = trim($address);
        if ($address === '' || strlen($address) > 100 || $expectedSatoshis < 0) {
            throw new BlockchainProviderException('Invalid observation request.', 'observe_address', 400);
        }
        // Namespace by endpoint as well as address: never mix different networks/daemons.
        $key = hash('sha256', 'balance-v2|' . $this->rpc->getEndpoint() . '|' . $address);
        $cached = $this->memoryCache[$key] ?? null;
        if ($cached !== null && time() - $cached->getObservedAt() < $this->ttlSeconds) {
            return $cached;
        }
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0770, true) && !is_dir($this->cacheDir)) {
            throw $this->busy();
        }
        $cached = $this->readCache($key, $address, $this->ttlSeconds);
        if ($cached !== null) {
            return $this->remember($key, $cached);
        }
        $lock = @fopen($this->path($key, 'lock'), 'c');
        if ($lock === false) {
            return $this->staleOrFail($key, $address);
        }
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        $locked = false;
        try {
            do {
                $locked = flock($lock, LOCK_EX | LOCK_NB);
                if ($locked) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if (!$locked) {
                // Never issue an uncoalesced RPC on timeout/cache miss.
                return $this->staleOrFail($key, $address);
            }
            $cached = $this->readCache($key, $address, $this->ttlSeconds);
            if ($cached !== null) {
                return $this->remember($key, $cached);
            }
            $retryAt = (int) @file_get_contents($this->path($key, 'retry'));
            if ($retryAt > time()) {
                return $this->staleOrFail($key, $address);
            }
            try {
                $observation = $this->queryElectrum($address);
                $this->writeCache($key, $observation);
                return $this->remember($key, $observation);
            } catch (Throwable $exception) {
                // Back off across processes on upstream failure, too.
                @file_put_contents($this->path($key, 'retry'), (string) (time() + self::FAILURE_COOLDOWN_SECONDS));
                return $this->staleOrFail($key, $address, $exception);
            }
        } finally {
            if ($locked) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    private function queryElectrum(string $address): AddressPaymentObservation
    {
        $balance = $this->rpc->callNetwork('getaddressbalance', ['address' => $address]);
        if (!is_array($balance) || !isset($balance['confirmed'], $balance['unconfirmed'])) {
            throw new BlockchainProviderException('Invalid address balance response.', 'observe_address', 503);
        }
        $confirmed = max(0, BitcoinAmount::fromBtc($balance['confirmed'])->toSatoshis());
        $delta = BitcoinAmount::fromBtc($balance['unconfirmed'])->toSatoshis();
        // Electrum mempool balance is a delta, possibly negative after a spend.
        // Normalize inconsistent negative totals here, never manufacture historical receipts.
        $delta = max(-$confirmed, $delta);
        return new AddressPaymentObservation($address, $confirmed, $delta, $confirmed + $delta, time());
    }

    private function staleOrFail(string $key, string $address, ?Throwable $previous = null): AddressPaymentObservation
    {
        $cached = $this->readCache($key, $address, $this->staleSeconds);
        if ($cached !== null) {
            return $cached;
        }
        throw $this->busy($previous);
    }

    private function busy(?Throwable $previous = null): BlockchainProviderException
    {
        return new BlockchainProviderException('Blockchain observation is busy. Retry shortly.', 'observe_address', 503, $previous);
    }

    private function path(string $key, string $suffix): string
    {
        return $this->cacheDir . '/' . $key . '.' . $suffix;
    }

    private function remember(string $key, AddressPaymentObservation $observation): AddressPaymentObservation
    {
        // Bound memory for long-running workers.
        if (count($this->memoryCache) >= 1024) {
            $this->memoryCache = [];
        }
        return $this->memoryCache[$key] = $observation;
    }

    private function readCache(string $key, string $address, int $maxAge): ?AddressPaymentObservation
    {
        $data = json_decode((string) @file_get_contents($this->path($key, 'json')), true);
        if (!is_array($data) || ($data['address'] ?? null) !== $address) {
            return null;
        }
        foreach (['confirmed', 'delta', 'current', 'time'] as $field) {
            if (!is_int($data[$field] ?? null)) {
                return null;
            }
        }
        $age = time() - $data['time'];
        if ($age < 0 || $age >= $maxAge) {
            return null;
        }
        try {
            return new AddressPaymentObservation($address, $data['confirmed'], $data['delta'], $data['current'], $data['time']);
        } catch (Throwable) {
            return null;
        }
    }

    private function writeCache(string $key, AddressPaymentObservation $observation): void
    {
        $payload = json_encode([
            'address' => $observation->getAddress(),
            'confirmed' => $observation->getConfirmedBalanceSatoshis(),
            'delta' => $observation->getMempoolDeltaSatoshis(),
            'current' => $observation->getCurrentBalanceSatoshis(),
            'time' => $observation->getObservedAt(),
        ], JSON_THROW_ON_ERROR);
        $path = $this->path($key, 'json');
        $temp = $path . '.' . bin2hex(random_bytes(8));
        try {
            if (@file_put_contents($temp, $payload) !== strlen($payload) || !@rename($temp, $path)) {
                throw $this->busy();
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
