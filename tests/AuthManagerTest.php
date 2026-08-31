<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/AuthException.php';
require_once __DIR__ . '/../classes/AuthUserRepository.php';
require_once __DIR__ . '/../classes/AuthManager.php';

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\AuthUserRepository;

final class FakeAuthUserRepository implements AuthUserRepository
{
    /** @var array<string,array{id:int,email:string,password_hash:string,role:string}> */
    public array $users = [];
    /** @var array<string,list<int>> */
    public array $failures = [];
    public int $nextId = 1;

    public function findByEmail(string $email): ?array
    {
        return $this->users[$email] ?? null;
    }

    public function createClient(string $email, string $passwordHash): int
    {
        $id = $this->nextId++;
        $this->users[$email] = [
            'id' => $id,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => 'client',
        ];
        return $id;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        foreach ($this->users as &$user) {
            if ($user['id'] === $userId) {
                $user['password_hash'] = $passwordHash;
            }
        }
    }

    public function countRecentAttempts(string $identityHash, int $since): int
    {
        return count(array_filter(
            $this->failures[$identityHash] ?? [],
            static fn (int $attempt): bool => $attempt >= $since
        ));
    }

    public function recordAttempt(string $identityHash, int $attemptedAt): void
    {
        $this->failures[$identityHash][] = $attemptedAt;
    }

    public function clearAttempts(string $identityHash): void
    {
        unset($this->failures[$identityHash]);
    }
}

/** @var list<string> $passes */
$passes = [];

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function expectAuthException(callable $callback, string $expectedMessage): void
{
    try {
        $callback();
    } catch (AuthException $exception) {
        assertSameValue($expectedMessage, $exception->getMessage(), 'Unexpected auth error');
        return;
    }
    throw new RuntimeException('Expected AuthException was not thrown.');
}

function resetTestSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
}

ob_start();

$repository = new FakeAuthUserRepository();
$auth = new AuthManager($repository);
expectAuthException(
    static fn () => $auth->registerUser('person@example.test', 'short', 'short'),
    'Heslo musí mít 12 až 72 znaků včetně mezer.'
);
$passes[] = 'rejects a weak registration password';

$userId = $auth->registerUser(
    '  PERSON@Example.Test ',
    'correct horse battery staple',
    'correct horse battery staple'
);
assertSameValue(1, $userId, 'Unexpected user ID');
assertSameValue(true, isset($repository->users['person@example.test']), 'Email was not normalized');
$passes[] = 'normalizes email and hashes a registration password';

$wrongMessage = 'Nesprávný e-mail nebo heslo.';
expectAuthException(
    static fn () => $auth->login('missing@example.test', 'wrong-password', '127.0.0.1'),
    $wrongMessage
);
expectAuthException(
    static fn () => $auth->login('person@example.test', 'wrong-password', '127.0.0.1'),
    $wrongMessage
);
$passes[] = 'uses one error for missing users and bad passwords';

$throttledRepository = new FakeAuthUserRepository();
$throttledAuth = new AuthManager($throttledRepository);
for ($attempt = 0; $attempt < 5; $attempt++) {
    expectAuthException(
        static fn () => $throttledAuth->login('person@example.test', 'wrong-password', '203.0.113.10'),
        $wrongMessage
    );
}
expectAuthException(
    static fn () => $throttledAuth->login('person@example.test', 'wrong-password', '203.0.113.10'),
    'Příliš mnoho pokusů o přihlášení. Zkuste to znovu za 15 minut.'
);
$passes[] = 'throttles repeated failures by email and client identity';

$registrationRepository = new FakeAuthUserRepository();
$registrationAuth = new AuthManager($registrationRepository);
for ($registration = 1; $registration <= 3; $registration++) {
    $registrationAuth->recordRegistrationAttempt('198.51.100.20');
    $registrationAuth->registerUser(
        'person' . $registration . '@example.test',
        'correct horse battery staple',
        'correct horse battery staple'
    );
}
expectAuthException(
    static fn () => $registrationAuth->recordRegistrationAttempt('198.51.100.20'),
    'Z této adresy bylo provedeno příliš mnoho registrací. Zkuste to znovu za hodinu.'
);
$passes[] = 'limits wallet-producing registrations per client address';

resetTestSession();
$user = $auth->login(
    'person@example.test',
    'correct horse battery staple',
    '127.0.0.1'
);
assertSameValue(
    ['id' => 1, 'email' => 'person@example.test', 'role' => 'client'],
    $user,
    'Login returned an unsafe or incomplete user'
);
assertSameValue(1, $_SESSION['user_id'] ?? null, 'Session user was not established');
assertSameValue(true, AuthManager::hasRole('client'), 'Valid role was rejected');
assertSameValue('BTCPAYLITESESSID', session_name(), 'Session cookie name was not isolated');
assertSameValue('Lax', session_get_cookie_params()['samesite'], 'SameSite cookie policy is missing');
$passes[] = 'creates a redacted authenticated session';

$csrfToken = AuthManager::csrfToken();
AuthManager::requireCsrfToken($csrfToken);
expectAuthException(
    static fn () => AuthManager::requireCsrfToken('invalid-token'),
    'Formulář vypršel. Obnovte stránku a zkuste to znovu.'
);
$passes[] = 'creates and verifies a per-session CSRF token';

$_SESSION['auth_last_activity'] = time() - 1801;
assertSameValue(false, AuthManager::hasRole('client'), 'Expired idle session was accepted');
$passes[] = 'expires an idle authenticated session';

$auth->logout();
assertSameValue(PHP_SESSION_NONE, session_status(), 'Session was not destroyed');
$passes[] = 'destroys session state on logout';

ob_end_clean();
foreach ($passes as $pass) {
    echo '[PASS] ' . $pass . PHP_EOL;
}
echo count($passes) . ' AuthManager tests passed.' . PHP_EOL;
