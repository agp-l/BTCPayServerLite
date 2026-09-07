<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Manages fine-grained, per-wallet exclusive locks for mutating operations.
 *
 * Prevents race conditions during address creation or wallet mutations
 * without blocking other wallets or concurrent read queries.
 */
class WalletLockManager
{
    private string $lockDir;

    public function __construct(?string $lockDir = null)
    {
        $this->lockDir = $lockDir !== null && $lockDir !== ''
            ? rtrim($lockDir, '/\\')
            : (getenv('BTCPAY_WALLET_LOCK_DIR') ?: dirname(__DIR__) . '/var/locks');
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
        $walletHash = hash('sha256', self::canonicalWalletPath($walletPath));
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0770, true) && !is_dir($this->lockDir)) {
            throw new WalletBusyException('Wallet lock directory is unavailable.', 2, 503);
        }

        return $this->withFileLock($walletHash, $callback, $timeoutSeconds);
    }

    /** Canonical daemon path; configure absolute, non-aliased paths in every process. */
    public static function canonicalWalletPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('An absolute Electrum wallet path is required.');
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        $canonical = '/' . implode('/', $parts);
        return realpath($canonical) ?: $canonical;
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
