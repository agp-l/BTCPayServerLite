<?php
// client/registrace.php - Zabezpečená Registrační brána
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;
use BtcPayLite\AuthManager;

$error = '';
$successMsg = '';
$email = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    try {
        $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
        $auth = new AuthManager($db);

        $db->getPdo()->beginTransaction();

        // 1. Založení uživatele 
        $userId = $auth->registerUser($email, $password, $passwordConfirm);

        // 2. Tvorba obchodu a API klíče
        $storeId = 'store_' . substr(bin2hex(random_bytes(8)), 0, 10);
        $apiKey = 'sk_' . bin2hex(random_bytes(16));
        $walletPath = '/opt/btcpay_wallets/' . $storeId . '_wallet';
        
        // 3. Fyzické vytvoření peněženky
        $cmd = "python3 /opt/electrum/run_electrum -D /opt/electrum_config create --offline -w " . escapeshellarg($walletPath) . " 2>&1";
        shell_exec($cmd);

        if (!file_exists($walletPath)) {
            // Pokud se peněženka nevytvoří, vyhodíme fatální systémovou chybu
            throw new \Error("Fyzické vytvoření peněženky selhalo.");
        }
        chmod($walletPath, 0664);

        // 4. Vložení obchodu do DB
        $stmt = $db->getPdo()->prepare("INSERT INTO stores (id, name, api_key, wallet_path, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$storeId, 'Můj první e-shop', $apiKey, $walletPath, $userId]);

        $db->getPdo()->commit();

        $successMsg = "Registrace proběhla úspěšně! Nyní se můžete přihlásit.";
        $email = ''; 
        
    } catch (\Exception $e) {
        // OBRANA PROTI BOTŮM: Běžné uživatelské chyby z AuthManageru (např. existující email)
        if (isset($db) && $db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        sleep(1); 
        $error = $e->getMessage();
        
    } catch (\Throwable $e) {
        // OBRANA PROTI ÚNIKU DAT: Fatální systémové chyby
        if (isset($db) && $db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        sleep(1);
        error_log("Kritická chyba registrace: " . $e->getMessage()); // Zápis do logu serveru
        $error = "Došlo k interní systémové chybě. Zkuste to prosím později."; // Bezpečná hláška pro uživatele
    }
}

require __DIR__ . '/views/registrace_view.php';