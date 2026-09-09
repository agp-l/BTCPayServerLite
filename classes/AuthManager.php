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
    private const MAX_REGISTRATIONS = 3;
    private const REGISTRATION_WINDOW_SECONDS = 3600;
    private const SESSION_IDLE_SECONDS = 28800;
    private const SESSION_ABSOLUTE_SECONDS = 43200;
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    private AuthUserRepository $users;
    private ?RememberedLogin $remembered = null;
    private static bool $restoreAttempted = false;

    public function __construct(Database|AuthUserRepository $users)
    {
        if ($users instanceof Database) { $this->remembered = new RememberedLogin($users->getPdo()); }
        $this->users = $users instanceof Database
            ? new PdoAuthUserRepository($users)
            : $users;
    }

    /** @return array{id:int,email:string,role:string} */
    public function login(string $email, string $password, string $clientIdentity = '', bool $remember = false): array
    {
        $email = $this->normalizeEmail($email);
        if ($password === '' || strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new AuthException('Nesprávný e-mail nebo heslo.');
        }

        $now = time();
        $identityHash = hash('sha256', "account\0" . $email . "\0" . $clientIdentity);
        $clientHash = hash('sha256', "client\0" . $clientIdentity);
        $accountFailures = $this->users->countRecentAttempts(
            $identityHash,
            $now - self::LOGIN_WINDOW_SECONDS
        );
        $clientFailures = $clientIdentity === '' ? 0 : $this->users->countRecentAttempts(
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
            $this->users->recordAttempt($identityHash, $now);
            if ($clientIdentity !== '') {
                $this->users->recordAttempt($clientHash, $now);
            }
            throw new AuthException('Nesprávný e-mail nebo heslo.');
        }
        if (!in_array($user['role'], ['admin', 'client'], true)) {
            throw new AuthException('Přihlášení nyní nelze dokončit. Zkuste to prosím později.');
        }
        if (($user['status'] ?? 'active') !== 'active') {
            throw new AuthException('Tento účet je pozastaven. Kontaktujte administrátora.');
        }
        $sessionVersion = is_int($user['session_version'] ?? null)
            ? $user['session_version']
            : 1;
        if ($sessionVersion < 1) {
            throw new AuthException('Přihlášení nyní nelze dokončit. Zkuste to prosím později.');
        }

        $this->users->clearAttempts($identityHash);
        if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if (is_string($newHash)) {
                $this->users->updatePasswordHash($user['id'], $newHash);
            }
        }
        if ($this->users instanceof LoginTelemetryRepository) {
            $ipAddress = filter_var($clientIdentity, FILTER_VALIDATE_IP) !== false
                ? $clientIdentity
                : null;
            $this->users->recordSuccessfulLogin($user['id'], $ipAddress, $now);
        }

        self::startSession();
        if ($remember && $this->remembered === null) { throw new AuthException('Zapamatování přihlášení vyžaduje databázi.'); }
        $deviceCookie = is_string($_COOKIE[RememberedLogin::COOKIE] ?? null) ? $_COOKIE[RememberedLogin::COOKIE] : '';
        if ($deviceCookie !== '' && $this->remembered !== null) {
            try { $this->remembered->revoke($deviceCookie); }
            catch (\Throwable $e) { throw new AuthException('Staré zapamatování nelze zrušit. Ověřte databázi a migraci 009.', previous: $e); }
        }
        $newCookie = null;
        if ($remember) {
            try { $newCookie = $this->remembered->issue(['id'=>$user['id'],'session_version'=>$sessionVersion], $now); }
            catch (\Throwable $e) { throw new AuthException('Zapamatování nelze zapnout. Spusťte migraci 009 v database_upgrade.php, nebo se přihlaste bez této volby.', previous: $e); }
        }
        self::establishSession(['id'=>$user['id'],'role'=>$user['role'],'email'=>$user['email'],'session_version'=>$sessionVersion], $now);
        RememberedLogin::cookie($newCookie ?? '', $newCookie === null ? $now-42000 : $now+RememberedLogin::LIFETIME);

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

    public function recordRegistrationAttempt(string $clientIdentity): void
    {
        if ($clientIdentity === '') {
            throw new AuthException('Registraci nyní nelze dokončit. Zkuste to prosím později.');
        }

        $now = time();
        $registrationHash = hash('sha256', "registration\0" . $clientIdentity);
        if ($this->users->countRecentAttempts(
            $registrationHash,
            $now - self::REGISTRATION_WINDOW_SECONDS
        ) >= self::MAX_REGISTRATIONS) {
            throw new AuthException(
                'Z této adresy bylo provedeno příliš mnoho registrací. Zkuste to znovu za hodinu.'
            );
        }
        $this->users->recordAttempt($registrationHash, $now);
    }

    public function logout(): void
    {
        self::startSession();
        $cookie = is_string($_COOKIE[RememberedLogin::COOKIE] ?? null) ? $_COOKIE[RememberedLogin::COOKIE] : '';
        $revokeError = null;
        try { $this->remembered?->revoke($cookie); }
        catch (\Throwable $e) { $revokeError = $e; }
        RememberedLogin::cookie('', time()-42000); $_SESSION = [];

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
        if ($revokeError !== null) { throw new AuthException('Relace byla ukončena, ale zapamatování v databázi nelze zrušit. Ověřte dostupnost databáze.', previous: $revokeError); }
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
        // Keep our sessions out of a shared pool collected using another app's
        // shorter gc_maxlifetime. The directory is private to this app/process UID.
        if (ini_get('session.save_handler') === 'files') {
            $base = session_save_path();
            if ($base === '' || str_contains($base, ';')) { $base = sys_get_temp_dir(); }
            $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'web';
            $suffix = '/btcpay-sessions-' . substr(hash('sha256', dirname(__DIR__) . $uid), 0, 16);
            if (!str_ends_with($base, $suffix)) { $base = rtrim($base, '/') . $suffix; }
            if (!is_dir($base) && !@mkdir($base, 0700) && !is_dir($base)) { throw new AuthException('Soukromý adresář relací není zapisovatelný.'); }
            if (is_link($base) || !is_writable($base)) { throw new AuthException('Soukromý adresář relací není dostupný.'); }
            session_save_path($base);
            ini_set('session.gc_maxlifetime', (string) self::SESSION_ABSOLUTE_SECONDS);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        session_name('BTCPAYLITESESSID');
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
            || (isset($_SESSION['remember_until']) && (!is_int($_SESSION['remember_until']) || $now >= $_SESSION['remember_until']))
            || $now - $issuedAt > self::SESSION_ABSOLUTE_SECONDS
            || $now - $lastActivity > self::SESSION_IDLE_SECONDS
        ) {
            if (!self::$restoreAttempted && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
                self::$restoreAttempted = true;
                if (self::restoreRemembered()) { return self::hasRole($requiredRole, $now); }
            }
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

    private static function establishSession(array $user, int $now): void
    {
        if (!session_regenerate_id(true)) { throw new AuthException('Relaci nelze obnovit.'); }
        $_SESSION = ['user_id'=>$user['id'],'role'=>$user['role'],'email'=>$user['email'],
            'session_version'=>$user['session_version'],'auth_seen_recorded_at'=>$now,
            'auth_issued_at'=>$now,'auth_last_activity'=>$now];
    }

    private static function restoreRemembered(): bool
    {
        $cookie = $_COOKIE[RememberedLogin::COOKIE] ?? null;
        if (!is_string($cookie) || $cookie === '') { return false; }
        try {
            $config = require dirname(__DIR__) . '/config.php';
            $db = new Database($config['db_host'],$config['db_name'],$config['db_user'],$config['db_pass'],(int)($config['db_port'] ?? 3306));
            $result = (new RememberedLogin($db->getPdo()))->resume($cookie);
            if ($result === null) { return false; }
            self::establishSession($result['user'], time());
            $_SESSION['remember_until'] = $result['expires_at'];
            if ($result['cookie'] !== null) { RememberedLogin::cookie($result['cookie'],$result['expires_at']); }
            return true;
        } catch (\Throwable $e) {
            error_log('Remembered login restore failed: ' . $e::class);
            return false;
        }
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
