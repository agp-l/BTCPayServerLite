<?php
declare(strict_types=1);
namespace BtcPayLite;

use Exception;
use PDO;

/**
 * Třída pro správu autentizace a uživatelských účtů (Zabezpečeno a Auditováno).
 */
class AuthManager
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Pokusí se přihlásit uživatele. V případě úspěchu vrátí jeho data.
     */
    public function login(string $email, string $password): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Neplatný formát e-mailové adresy.");
        }

        $stmt = $this->db->getPdo()->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            
            // OBRANA PROTI SESSION FIXATION: Okamžité přegenerování ID relace
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            // Bezpečné nastavení session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];

            return $user;
        }

        // Stejná chybová hláška zamezuje hádání existujících e-mailů (User Enumeration)
        throw new Exception("Nesprávný e-mail nebo heslo.");
    }

    /**
     * Zaregistruje nového uživatele a vrátí jeho ID.
     */
    public function registerUser(string $email, string $password, string $passwordConfirm): int
    {
        if (empty($email) || empty($password)) {
            throw new Exception("Vyplňte prosím e-mail a heslo.");
        }

        // VALIDACE FORMÁTU E-MAILU NA STRANĚ SERVERU
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Zadejte prosím platnou e-mailovou adresu.");
        }

        if ($password !== $passwordConfirm) {
            throw new Exception("Zadaná hesla se neshodují.");
        }

        $stmt = $this->db->getPdo()->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception("Tento e-mail je již zaregistrovaný.");
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->getPdo()->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'client')");
        $stmt->execute([$email, $passwordHash]);
        
        return (int)$this->db->getPdo()->lastInsertId();
    }

    /**
     * Bezpečně zničí aktuální session, odhlásí uživatele a odstraní Cookie.
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 1. Vymazání dat z paměti
        $_SESSION = [];

        // 2. Vymazání fyzické Session Cookie z prohlížeče uživatele
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // 3. Totální zničení session na serveru
        session_destroy();
    }


    /**
     * Zabezpečí stránku pouze pro určitou roli.
     * Pokud uživatel nemá oprávnění, přesměruje ho pryč.
     */
    public static function requireRole(string $requiredRole, string $redirectUrl = '../client/login.php'): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // OCHRANA PROTI "BACK BUTTON" ÚTOKU (Zákaz cachování prohlížečem)
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        // STRIKTNÍ KONTROLA ROLE
        if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== $requiredRole) {
            header("Location: " . $redirectUrl);
            exit;
        }
    }
}