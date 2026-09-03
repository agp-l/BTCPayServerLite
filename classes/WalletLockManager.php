<?php

declare(strict_types=1);

namespace BtcPayLite;

use Throwable;

/**
 * Manages fine-grained, per-wallet application locks.
 *
 * Replaces global daemon-level mutexes with granular locks keyed by wallet identifier.
 * Mutating operations (e.g. createnewaddress, payto) obtain an exclusive lock on wallet A,
 * while wallet B continues in parallel without blocking.
 */
class WalletLockManager
{
    private const DEFAULT_TIMEOUT_SECONDS = 3;
    private ?Database $database;

    public function __construct(?Database $database = null)
    {
        $this->database = $database;
    }

    /**
     * Executes the given callable under an exclusive lock for the specified wallet.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws DatabaseException If the lock cannot be acquired in time (HTTP 503).
     */
    public function withWalletLock(string $walletIdentifier, callable $callback, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): mixed
    {
        $lockKey = 'electrum_wallet_' . substr(hash('sha256', $walletIdentifier), 0, 32);

        if ($this->database !== null) {
            return $this->database->withNamedLock($lockKey, $timeoutSeconds, function () use ($callback) {
                return $callback();
            });
        }

        // File-based lock fallback for stateless / non-database execution
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $lockKey . '.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            throw new DatabaseException('Could not create wallet lock handle.', 'acquire_wallet_lock', 503);
        }

        $startTime = microtime(true);
        $acquired = false;
        while ((microtime(true) - $startTime) < $timeoutSeconds) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }
            usleep(25_000); // 25ms backoff
        }

        if (!$acquired) {
            fclose($handle);
            throw new DatabaseException('Wallet is currently busy. Please retry shortly.', 'acquire_wallet_lock', 503);
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
