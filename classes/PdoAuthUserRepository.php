<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use PDOException;
use Throwable;

/**
 * Narrow PDO boundary for users and persistent login throttling.
 */
class PdoAuthUserRepository implements AuthUserRepository, LoginTelemetryRepository
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
                'SELECT id, email, password_hash, role, status, session_version
                 FROM users WHERE email = ? LIMIT 1'
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
            'status' => is_string($user['status'] ?? null) ? $user['status'] : 'active',
            'session_version' => is_numeric($user['session_version'] ?? null)
                ? (int) $user['session_version']
                : 1,
        ];
    }

    public function recordSuccessfulLogin(int $userId, ?string $ipAddress, int $loggedInAt): void
    {
        try {
            $statement = $this->pdo->prepare(
                'UPDATE users
                 SET last_login_at = ?, last_login_ip = ?, last_seen_at = ?, last_seen_ip = ?
                 WHERE id = ?'
            );
            $statement->execute([$loggedInAt, $ipAddress, $loggedInAt, $ipAddress, $userId]);
        } catch (Throwable $exception) {
            throw new AuthException(
                'Přihlášení nyní nelze dokončit. Zkuste to prosím později.',
                previous: $exception
            );
        }
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

    public function countRecentAttempts(string $identityHash, int $since): int
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM auth_attempts '
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

    public function recordAttempt(string $identityHash, int $attemptedAt): void
    {
        try {
            $cleanup = $this->pdo->prepare(
                'DELETE FROM auth_attempts WHERE attempted_at < ?'
            );
            $cleanup->execute([$attemptedAt - 86400]);

            $statement = $this->pdo->prepare(
                'INSERT INTO auth_attempts (identity_hash, attempted_at) '
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

    public function clearAttempts(string $identityHash): void
    {
        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM auth_attempts WHERE identity_hash = UNHEX(?)'
            );
            $statement->execute([$identityHash]);
        } catch (Throwable $exception) {
            error_log('Login failures could not be cleared: ' . $exception->getMessage());
        }
    }
}
