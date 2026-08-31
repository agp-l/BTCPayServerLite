<?php

declare(strict_types=1);

namespace BtcPayLite;

interface AuthUserRepository
{
    /** @return array{id:int,email:string,password_hash:string,role:string}|null */
    public function findByEmail(string $email): ?array;

    public function createClient(string $email, string $passwordHash): int;

    public function updatePasswordHash(int $userId, string $passwordHash): void;

    public function countRecentLoginFailures(string $identityHash, int $since): int;

    public function recordLoginFailure(string $identityHash, int $attemptedAt): void;

    public function clearLoginFailures(string $identityHash): void;
}
