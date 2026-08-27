<?php
// admin/stores.php
declare(strict_types=1);
session_start();
ini_set('display_errors', '0'); // Na produkci skryto
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;

$toastMsg = '';
$stores = [];

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    // Zpracování formuláře pro nový obchod
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
        $name = trim($_POST['store_name'] ?? '');
        $walletPath = trim($_POST['wallet_path'] ?? '');

        if (empty($name) || empty($walletPath)) {
            throw new \Exception("Vyplň název obchodu i cestu k peněžence.");
        }

        // Generování unikátních identifikátorů
        $storeId = 'store_' . substr(bin2hex(random_bytes(8)), 0, 10);
        $apiKey = 'sk_' . bin2hex(random_bytes(16)); // Bezpečný API klíč

        $stmt = $db->getPdo()->prepare("INSERT INTO stores (id, name, api_key, wallet_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$storeId, $name, $apiKey, $walletPath]);

        $toastMsg = "Obchod '$name' byl úspěšně vytvořen!";
    }

    // Načtení všech obchodů pro výpis
    $stores = $db->getPdo()->query("SELECT * FROM stores ORDER BY name ASC")->fetchAll();

} catch (\Throwable $e) {
    $toastMsg = "Chyba: " . $e->getMessage();
}

// Volání View šablony
require __DIR__ . '/views/stores_view.php';