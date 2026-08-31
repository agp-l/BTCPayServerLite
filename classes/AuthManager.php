<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Authentication, registration, CSRF and session lifecycle boundary.
 */
class AuthManager
{
    private const MIN_PASSWORD_BYTES = 12;
    private const MAX_PASSWORD_BYTES = 72;
    private const MAX_EMAIL_BYTES = 254;
    private const MAX_LOGIN_FAILURES = 5;
    private const MAX_CLIENT_FAILURES = 25;
    private const LOGIN_WINDOW_SECONDS = 900;
    private const SESSION_IDLE_SECONDS = 1800;
    private const SESSION_ABSOLUTE_SECONDS = 43200;
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    private AuthUserRepository $users;

    public function __construct(Database|AuthUserRepository $users)
    {
        $this->users = $users instanceof Database
            ? new PdoAuthUserRepository($users)
            : $users;
    }

    /** @return array{id:int,email:string,role:string} */
    public function login(string $email, string $password, string $clientIdentity = ''): array
    {
        $email = $this->normalizeEmail($email);
        if ($password === '' || strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new AuthException('Nesprávný e-mail nebo heslo.');
        }

        $now = time();
        $identityHash = hash('sha256', "account\0" . $email . "\0" . $clientIdentity);
        $clientHash = hash('sha256', "client\0" . $clientIdentity);
        $accountFailures = $this->users->countRecentLoginFailures(
            $identityHash,
            $now - self::LOGIN_WINDOW_SECONDS
        );
        $clientFailures = $clientIdentity === '' ? 0 : $this->users->countRecentLoginFailures(
            $clientHash,
            $now - self::LOGIN_WINDOW_SECONDS
        );
        if (
            $accountFailures >= self::MAX_LOGIN_FAILURES
            || $clientFailures >= self::MAX_CLIENT_FAILURES
        ) {
            throw new AuthException('Příliš mnoho pokusů o přihlášení. Zkuste to znovu za 15 minut.');
        }

        $user = $this->users->findByEmail($email);
        $passwordHash = $user['password_hash'] ?? self::DUMMY_PASSWORD_HASH;
        $passwordMatches = password_verify($password, $passwordHash);

        if ($user === null || !$passwordMatches) {
            $this->users->recordLoginFailure($identityHash, $now);
            if ($clientIdentity !== '') {
                $this->users->recordLoginFailure($clientHash, $now);
            }
            throw new AuthException('Nesprávný e-mail nebo heslo.');
        }
        if (!in_array($user['role'], ['admin', 'client'], true)) {
            throw new AuthException('Přihlášení nyní nelze dokončit. Zkuste to prosím později.');
        }

        $this->users->clearLoginFailures($identityHash);
        if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if (is_string($newHash)) {
                $this->users->updatePasswordHash($user['id'], $newHash);
            }
        }

        self::startSession();
        if (!session_regenerate_id(true)) {
            throw new AuthException('Přihlášení nyní nelze dokončit. Zkuste to prosím později.');
        }

        $_SESSION = [
            'user_id' => $user['id'],
            'role' => $user['role'],
            'email' => $user['email'],
            'auth_issued_at' => $now,
            'auth_last_activity' => $now,
        ];

        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    public function registerUser(string $email, string $password, string $passwordConfirm): int
    {
        $email = $this->normalizeEmail($email);
        $passwordLength = strlen($password);
        if ($passwordLength < self::MIN_PASSWORD_BYTES || $passwordLength > self::MAX_PASSWORD_BYTES) {
            throw new AuthException('Heslo musí mít 12 až 72 znaků včetně mezer.');
        }
        if (!hash_equals($password, $passwordConfirm)) {
            throw new AuthException('Zadaná hesla se neshodují.');
        }
        if ($this->users->findByEmail($email) !== null) {
            throw new AuthException('Registraci s těmito údaji nelze dokončit.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new AuthException('Registraci nyní nelze dokončit. Zkuste to prosím později.');
        }

        return $this->users->createClient($email, $passwordHash);
    }

    public function logout(): void
    {
        self::startSession();
        $_SESSION = [];

        if ((bool) ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }

        session_destroy();
    }

    public static function startSession(?bool $secure = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            throw new AuthException('Relaci nyní nelze bezpečně spustit.');
        }

        $secure ??= !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new AuthException('Relaci nyní nelze bezpečně spustit.');
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function requireCsrfToken(mixed $token): void
    {
        self::startSession();
        if (
            !is_string($token)
            || !isset($_SESSION['csrf_token'])
            || !is_string($_SESSION['csrf_token'])
            || !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            throw new AuthException('Formulář vypršel. Obnovte stránku a zkuste to znovu.');
        }
    }

    public static function hasRole(string $requiredRole, ?int $now = null): bool
    {
        self::startSession();
        $now ??= time();
        $issuedAt = $_SESSION['auth_issued_at'] ?? null;
        $lastActivity = $_SESSION['auth_last_activity'] ?? null;
        if (
            !is_int($_SESSION['user_id'] ?? null)
            || !is_string($_SESSION['role'] ?? null)
            || $_SESSION['role'] !== $requiredRole
            || !is_int($issuedAt)
            || !is_int($lastActivity)
            || $issuedAt > $now
            || $lastActivity > $now
            || $now - $issuedAt > self::SESSION_ABSOLUTE_SECONDS
            || $now - $lastActivity > self::SESSION_IDLE_SECONDS
        ) {
            return false;
        }

        $_SESSION['auth_last_activity'] = $now;
        return true;
    }

    public static function requireRole(string $requiredRole, string $redirectUrl = '../client/login.php'): void
    {
        self::sendPrivateResponseHeaders();
        if (!self::hasRole($requiredRole)) {
            self::clearInvalidSession();
            header('Location: ' . $redirectUrl, true, 303);
            exit;
        }
    }

    public static function sendPrivateResponseHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (
            $email === ''
            || strlen($email) > self::MAX_EMAIL_BYTES
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new AuthException('Nesprávný e-mail nebo heslo.');
        }

        return $email;
    }

    private static function clearInvalidSession(): void
    {
        self::startSession();
        $_SESSION = [];
    }
}
