<?php
// admin/index.php - Hlavní Dashboard BTCPay Lite
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);

    // Statistiky pro dashboard
    $totalStores = $db->getPdo()->query("SELECT COUNT(*) FROM stores")->fetchColumn();
    $totalInvoices = $db->getPdo()->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
    $settledInvoices = $db->getPdo()->query("SELECT COUNT(*) FROM invoices WHERE status = 'Settled'")->fetchColumn();
    $totalBtcVolume = $db->getPdo()->query("SELECT SUM(amount) FROM invoices WHERE status = 'Settled'")->fetchColumn() ?? 0;

    // Načtení posledních faktur s názvem obchodu
    $invoices = $db->getPdo()->query("
        SELECT i.*, s.name as store_name 
        FROM invoices i 
        LEFT JOIN stores s ON i.store_id = s.id 
        ORDER BY i.created_at DESC 
        LIMIT 20
    ")->fetchAll();

} catch (Exception $e) {
    die("Chyba při načítání dashboardu: " . htmlspecialchars($e->getMessage()));
}

// ==========================================
// VYKRESLENÍ ŠABLONY (VIEW)
// ==========================================
require __DIR__ . '/views/index_view.php';