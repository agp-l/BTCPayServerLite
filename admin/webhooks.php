<?php
// admin/webhooks.php
declare(strict_types=1);

ini_set('display_errors', '0'); // Na produkci skryto
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;
use BtcPayLite\GreenfieldApiRepository;
use BtcPayLite\WebhookEndpointPolicy;
use BtcPayLite\AuthManager;

AuthManager::requireRole('admin', '../login');

$toastMsg = '';
$stores = [];
$webhooks = [];

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    $webhookRepository = new GreenfieldApiRepository($db);
    $webhookPolicy = new WebhookEndpointPolicy(
        null,
        ($config['allow_local_webhooks'] ?? false) === true
    );
    
    // Zpracování formulářů (Přidání a Smazání webhooku)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'create') {
            $storeId = trim($_POST['store_id'] ?? '');
            $url = trim($_POST['url'] ?? '');

            if (empty($storeId) || empty($url)) {
                throw new \Exception("Vyber obchod a zadej URL webhooku.");
            }

            $endpoint = $webhookPolicy->inspect($url);
            $webhookRepository->findOrCreateWebhook($storeId, $endpoint['url']);
            $toastMsg = "Webhook byl úspěšně přidán!";
        } elseif ($_POST['action'] === 'delete') {
            $whId = trim($_POST['webhook_id'] ?? '');
            $stmt = $db->getPdo()->prepare("DELETE FROM webhooks WHERE id = ?");
            $stmt->execute([$whId]);
            $toastMsg = "Webhook byl smazán.";
        }
    }

    // Načtení dat pro výpis
    $stores = $db->getPdo()->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll();
    $webhooks = $db->getPdo()->query("
        SELECT w.*, s.name as store_name 
        FROM webhooks w 
        LEFT JOIN stores s ON w.store_id = s.id 
        ORDER BY w.id DESC
    ")->fetchAll();

} catch (\Throwable $e) {
    $toastMsg = "Chyba: " . $e->getMessage();
}

// Volání View šablony
require __DIR__ . '/views/webhooks_view.php';