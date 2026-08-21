<?php
// api.php - BTCPay Lite API (Greenfield v1 kompatibilní pro WooCommerce)
declare(strict_types=1);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcInvoiceManager;

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
} catch (\Exception $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Chyba pripojeni k databazi']));
}

$requestUri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

$path = str_replace($_SERVER['SCRIPT_NAME'], '', $requestUri);
$path = strtok($path, '?');

// ==============================================================================
// 1. ENDPOINT: Info o obchodu (GET)
// ==============================================================================
if ($method === 'GET' && preg_match('/^\/api\/v1\/stores\/([a-zA-Z0-9_-]+)$/', $path, $matches)) {
    $storeId = $matches[1];
    
    $stmt = $db->getPdo()->prepare("SELECT id, name FROM stores WHERE id = ?");
    $stmt->execute([$storeId]);
    $store = $stmt->fetch();
    
    if ($store) {
        http_response_code(200);
        echo json_encode([
            "id" => $store['id'],
            "name" => $store['name'],
            "website" => null,
            "defaultPaymentMethod" => "BTC"
        ]);
    } else {
        http_response_code(404);
        echo json_encode(["message" => "Obchod nenalezen"]);
    }
    exit;
}

// ==============================================================================
// 1.5 ENDPOINT: Detail faktury (GET)
// ==============================================================================
if ($method === 'GET' && preg_match('/^\/api\/v1\/stores\/([a-zA-Z0-9_-]+)\/invoices\/([a-zA-Z0-9_-]+)$/', $path, $matches)) {
    $storeId = $matches[1];
    $invoiceId = $matches[2];
    
    $stmt = $db->getPdo()->prepare("SELECT * FROM invoices WHERE id = ? AND store_id = ?");
    $stmt->execute([$invoiceId, $storeId]);
    $inv = $stmt->fetch();
    
    if ($inv) {
        $createdTime = is_numeric($inv['created_at']) ? (int)$inv['created_at'] : strtotime((string)$inv['created_at']);
        
        http_response_code(200);
        echo json_encode([
            "id" => $inv['id'],
            "storeId" => $inv['store_id'],
            "amount" => (float)$inv['amount'],
            "currency" => "BTC",
            "type" => "Standard",
            "status" => $inv['status'],
            "additionalStatus" => "None",
            "createdTime" => $createdTime,
            "expirationTime" => (int)$inv['expires_at'],
            "metadata" => json_decode($inv['metadata'] ?? '{}', true)
        ]);
    } else {
        http_response_code(404);
        echo json_encode(["message" => "Faktura nenalezena"]);
    }
    exit;
}

// ==============================================================================
// 2. ENDPOINT: Vytvoření faktury (POST)
// ==============================================================================
if ($method === 'POST' && preg_match('/^\/api\/v1\/stores\/([a-zA-Z0-9_-]+)\/invoices$/', $path, $matches)) {
    $storeId = $matches[1];
    
    $stmt = $db->getPdo()->prepare("SELECT * FROM stores WHERE id = ?");
    $stmt->execute([$storeId]);
    $store = $stmt->fetch();
    
    if (!$store) {
        http_response_code(404);
        die(json_encode(["message" => "Obchod nenalezen"]));
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $amount = (float)($input['amount'] ?? 0);
    
    if ($amount <= 0) {
        http_response_code(400);
        die(json_encode(["message" => "Neplatna castka"]));
    }

    try {
        $rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
        $wallet = new ElectrumWallet($rpc);
        $wallet->loadWallet($store['wallet_path']);
        $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], $db);

        $metadata = $input['metadata'] ?? [];
        $inv = $invoiceManager->createDatabaseInvoice($storeId, $amount, $metadata, 15);

        $notificationUrl = $input['checkout']['redirectURL'] ?? ($input['notificationUrl'] ?? null);
        if ($notificationUrl) {
            $whStmt = $db->getPdo()->prepare("SELECT id FROM webhooks WHERE store_id = ? AND url = ?");
            $whStmt->execute([$storeId, $notificationUrl]);
            if (!$whStmt->fetch()) {
                $whId = 'wh_' . substr(bin2hex(random_bytes(8)), 0, 10);
                $whSecret = bin2hex(random_bytes(16));
                $db->getPdo()->prepare("INSERT INTO webhooks (id, store_id, url, secret) VALUES (?, ?, ?, ?)")
                           ->execute([$whId, $storeId, $notificationUrl, $whSecret]);
            }
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $baseDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($baseDir === '/' || $baseDir === '\\') $baseDir = '';
        
        $checkoutLink = $protocol . $_SERVER['HTTP_HOST'] . rtrim($baseDir, '/\\') . '/checkout/pay.php?id=' . $inv['id'];

        http_response_code(200);
        echo json_encode([
            "id" => $inv['id'],
            "storeId" => $storeId,
            "amount" => (float)$inv['amount'],
            "currency" => "BTC",
            "type" => "Standard",
            "checkoutLink" => $checkoutLink,
            "createdTime" => $inv['created_at'],
            "expirationTime" => $inv['expires_at'],
            "monitoringTime" => $inv['expires_at'],
            "status" => "New",
            "additionalStatus" => "None",
            "metadata" => $metadata
        ]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["message" => "Chyba pri generovani faktury: " . $e->getMessage()]);
    }
    exit;
}

// ==============================================================================
// 3. ENDPOINT: Vytvoření webhooku (POST)
// ==============================================================================
if ($method === 'POST' && preg_match('/^\/api\/v1\/stores\/([a-zA-Z0-9_-]+)\/webhooks$/', $path, $matches)) {
    $storeId = $matches[1];
    
    $stmt = $db->getPdo()->prepare("SELECT id FROM stores WHERE id = ?");
    $stmt->execute([$storeId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(["message" => "Obchod nenalezen"]));
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $url = trim($input['url'] ?? '');
    
    if (empty($url)) {
        http_response_code(400);
        die(json_encode(["message" => "Chybi URL adresa pro webhook"]));
    }

    $stmt = $db->getPdo()->prepare("SELECT * FROM webhooks WHERE store_id = ? AND url = ?");
    $stmt->execute([$storeId, $url]);
    $existingWh = $stmt->fetch();

    if ($existingWh) {
        http_response_code(200);
        echo json_encode([
            "id" => $existingWh['id'],
            "enabled" => true,
            "automaticRedelivery" => true,
            "url" => $existingWh['url'],
            "secret" => $existingWh['secret']
        ]);
        exit;
    }

    $whId = 'wh_' . substr(bin2hex(random_bytes(8)), 0, 10);
    $whSecret = bin2hex(random_bytes(16));

    $stmt = $db->getPdo()->prepare("INSERT INTO webhooks (id, store_id, url, secret) VALUES (?, ?, ?, ?)");
    $stmt->execute([$whId, $storeId, $url, $whSecret]);

    http_response_code(200);
    echo json_encode([
        "id" => $whId,
        "enabled" => true,
        "automaticRedelivery" => true,
        "url" => $url,
        "secret" => $whSecret
    ]);
    exit;
}

http_response_code(404);
echo json_encode(["message" => "Endpoint nenalezen"]);