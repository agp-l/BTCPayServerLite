<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * In-memory or stateful circuit breaker to prevent cascading failures when Electrum daemon is down.
 */
class CircuitBreaker
{
    private const DEFAULT_FAILURE_THRESHOLD = 5;
    private const DEFAULT_COOLDOWN_SECONDS = 15;

    private int $failureThreshold;
    private int $cooldownSeconds;
    private int $consecutiveFailures = 0;
    private int $trippedAt = 0;
    private ?string $lastErrorMessage = null;

    public function __construct(
        int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS
    ) {
        $this->failureThreshold = max(1, $failureThreshold);
        $this->cooldownSeconds = max(1, $cooldownSeconds);
    }

    public function isAvailable(): bool
    {
        if ($this->trippedAt === 0) {
            return true;
        }

        if (time() - $this->trippedAt > $this->cooldownSeconds) {
            // Half-open: allow one retry
            return true;
        }

        return false;
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->trippedAt = 0;
        $this->lastErrorMessage = null;
    }

    public function recordFailure(string $errorMessage): void
    {
        $this->consecutiveFailures++;
        $this->lastErrorMessage = $errorMessage;

        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->trippedAt = time();
        }
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function isTripped(): bool
    {
        return !$this->isAvailable();
    }
}
