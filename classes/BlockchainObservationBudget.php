<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use Throwable;

/** Short shared admission lock; never holds a global lock around network I/O. */
final class BlockchainObservationBudget
{
    public const DEFAULT_PER_MINUTE = 60;
    public const MAX_CONCURRENT = 2;
    private Closure $clock;
    private string $path;

    public function __construct(
        string $directory,
        string $endpoint,
        private int $perMinute = self::DEFAULT_PER_MINUTE,
        ?callable $clock = null
    ) {
        if ($perMinute < 1 || $perMinute > 600) { throw new \InvalidArgumentException('Observation budget must be between 1 and 600 per minute.'); }
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
        $this->path = $directory . '/budget-' . hash('sha256', $endpoint) . '.state';
    }

    public static function fromEnvironment(string $directory, string $endpoint): self
    {
        $value = getenv('BTCPAY_BLOCKCHAIN_OBSERVATIONS_PER_MINUTE');
        if ($value === false || $value === '') { return new self($directory, $endpoint); }
        if (!ctype_digit($value)) { throw new \InvalidArgumentException('Invalid observation budget configuration.'); }
        return new self($directory, $endpoint, (int) $value);
    }

    public function acquire(int $maxSeconds): string
    {
        return $this->update(function (array &$state, int $now) use ($maxSeconds): string {
            if (($state['blocked_until'] ?? 0) > $now) { throw $this->unavailable('upstream_circuit_open'); }
            if (count($state['active']) >= self::MAX_CONCURRENT) { throw $this->unavailable('observation_concurrency'); }
            if (count($state['starts']) >= $this->perMinute) { throw $this->unavailable('observation_budget'); }
            $token = bin2hex(random_bytes(16));
            $state['starts'][] = $now;
            // Charge before RPC. A killed observer cannot immediately bypass the budget.
            $state['active'][$token] = $now + $maxSeconds + 5;
            return $token;
        });
    }

    public function release(string $token, ?Throwable $error = null): void
    {
        $this->update(function (array &$state, int $now) use ($token, $error): void {
            unset($state['active'][$token]);
            for ($cause = $error, $i = 0; $cause !== null && $i < 8; $cause = $cause->getPrevious(), ++$i) {
                if ($cause instanceof \BtcPayLite\ElectrumRPCException
                    && in_array($cause->getType(), ['authentication', 'transport', 'http', 'protocol'], true)) {
                    $state['blocked_until'] = $now + 60;
                    break;
                }
            }
        });
    }

    private function update(callable $operation): mixed
    {
        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false) { throw $this->unavailable('cache_lock_open'); }
        $deadline = microtime(true) + 0.1; $locked = false;
        try {
            do {
                $locked = flock($lock, LOCK_EX | LOCK_NB);
                if ($locked) { break; }
                usleep(1000);
            } while (microtime(true) < $deadline);
            if (!$locked) { throw $this->unavailable('observation_concurrency'); }
            if (is_file($this->path) && filesize($this->path) > 32768) { throw $this->unavailable('cache_write'); }
            $state = json_decode((string) @file_get_contents($this->path), true);
            if (!is_array($state)) {
                if (is_file($this->path)) { throw $this->unavailable('cache_write'); }
                $state = ['starts' => [], 'active' => [], 'blocked_until' => 0];
            }
            if (!is_array($state['starts'] ?? null) || !is_array($state['active'] ?? null)) { throw $this->unavailable('cache_write'); }
            $now = ($this->clock)();
            $state['starts'] = array_values(array_filter($state['starts'], static fn ($at) => is_int($at) && $at > $now - 60));
            $state['active'] = array_filter($state['active'], static fn ($until) => is_int($until) && $until > $now);
            $result = $operation($state, $now);
            $payload = json_encode($state, JSON_THROW_ON_ERROR);
            $temp = $this->path . '.' . bin2hex(random_bytes(8));
            try {
                if (@file_put_contents($temp, $payload) !== strlen($payload) || !@rename($temp, $this->path)) {
                    throw $this->unavailable('cache_write');
                }
            } finally { if (is_file($temp)) { @unlink($temp); } }
            return $result;
        } finally {
            if ($locked) { flock($lock, LOCK_UN); }
            fclose($lock);
        }
    }

    private function unavailable(string $reason): BlockchainProviderException
    {
        return new BlockchainProviderException('Blockchain observation temporarily limited.', 'observe_address', 503, null, $reason);
    }
}
