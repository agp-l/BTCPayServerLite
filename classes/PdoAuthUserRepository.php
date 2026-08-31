<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use PDOException;
use Throwable;

/**
 * Narrow PDO boundary for users and persistent login throttling.
 */
class PdoAuthUserRepository implements AuthUserRepository
{
    private PDO $pdo;

    public function __construct(Database $database)
    {
        $this->pdo = $database->getPdo();
    }

    public function findByEmail(string $email): ?array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, email, password_hash, role FROM users WHERE email = ? LIMIT 1'
            );
            $statement->execute([$email]);
            $user = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            throw new AuthException(
                'Přihlášení nyní nelze dokončit. Zkuste to prosím později.',
                previous: $exception
            );
        }

        if ($user === false) {
            return null;
        }
        if (
            !is_numeric($user['id'] ?? null)
            || !is_string($user['email'] ?? null)
            || !is_string($user['password_hash'] ?? null)
            || !is_string($user['role'] ?? null)
        ) {
            throw new AuthException('Přihlášení nyní nelze dokončit. Zkuste to prosím později.');
        }

        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'password_hash' => $user['password_hash'],
            'role' => $user['role'],
        ];
    }

    public function createClient(string $email, string $passwordHash): int
    {
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'client')"
            );
            $statement->execute([$email, $passwordHash]);
            $userId = $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new AuthException('Registraci s těmito údaji nelze dokončit.');
            }

            throw new AuthException(
                'Registraci nyní nelze dokončit. Zkuste to prosím později.',
                previous: $exception
            );
        }

        if (!ctype_digit($userId) || (int) $userId < 1) {
            throw new AuthException('Registraci nyní nelze dokončit. Zkuste to prosím později.');
        }

        return (int) $userId;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        try {
            $statement = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $statement->execute([$passwordHash, $userId]);
        } catch (Throwable $exception) {
            error_log('Password rehash could not be stored: ' . $exception->getMessage());
        }
    }

    public function countRecentLoginFailures(string $identityHash, int $since): int
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM auth_login_attempts '
                . 'WHERE identity_hash = UNHEX(?) AND attempted_at >= ?'
            );
            $statement->execute([$identityHash, $since]);

            return (int) $statement->fetchColumn();
        } catch (Throwable $exception) {
            throw new AuthException(
                'Přihlášení nyní nelze dokončit. Zkuste to prosím později.',
                previous: $exception
            );
        }
    }

    public function recordLoginFailure(string $identityHash, int $attemptedAt): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO auth_login_attempts (identity_hash, attempted_at) '
                . 'VALUES (UNHEX(?), ?)'
            );
            $statement->execute([$identityHash, $attemptedAt]);
        } catch (Throwable $exception) {
            throw new AuthException(
                'Přihlášení nyní nelze dokončit. Zkuste to prosím později.',
                previous: $exception
            );
        }
    }

    public function clearLoginFailures(string $identityHash): void
    {
        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM auth_login_attempts WHERE identity_hash = UNHEX(?)'
            );
            $statement->execute([$identityHash]);
        } catch (Throwable $exception) {
            error_log('Login failures could not be cleared: ' . $exception->getMessage());
        }
    }
}
