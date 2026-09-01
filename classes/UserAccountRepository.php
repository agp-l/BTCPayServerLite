<?php

declare(strict_types=1);

namespace BtcPayLite;

interface UserAccountRepository
{
    public function isRegistrationEnabled(): bool;

    public function setRegistrationEnabled(bool $enabled, int $adminUserId, int $changedAt): void;

    /**
     * @return array{
     *   id:int,email:string,password_hash:string,role:string,status:string,session_version:int
     * }|null
     */
    public function findAccountById(int $userId): ?array;

    /**
     * @return array{id:int,email:string,status:string}|null
     */
    public function findResettableAccountByEmail(string $email): ?array;

    public function validateSessionAndTouch(
        int $userId,
        string $role,
        int $sessionVersion,
        ?string $ipAddress,
        int $seenAt,
        bool $writeLastSeen
    ): bool;

    public function changePassword(
        int $userId,
        string $expectedPasswordHash,
        string $newPasswordHash
    ): ?int;

    public function issuePasswordResetToken(
        int $userId,
        string $tokenHash,
        int $expiresAt,
        ?string $requestedIp,
        int $createdAt
    ): bool;

    public function consumePasswordResetToken(
        string $tokenHash,
        string $newPasswordHash,
        int $usedAt
    ): bool;
}
