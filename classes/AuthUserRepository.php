<?php

declare(strict_types=1);

namespace BtcPayLite;

interface AuthUserRepository
{
    /**
     * @return array{
     *   id:int,email:string,password_hash:string,role:string,
     *   status?:string,session_version?:int
     * }|null
     */
    public function findByEmail(string $email): ?array;

    public function createClient(string $email, string $passwordHash): int;

    public function updatePasswordHash(int $userId, string $passwordHash): void;

    public function countRecentAttempts(string $identityHash, int $since): int;

    public function recordAttempt(string $identityHash, int $attemptedAt): void;

    public function clearAttempts(string $identityHash): void;
}
