<?php
// login.php - Přihlašovací brána
declare(strict_types=1);
session_start();

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';
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

            // Směrovač podle role
            if ($user['role'] === 'admin') {
                header("Location: admin/index.php");
            } else {
                header("Location: client/index.php");
            }
            exit;
        } else {
            $error = "Nesprávný e-mail nebo heslo.";
        }
    } catch (Exception $e) {
        $error = "Chyba systému: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Přihlášení - BTCPay Lite</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background: #f0f4f1; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; color: #17201a; }
    .login-box { background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 8px 30px rgba(20,45,28,.06); width: 100%; max-width: 360px; text-align: center; border: 1px solid #e5eae7; }
    h1 { margin: 0 0 20px 0; font-size: 22px; }
    .error { color: #ef4d4d; background: #fff0f0; padding: 10px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; font-weight: 600; }
    input { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid #e5eae7; border-radius: 10px; font-size: 14px; box-sizing: border-box; outline: none; transition: 0.2s; }
    input:focus { border-color: #2fd35a; box-shadow: inset 0 0 0 1px #2fd35a; }
    button { width: 100%; padding: 13px; background: #2fd35a; color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: 0.2s; }
    button:hover { background: #20b948; }
    .footer-link { display: block; margin-top: 20px; font-size: 13px; color: #748078; text-decoration: none; }
    .footer-link:hover { color: #20b948; }
  </style>
</head>
<body>
    <div class="login-box">
        <h1>Vítejte zpět</h1>
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <input type="email" name="email" placeholder="E-mailová adresa" required>
            <input type="password" name="password" placeholder="Heslo" required>
            <button type="submit">Přihlásit se</button>
        </form>
        <a href="register.php" class="footer-link">Nemáte účet? Zaregistrujte se</a>
    </div>
</body>
</html>