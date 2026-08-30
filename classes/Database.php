<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use PDOException;
use Throwable;

/**
 * Configures the PDO connection and owns database-level concurrency helpers.
 */
class Database
{
    private const MAX_LOCK_NAME_BYTES = 64;
    private const MAX_LOCK_TIMEOUT_SECONDS = 30;

    private PDO $pdo;

    public function __construct(
        string $host,
        string $databaseName,
        string $user,
        string $password,
        int $port = 3306
    ) {
        $host = $this->validateHost($host);
        $databaseName = $this->validateDatabaseName($databaseName);
        $user = $this->validateUser($user);
        if (str_contains($password, "\0")) {
            throw new DatabaseException('Database password is invalid.', 'configure');
        }
        if ($port < 1 || $port > 65_535) {
            throw new DatabaseException('Database port is invalid.', 'configure');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $databaseName
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            $this->pdo = $this->createPdo($dsn, $user, $password, $options);
        } catch (PDOException $exception) {
            throw new DatabaseException(
                'Unable to connect to the database.',
                'connect',
                previous: $exception
            );
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Executes a callback in a transaction owned by this method.
     */
    public function transactional(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new DatabaseException('A database transaction is already active.', 'begin_transaction');
        }

        try {
            $this->pdo->beginTransaction();
            $result = $callback($this->pdo);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Runs a callback while holding a connection-scoped MySQL/MariaDB lock.
     */
    public function withNamedLock(string $lockName, int $timeoutSeconds, callable $callback): mixed
    {
        $lockName = trim($lockName);
        if (
            $lockName === ''
            || strlen($lockName) > self::MAX_LOCK_NAME_BYTES
            || str_contains($lockName, "\0")
        ) {
            throw new DatabaseException('Database lock name is invalid.', 'configure_lock');
        }
        if ($timeoutSeconds < 0 || $timeoutSeconds > self::MAX_LOCK_TIMEOUT_SECONDS) {
            throw new DatabaseException('Database lock timeout is invalid.', 'configure_lock');
        }

        try {
            $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $statement->execute([$lockName, $timeoutSeconds]);
            $acquired = (string) $statement->fetchColumn() === '1';
        } catch (PDOException $exception) {
            throw new DatabaseException(
                'Database lock could not be acquired.',
                'acquire_lock',
                previous: $exception
            );
        }

        if (!$acquired) {
            throw new DatabaseException('Database lock is currently busy.', 'acquire_lock', 503);
        }

        $result = null;
        $callbackFailure = null;
        try {
            $result = $callback($this->pdo);
        } catch (Throwable $exception) {
            $callbackFailure = $exception;
        }

        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
            $released = (string) $statement->fetchColumn() === '1';
            if (!$released && $callbackFailure === null) {
                throw new DatabaseException('Database lock could not be released.', 'release_lock');
            }
        } catch (Throwable $releaseFailure) {
            if ($callbackFailure === null) {
                if ($releaseFailure instanceof DatabaseException) {
                    throw $releaseFailure;
                }

                throw new DatabaseException(
                    'Database lock could not be released.',
                    'release_lock',
                    previous: $releaseFailure
                );
            }

            error_log('Database lock release failed after callback failure: ' . $releaseFailure->getMessage());
        }

        if ($callbackFailure !== null) {
            throw $callbackFailure;
        }

        return $result;
    }

    /** @param array<int, mixed> $options */
    protected function createPdo(string $dsn, string $user, string $password, array $options): PDO
    {
        return new PDO($dsn, $user, $password, $options);
    }

    private function validateHost(string $host): string
    {
        $host = trim($host);
        if (
            $host === ''
            || strlen($host) > 253
            || !preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $host)
        ) {
            throw new DatabaseException('Database host is invalid.', 'configure');
        }

        return $host;
    }

    private function validateDatabaseName(string $databaseName): string
    {
        $databaseName = trim($databaseName);
        if (!preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/D', $databaseName)) {
            throw new DatabaseException('Database name is invalid.', 'configure');
        }

        return $databaseName;
    }

    private function validateUser(string $user): string
    {
        $user = trim($user);
        if ($user === '' || strlen($user) > 128 || str_contains($user, "\0")) {
            throw new DatabaseException('Database user is invalid.', 'configure');
        }

        return $user;
    }
}
