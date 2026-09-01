<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

final class PdoUserAccountRepository implements UserAccountRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function isRegistrationEnabled(): bool
    {
        $statement = $this->database->getPdo()->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'registration_enabled' LIMIT 1"
        );
        $statement->execute();
        $value = $statement->fetchColumn();

        return $value === false || (string) $value === '1';
    }

    public function setRegistrationEnabled(bool $enabled, int $adminUserId, int $changedAt): void
    {
        $statement = $this->database->getPdo()->prepare(
            "INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
             SELECT 'registration_enabled', ?, id, ? FROM users
             WHERE id = ? AND role = 'admin' AND status = 'active'
             ON DUPLICATE KEY UPDATE
                 setting_value = VALUES(setting_value),
                 updated_by = VALUES(updated_by),
                 updated_at = VALUES(updated_at)"
        );
        $statement->execute([$enabled ? '1' : '0', $changedAt, $adminUserId]);
        if ($statement->rowCount() < 1) {
            throw new AuthException('Nastavení může změnit pouze aktivní administrátor.');
        }
    }

    public function findAccountById(int $userId): ?array
    {
        $statement = $this->database->getPdo()->prepare(
            'SELECT id, email, password_hash, role, status, session_version
             FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->account($row) : null;
    }

    public function findResettableAccountByEmail(string $email): ?array
    {
        $statement = $this->database->getPdo()->prepare(
            "SELECT id, email, status FROM users
             WHERE email = ? AND status = 'active' LIMIT 1"
        );
        $statement->execute([$email]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => $this->positiveInt($row['id'] ?? null),
            'email' => $this->nonEmptyString($row['email'] ?? null),
            'status' => $this->nonEmptyString($row['status'] ?? null),
        ];
    }

    public function validateSessionAndTouch(
        int $userId,
        string $role,
        int $sessionVersion,
        ?string $ipAddress,
        int $seenAt,
        bool $writeLastSeen
    ): bool {
        if (!$writeLastSeen) {
            $statement = $this->database->getPdo()->prepare(
                "SELECT 1 FROM users
                 WHERE id = ? AND role = ? AND status = 'active' AND session_version = ?
                 LIMIT 1"
            );
            $statement->execute([$userId, $role, $sessionVersion]);
            return $statement->fetchColumn() !== false;
        }

        $statement = $this->database->getPdo()->prepare(
            "UPDATE users
             SET last_seen_at = ?, last_seen_ip = ?
             WHERE id = ? AND role = ? AND status = 'active' AND session_version = ?"
        );
        $statement->execute([$seenAt, $ipAddress, $userId, $role, $sessionVersion]);

        return $statement->rowCount() === 1;
    }

    public function changePassword(
        int $userId,
        string $expectedPasswordHash,
        string $newPasswordHash
    ): ?int {
        return $this->database->transactional(function (PDO $pdo) use (
            $userId,
            $expectedPasswordHash,
            $newPasswordHash
        ): ?int {
            $statement = $pdo->prepare(
                "UPDATE users
                 SET password_hash = ?, session_version = session_version + 1
                 WHERE id = ? AND password_hash = ? AND status = 'active'"
            );
            $statement->execute([$newPasswordHash, $userId, $expectedPasswordHash]);
            if ($statement->rowCount() !== 1) {
                return null;
            }

            $statement = $pdo->prepare('SELECT session_version FROM users WHERE id = ?');
            $statement->execute([$userId]);
            return $this->positiveInt($statement->fetchColumn());
        });
    }

    public function issuePasswordResetToken(
        int $userId,
        string $tokenHash,
        int $expiresAt,
        ?string $requestedIp,
        int $createdAt
    ): bool {
        return $this->database->transactional(function (PDO $pdo) use (
            $userId,
            $tokenHash,
            $expiresAt,
            $requestedIp,
            $createdAt
        ): bool {
            $statement = $pdo->prepare(
                'SELECT created_at FROM password_reset_tokens
                 WHERE user_id = ? AND used_at IS NULL
                 ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$userId]);
            $latest = $statement->fetchColumn();
            if ($latest !== false && (int) $latest > $createdAt - 900) {
                return false;
            }

            $statement = $pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = ?
                 WHERE user_id = ? AND used_at IS NULL'
            );
            $statement->execute([$createdAt, $userId]);

            $statement = $pdo->prepare(
                'INSERT INTO password_reset_tokens
                    (user_id, token_hash, expires_at, requested_ip, created_at)
                 VALUES (?, UNHEX(?), ?, ?, ?)'
            );
            $statement->execute([$userId, $tokenHash, $expiresAt, $requestedIp, $createdAt]);
            return true;
        });
    }

    public function consumePasswordResetToken(
        string $tokenHash,
        string $newPasswordHash,
        int $usedAt
    ): bool {
        return $this->database->transactional(function (PDO $pdo) use (
            $tokenHash,
            $newPasswordHash,
            $usedAt
        ): bool {
            $statement = $pdo->prepare(
                "SELECT prt.id, prt.user_id
                 FROM password_reset_tokens prt
                 INNER JOIN users u ON u.id = prt.user_id
                 WHERE prt.token_hash = UNHEX(?)
                   AND prt.used_at IS NULL
                   AND prt.expires_at >= ?
                   AND u.status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute([$tokenHash, $usedAt]);
            $token = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($token)) {
                return false;
            }

            $userId = $this->positiveInt($token['user_id'] ?? null);
            $statement = $pdo->prepare(
                'UPDATE users
                 SET password_hash = ?, session_version = session_version + 1
                 WHERE id = ?'
            );
            $statement->execute([$newPasswordHash, $userId]);
            if ($statement->rowCount() !== 1) {
                return false;
            }

            $statement = $pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = ?
                 WHERE user_id = ? AND used_at IS NULL'
            );
            $statement->execute([$usedAt, $userId]);
            return true;
        });
    }

    /** @param array<string,mixed> $row */
    private function account(array $row): array
    {
        return [
            'id' => $this->positiveInt($row['id'] ?? null),
            'email' => $this->nonEmptyString($row['email'] ?? null),
            'password_hash' => $this->nonEmptyString($row['password_hash'] ?? null),
            'role' => $this->nonEmptyString($row['role'] ?? null),
            'status' => $this->nonEmptyString($row['status'] ?? null),
            'session_version' => $this->positiveInt($row['session_version'] ?? null),
        ];
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            throw new AuthException('Účet nyní nelze načíst.');
        }
        if ($number < 1) {
            throw new AuthException('Účet nyní nelze načíst.');
        }
        return $number;
    }

    private function nonEmptyString(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new AuthException('Účet nyní nelze načíst.');
        }
        return $value;
    }
}
