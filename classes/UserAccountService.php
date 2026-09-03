<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;

final class UserAccountService
{
    private const MIN_PASSWORD_BYTES = 12;
    private const MAX_PASSWORD_BYTES = 72;
    private const RESET_LIFETIME_SECONDS = 1800;
    private const LAST_SEEN_INTERVAL_SECONDS = 60;

    private UserAccountRepository $accounts;
    private Closure $clock;

    public function __construct(UserAccountRepository $accounts, ?callable $clock = null)
    {
        $this->accounts = $accounts;
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function isRegistrationEnabled(): bool
    {
        return $this->accounts->isRegistrationEnabled();
    }

    public function setRegistrationEnabled(bool $enabled, int $adminUserId): void
    {
        if ($adminUserId < 1) {
            throw new AuthException('Administrator is invalid.');
        }
        $this->accounts->setRegistrationEnabled($enabled, $adminUserId, $this->now());
    }

    public function validateSession(
        int $userId,
        string $role,
        int $sessionVersion,
        ?string $ipAddress,
        int $lastRecordedAt
    ): int {
        if ($userId < 1 || !in_array($role, ['admin', 'client'], true) || $sessionVersion < 1) {
            throw new AuthException('Session is no longer valid.');
        }

        $now = $this->now();
        $writeLastSeen = $lastRecordedAt < $now - self::LAST_SEEN_INTERVAL_SECONDS;
        if (!$this->accounts->validateSessionAndTouch(
            $userId,
            $role,
            $sessionVersion,
            $this->ipAddress($ipAddress),
            $now,
            $writeLastSeen
        )) {
            throw new AuthException('Session is no longer valid.');
        }

        return $writeLastSeen ? $now : $lastRecordedAt;
    }

    public function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $newPasswordConfirm
    ): int {
        $this->validateNewPassword($newPassword, $newPasswordConfirm);
        $account = $this->accounts->findAccountById($userId);
        if (
            $account === null
            || $account['status'] !== 'active'
            || !password_verify($currentPassword, $account['password_hash'])
        ) {
            throw new AuthException('Current password is incorrect.');
        }
        if (password_verify($newPassword, $account['password_hash'])) {
            throw new AuthException('New password must be different from current password.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newHash)) {
            throw new AuthException('Password cannot be changed at this time.');
        }
        $newVersion = $this->accounts->changePassword(
            $userId,
            $account['password_hash'],
            $newHash
        );
        if ($newVersion === null) {
            throw new AuthException('Account has changed in the meantime. Please log in again.');
        }

        return $newVersion;
    }

    /**
     * Returns a one-time raw token only when an active account exists.
     * Controllers must always show the same response regardless of this value.
     */
    public function requestPasswordReset(string $email, ?string $requestedIp): ?string
    {
        $email = strtolower(trim($email));
        if (
            $email === ''
            || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return null;
        }

        $account = $this->accounts->findResettableAccountByEmail($email);
        if ($account === null) {
            return null;
        }

        $now = $this->now();
        $token = bin2hex(random_bytes(32));
        $issued = $this->accounts->issuePasswordResetToken(
            $account['id'],
            hash('sha256', $token),
            $now + self::RESET_LIFETIME_SECONDS,
            $this->ipAddress($requestedIp),
            $now
        );

        return $issued ? $token : null;
    }

    public function resetPassword(
        string $token,
        string $newPassword,
        string $newPasswordConfirm
    ): void {
        $this->validateNewPassword($newPassword, $newPasswordConfirm);
        $token = strtolower(trim($token));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            throw new AuthException('Password reset link is invalid or has expired.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newHash)) {
            throw new AuthException('Password cannot be changed at this time.');
        }
        if (!$this->accounts->consumePasswordResetToken(
            hash('sha256', $token),
            $newHash,
            $this->now()
        )) {
            throw new AuthException('Password reset link is invalid or has expired.');
        }
    }

    private function validateNewPassword(string $password, string $confirmation): void
    {
        $length = strlen($password);
        if ($length < self::MIN_PASSWORD_BYTES || $length > self::MAX_PASSWORD_BYTES) {
            throw new AuthException('Password must be between 12 and 72 characters long.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new AuthException('Passwords do not match.');
        }
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 1) {
            throw new AuthException('Application clock is not available.');
        }
        return $now;
    }

    private function ipAddress(?string $ipAddress): ?string
    {
        if ($ipAddress === null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $ipAddress;
    }
}
