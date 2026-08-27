<?php
// registrace.php - Registrační brána (Kontroler)
declare(strict_types=1);
session_start();
ini_set('display_errors', '0'); // Na produkci skryto
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';
use BtcPayLite\Database;

$error = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    try {
        if (empty($email) || empty($password)) {
            throw new \Exception("Vyplňte prosím e-mail a heslo.");
        }

        if ($password !== $passwordConfirm) {
            throw new \Exception("Zadaná hesla se neshodují.");
        }

        if (strlen($password) < 6) {
            throw new \Exception("Heslo musí mít alespoň 6 znaků.");
        }

        $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
        
        // Kontrola, zda e-mail již neexistuje
        $stmt = $db->getPdo()->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new \Exception("Tento e-mail je již zaregistrovaný.");
        }

        // Spuštění transakce pro bezpečné založení uživatele i obchodu
        $db->getPdo()->beginTransaction();

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->getPdo()->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'client')");
        $stmt->execute([$email, $passwordHash]);
        $userId = (int)$db->getPdo()->lastInsertId();

        // Generování přístupových údajů pro výchozí obchod klienta
        $storeId = 'store_' . substr(bin2hex(random_bytes(8)), 0, 10);
        $apiKey = 'sk_' . bin2hex(random_bytes(16));
        $walletPath = '/opt/btcpay_wallets/' . $storeId . '_wallet';
        
        // Fyzické vytvoření peněženky v Linuxu přes Electrum CLI
        $cmd = "python3 /opt/electrum/run_electrum -D /opt/electrum_config create --offline -w " . escapeshellarg($walletPath) . " 2>&1";
        shell_exec($cmd);

        if (file_exists($walletPath)) {
            chmod($walletPath, 0664);
        }

        // Vložení obchodu do databáze
        $stmt = $db->getPdo()->prepare("INSERT INTO stores (id, name, api_key, wallet_path, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$storeId, 'Můj první e-shop', $apiKey, $walletPath, $userId]);

        $db->getPdo()->commit();

        $successMsg = "Registrace proběhla úspěšně! Nyní se můžete přihlásit.";
    } catch (\Throwable $e) {
        if (isset($db) && $db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Chyba při registraci: " . $e->getMessage();
    }
}

// Načtení vizuální šablony
require __DIR__ . '/client/views/registrace_view.php';