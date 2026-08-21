<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';
use BtcPayLite\Database;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
    
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    try {
        $db->getPdo()->beginTransaction();
        
        // Vložení uživatele
        $stmt = $db->getPdo()->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
        $stmt->execute([$email, $password]);
        $userId = $db->getPdo()->lastInsertId();
        
        // Generování přístupových údajů a cest
        $storeId = 'store_' . substr(bin2hex(random_bytes(8)), 0, 10);
        $apiKey = bin2hex(random_bytes(16));
        $walletPath = '/opt/btcpay_wallets/' . $storeId . '_wallet';
        
        // Vytvoření peněženky přes příkazovou řádku
        $cmd = escapeshellcmd("electrum create -w " . escapeshellarg($walletPath));
        shell_exec($cmd);
        
        // Uložení e-shopu
        $stmt = $db->getPdo()->prepare("INSERT INTO stores (id, name, api_key, wallet_path, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$storeId, 'Můj první e-shop', $apiKey, $walletPath, $userId]);
        
        $db->getPdo()->commit();
        $msg = "✅ Úspěch! Obchod založen. Store ID: " . htmlspecialchars($storeId);
    } catch (Exception $e) {
        $db->getPdo()->rollBack();
        $msg = "❌ Chyba: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>Registrace klienta</title>
  <style>
    body { font-family: sans-serif; background: #f0f4f1; padding: 50px; text-align: center; }
    form { background: #fff; padding: 30px; border-radius: 10px; max-width: 350px; margin: 0 auto; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    input, button { width: 100%; margin-bottom: 15px; padding: 12px; box-sizing: border-box; }
    button { background: #2fd35a; color: white; border: none; font-weight: bold; cursor: pointer; }
  </style>
</head>
<body>
    <h2>Založit účet na BTCPay Lite</h2>
    <?php if (!empty($msg)) echo "<p><strong>$msg</strong></p>"; ?>
    <form method="POST">
        <input type="email" name="email" placeholder="E-mailová adresa" required>
        <input type="password" name="password" placeholder="Zvolte heslo" required>
        <button type="submit">Zaregistrovat e-shop</button>
    </form>
</body>
</html>