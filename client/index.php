<?php
// client/index.php - Klientský dashboard (Kontroler)
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Ochrana - pustíme sem jen přihlášené uživatele
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}


require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';
use BtcPayLite\Database;

$userId = $_SESSION['user_id'];
$stores = [];
$invoices = [];
$webhooks = [];
$toastMsg = '';
$clientStats = ['total_stores' => 0, 'total_invoices' => 0, 'paid_invoices' => 0];

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    // Zpracování formulářů (Tvorba e-shopu, Webhooky)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        
        // 1. AKCE: Vytvoření nového e-shopu (a peněženky na pozadí)
        if ($_POST['action'] === 'create_store') {
            $storeName = trim($_POST['store_name'] ?? '');
            if (empty($storeName)) $storeName = 'Nový e-shop';

            $storeId = 'store_' . substr(bin2hex(random_bytes(8)), 0, 10);
            $apiKey = bin2hex(random_bytes(16));
            $walletPath = '/opt/btcpay_wallets/' . $storeId . '_wallet';
            
            // Fyzické vytvoření peněženky v Linuxu
            $cmd = "python3 /opt/electrum/run_electrum -D /opt/electrum_config create --offline -w " . escapeshellarg($walletPath) . " 2>&1";
            shell_exec($cmd);

            // Nastavíme práva, aby k souboru mohl přistoupit hlavní Electrum démon
            if (file_exists($walletPath)) {
                chmod($walletPath, 0664);
            }
            
            $stmt = $db->getPdo()->prepare("INSERT INTO stores (id, name, api_key, wallet_path, user_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$storeId, $storeName, $apiKey, $walletPath, $userId]);
            $toastMsg = "Obchod $storeName byl úspěšně založen!";
        }
        
        // 2. AKCE: Přidání Webhooku
        elseif ($_POST['action'] === 'create_webhook') {
            $storeId = trim($_POST['store_id'] ?? '');
            $url = trim($_POST['url'] ?? '');

            if (!empty($storeId) && !empty($url)) {
                $stmt = $db->getPdo()->prepare("SELECT id FROM stores WHERE id = ? AND user_id = ?");
                $stmt->execute([$storeId, $userId]);
                if ($stmt->fetch()) {
                    $whId = 'wh_' . substr(bin2hex(random_bytes(8)), 0, 10);
                    $whSecret = bin2hex(random_bytes(16));
                    $insStmt = $db->getPdo()->prepare("INSERT INTO webhooks (id, store_id, url, secret) VALUES (?, ?, ?, ?)");
                    $insStmt->execute([$whId, $storeId, $url, $whSecret]);
                    $toastMsg = "Webhook byl úspěšně přidán!";
                } else {
                    $toastMsg = "Chyba: Neoprávněný přístup k obchodu.";
                }
            }
        } 
        
        // 3. AKCE: Smazání Webhooku
        elseif ($_POST['action'] === 'delete_webhook') {
            $whId = trim($_POST['webhook_id'] ?? '');
            
            $stmt = $db->getPdo()->prepare("
                SELECT w.id FROM webhooks w 
                JOIN stores s ON w.store_id = s.id 
                WHERE w.id = ? AND s.user_id = ?
            ");
            $stmt->execute([$whId, $userId]);
            if ($stmt->fetch()) {
                $delStmt = $db->getPdo()->prepare("DELETE FROM webhooks WHERE id = ?");
                $delStmt->execute([$whId]);
                $toastMsg = "Webhook byl smazán.";
            } else {
                $toastMsg = "Chyba: Nemáte oprávnění ke smazání tohoto webhooku.";
            }
        }
    }

    // Načtení klientských statistik
    $statStmt = $db->getPdo()->prepare("
        SELECT 
            (SELECT COUNT(*) FROM stores WHERE user_id = ?) as total_stores,
            (SELECT COUNT(*) FROM invoices i JOIN stores s ON i.store_id = s.id WHERE s.user_id = ?) as total_invoices,
            (SELECT COUNT(*) FROM invoices i JOIN stores s ON i.store_id = s.id WHERE s.user_id = ? AND i.status = 'Settled') as paid_invoices
    ");
    $statStmt->execute([$userId, $userId, $userId]);
    $clientStats = $statStmt->fetch() ?: $clientStats;

    // Načtení e-shopů
    $stmt = $db->getPdo()->prepare("SELECT * FROM stores WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$userId]);
    $stores = $stmt->fetchAll();

    if (!empty($stores)) {
        $storeIds = array_column($stores, 'id');
        $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
        
        // Načtení faktur
        $invStmt = $db->getPdo()->prepare("
            SELECT i.*, s.name as store_name 
            FROM invoices i 
            JOIN stores s ON i.store_id = s.id 
            WHERE i.store_id IN ($placeholders) 
            ORDER BY i.created_at DESC 
            LIMIT 30
        ");
        $invStmt->execute($storeIds);
        $invoices = $invStmt->fetchAll();

        // Načtení webhooků
        $whStmt = $db->getPdo()->prepare("
            SELECT w.*, s.name as store_name 
            FROM webhooks w 
            JOIN stores s ON w.store_id = s.id 
            WHERE w.store_id IN ($placeholders) 
            ORDER BY w.id DESC
        ");
        $whStmt->execute($storeIds);
        $webhooks = $whStmt->fetchAll();
    }
} catch (\Throwable $e) {
    $toastMsg = "Chyba při načítání dat: " . $e->getMessage();
}

require __DIR__ . '/views/index_view.php';