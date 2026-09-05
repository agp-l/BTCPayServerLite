<?php

declare(strict_types=1);

namespace BtcPayLite;

use Throwable;

/**
 * Manages fine-grained, per-wallet exclusive locks for mutating operations.
 *
 * Prevents race conditions during address creation or wallet mutations
 * without blocking other wallets or concurrent read queries.
 */
class WalletLockManager
{
    private ?Database $database;
    private string $lockDir;

    public function __construct(?Database $database = null, ?string $lockDir = null)
    {
        $this->database = $database;
        $this->lockDir = $lockDir !== null && $lockDir !== ''
            ? rtrim($lockDir, '/\\')
            : sys_get_temp_dir();
    }

    /**
     * Executes a callback under an exclusive per-wallet lock with a short timeout.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     *
     * @throws WalletBusyException if the lock cannot be acquired within timeoutSeconds
     */
    public function withWalletLock(string $walletPath, callable $callback, int $timeoutSeconds = 3): mixed
    {
        $walletHash = hash('sha256', trim($walletPath));

        if ($this->database !== null) {
            return $this->withDbLock($walletHash, $callback, $timeoutSeconds);
        }

        return $this->withFileLock($walletHash, $callback, $timeoutSeconds);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withDbLock(string $walletHash, callable $callback, int $timeoutSeconds): mixed
    {
        // MySQL GET_LOCK max name length is 64 characters
        $lockName = 'el_w_' . substr($walletHash, 0, 48);

        try {
            return $this->database->withNamedLock($lockName, $timeoutSeconds, $callback);
        } catch (DatabaseException $e) {
            if ($e->getCode() === 503 || str_contains(strtolower($e->getMessage()), 'busy')) {
                throw new WalletBusyException('Wallet is currently busy. Please retry shortly.', 2, 503);
            }
            throw $e;
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withFileLock(string $walletHash, callable $callback, int $timeoutSeconds): mixed
    {
        $lockPath = $this->lockDir . DIRECTORY_SEPARATOR . 'btcpay_w_' . substr($walletHash, 0, 32) . '.lock';
        $handle = @fopen($lockPath, 'c');

        if ($handle === false) {
            throw new WalletBusyException('Cannot open wallet lock file.', 2, 503);
        }

        $startTime = microtime(true);
        $acquired = false;

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }
            usleep(50000); // 50ms wait
        } while ((microtime(true) - $startTime) < $timeoutSeconds);

        if (!$acquired) {
            fclose($handle);
            throw new WalletBusyException('Wallet is currently busy. Please retry shortly.', 2, 503);
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
