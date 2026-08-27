<?php
// client/login.php - Přihlašovací brána (Kontroler)
declare(strict_types=1);
session_start();

// Jsme ve složce client/, takže musíme o úroveň výš pro vendor a config
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';
use BtcPayLite\Database;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
        
        $stmt = $db->getPdo()->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Ověření uživatele a hesla
        if ($user && password_verify($password, $user['password_hash'])) {
            // Nastavení sezení (session)
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];

            // Směrovač podle role (upravené cesty!)
            if ($user['role'] === 'admin') {
                header("Location: ../admin/index.php"); // Admin je o složku výš a pak do admin/
            } else {
                header("Location: index.php"); // Klient je ve stejné složce
            }
            exit;
        } else {
            $error = "Nesprávný e-mail nebo heslo.";
        }
    } catch (\Throwable $e) {
        $error = "Chyba systému: " . $e->getMessage();
    }
}

// Načtení vizuální šablony (ta už je s námi ve složce client)
require __DIR__ . '/views/login_view.php';