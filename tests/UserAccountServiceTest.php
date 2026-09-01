<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/AuthException.php';
require_once __DIR__ . '/../classes/UserAccountRepository.php';
require_once __DIR__ . '/../classes/UserAccountService.php';

use BtcPayLite\AuthException;
use BtcPayLite\UserAccountRepository;
use BtcPayLite\UserAccountService;

final class FakeUserAccountRepository implements UserAccountRepository
{
    public bool $registrationEnabled = true;
    /** @var array<int,array{id:int,email:string,password_hash:string,role:string,status:string,session_version:int}> */
    public array $accounts = [];
    /** @var array<string,array{user_id:int,expires_at:int,used:bool}> */
    public array $resetTokens = [];
    public int $lastResetCreatedAt = 0;

    public function isRegistrationEnabled(): bool
    {
        return $this->registrationEnabled;
    }

    public function setRegistrationEnabled(bool $enabled, int $adminUserId, int $changedAt): void
    {
        $this->registrationEnabled = $enabled;
    }

    public function findAccountById(int $userId): ?array
    {
        return $this->accounts[$userId] ?? null;
    }

    public function findResettableAccountByEmail(string $email): ?array
    {
        foreach ($this->accounts as $account) {
            if ($account['email'] === $email && $account['status'] === 'active') {
                return ['id' => $account['id'], 'email' => $account['email'], 'status' => $account['status']];
            }
        }
        return null;
    }

    public function validateSessionAndTouch(
        int $userId,
        string $role,
        int $sessionVersion,
        ?string $ipAddress,
        int $seenAt,
        bool $writeLastSeen
    ): bool {
        $account = $this->accounts[$userId] ?? null;
        return $account !== null
            && $account['role'] === $role
            && $account['status'] === 'active'
            && $account['session_version'] === $sessionVersion;
    }

    public function changePassword(
        int $userId,
        string $expectedPasswordHash,
        string $newPasswordHash
    ): ?int {
        $account = $this->accounts[$userId] ?? null;
        if ($account === null || !hash_equals($account['password_hash'], $expectedPasswordHash)) {
            return null;
        }
        $this->accounts[$userId]['password_hash'] = $newPasswordHash;
        return ++$this->accounts[$userId]['session_version'];
    }

    public function issuePasswordResetToken(
        int $userId,
        string $tokenHash,
        int $expiresAt,
        ?string $requestedIp,
        int $createdAt
    ): bool {
        if ($this->lastResetCreatedAt > $createdAt - 900) {
            return false;
        }
        $this->lastResetCreatedAt = $createdAt;
        $this->resetTokens[$tokenHash] = [
            'user_id' => $userId,
            'expires_at' => $expiresAt,
            'used' => false,
        ];
        return true;
    }

    public function consumePasswordResetToken(
        string $tokenHash,
        string $newPasswordHash,
        int $usedAt
    ): bool {
        $token = $this->resetTokens[$tokenHash] ?? null;
        if ($token === null || $token['used'] || $token['expires_at'] < $usedAt) {
            return false;
        }
        $userId = $token['user_id'];
        $this->accounts[$userId]['password_hash'] = $newPasswordHash;
        ++$this->accounts[$userId]['session_version'];
        $this->resetTokens[$tokenHash]['used'] = true;
        return true;
    }
}

function accountAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function accountExpectError(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (AuthException $exception) {
        accountAssertSame($message, $exception->getMessage(), 'Unexpected account error');
        return;
    }
    throw new RuntimeException('Expected AuthException was not thrown.');
}

$now = 1_800_000_000;
$repository = new FakeUserAccountRepository();
$repository->accounts[1] = [
    'id' => 1,
    'email' => 'client@example.test',
    'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
    'role' => 'client',
    'status' => 'active',
    'session_version' => 1,
];
$service = new UserAccountService($repository, static fn (): int => $now);

accountAssertSame(true, $service->isRegistrationEnabled(), 'Registration should be enabled');
$service->setRegistrationEnabled(false, 9);
accountAssertSame(false, $service->isRegistrationEnabled(), 'Registration was not disabled');

$newVersion = $service->changePassword(
    1,
    'correct horse battery staple',
    'a different secure passphrase',
    'a different secure passphrase'
);
accountAssertSame(2, $newVersion, 'Session version was not incremented');
accountAssertSame(
    true,
    password_verify('a different secure passphrase', $repository->accounts[1]['password_hash']),
    'Password hash was not changed'
);

accountExpectError(
    static fn () => $service->changePassword(
        1,
        'wrong password',
        'another secure passphrase',
        'another secure passphrase'
    ),
    'Současné heslo není správné.'
);

$token = $service->requestPasswordReset('client@example.test', '203.0.113.8');
accountAssertSame(true, is_string($token) && strlen($token) === 64, 'Reset token was not issued');
$service->resetPassword($token, 'reset secure passphrase', 'reset secure passphrase');
accountAssertSame(
    true,
    password_verify('reset secure passphrase', $repository->accounts[1]['password_hash']),
    'Reset password was not stored'
);
accountExpectError(
    static fn () => $service->resetPassword(
        (string) $token,
        'another reset passphrase',
        'another reset passphrase'
    ),
    'Odkaz pro obnovu hesla je neplatný nebo vypršel.'
);

$repository->accounts[1]['status'] = 'suspended';
accountExpectError(
    static fn () => $service->validateSession(1, 'client', 3, '203.0.113.8', 0),
    'Relace již není platná.'
);

echo '[PASS] controls registration policy' . PHP_EOL;
echo '[PASS] changes password and invalidates older sessions' . PHP_EOL;
echo '[PASS] issues and consumes one-time reset tokens' . PHP_EOL;
echo '[PASS] rejects suspended sessions' . PHP_EOL;
echo '4 UserAccountService tests passed.' . PHP_EOL;
