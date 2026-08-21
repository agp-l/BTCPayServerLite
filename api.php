<?php
// api.php - BTCPay Lite API (Pro napojení WooCommerce a dalších e-shopů)
declare(strict_types=1);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

// Načtení moderních závislostí
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

// Vydolujeme z URL čistou cestu (aby fungovalo např. /api.php/api/v1/stores/...)
$path = str_replace($_SERVER['SCRIPT_NAME'], '', $requestUri);
$path = strtok($path, '?');

// ==============================================================================
// 1. ENDPOINT: Info o obchodu (GET) - WooCommerce si přes toto ověřuje spojení
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
// ENDPOINT 1.5: Detail faktury (GET) - Pro ověření stavu z e-shopu
// ==============================================================================
if ($method === 'GET' && preg_match('/^\/api\/v1\/stores\/([a-zA-Z0-9_-]+)\/invoices\/([a-zA-Z0-9_-]+)$/', $path, $matches)) {
    $storeId = $matches[1];
    $invoiceId = $matches[2];
    
    $stmt = $db->getPdo()->prepare("SELECT * FROM invoices WHERE id = ? AND store_id = ?");
    $stmt->execute([$invoiceId, $storeId]);
    $inv = $stmt->fetch();
    
    if ($inv) {
        http_response_code(200);
        echo json_encode([
            "id" => $inv['id'],
            "storeId" => $inv['store_id'],
            "amount" => $inv['amount'],
            "status" => $inv['status'],
            "btcAddress" => $inv['btc_address'],
            "createdTime" => is_numeric($inv['created_at']) ? (int)$inv['created_at'] : strtotime((string)$inv['created_at']),
            "expirationTime" => $inv['expires_at']
        ]);
    } else {
        http_response_code(404);
        echo json_encode(["message" => "Faktura nenalezena"]);
    }
    exit;
}

// ==============================================================================
// 2. ENDPOINT: Vytvoření faktury (POST) - Volá e-shop při dokončení objednávky
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

        // Zápis webhooku do databáze (včetně nového secret klíče)
        $notificationUrl = $input['checkout']['redirectURL'] ?? ($input['notificationUrl'] ?? null);
        if ($notificationUrl) {
            $whStmt = $db->getPdo()->prepare("SELECT id FROM webhooks WHERE store_id = ? AND url = ?");
            $whStmt->execute([$storeId, $notificationUrl]);
            if (!$whStmt->fetch()) {
                $whId = 'wh_' . substr(bin2hex(random_bytes(8)), 0, 10);
                $whSecret = bin2hex(random_bytes(16)); // Generování tajného klíče
                $db->getPdo()->prepare("INSERT INTO webhooks (id, store_id, url, secret) VALUES (?, ?, ?, ?)")
                           ->execute([$whId, $storeId, $notificationUrl, $whSecret]);
            }
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $baseDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($baseDir === '/' || $baseDir === '\\') $baseDir = '';
        
        // Cesta ukazuje správně do složky checkout!
        $checkoutLink = $protocol . $_SERVER['HTTP_HOST'] . rtrim($baseDir, '/\\') . '/checkout/pay.php?id=' . $inv['id'];

        http_response_code(200);
        echo json_encode([
            "id" => $inv['id'],
            "storeId" => $storeId,
            "amount" => $inv['amount'],
            "checkoutLink" => $checkoutLink,
            "status" => "New"
        ]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["message" => "Chyba pri generovani faktury: " . $e->getMessage()]);
    }
    exit;
}


// ==============================================================================
// ENDPOINT 3: Vytvoření webhooku (POST) - Volá e-shop při prvotním spárování
// ==============================================================================
if ($method === 'POST' && preg_match('/^\/api\/v1\/stores\/([a-zA-Z0-9_-]+)\/webhooks$/', $path, $matches)) {
    $storeId = $matches[1];
    
    // 1. Ověření, že obchod existuje
    $stmt = $db->getPdo()->prepare("SELECT id FROM stores WHERE id = ?");
    $stmt->execute([$storeId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(["message" => "Obchod nenalezen"]));
    }

    // 2. Přečtení dat, která nám e-shop poslal (hledáme URL)
    $input = json_decode(file_get_contents('php://input'), true);
    $url = trim($input['url'] ?? '');
    
    if (empty($url)) {
        http_response_code(400);
        die(json_encode(["message" => "Chybi URL adresa pro webhook"]));
    }

    // 3. Ochrana proti duplicitám (pokud e-shop ukládá nastavení vícekrát)
    $stmt = $db->getPdo()->prepare("SELECT * FROM webhooks WHERE store_id = ? AND url = ?");
    $stmt->execute([$storeId, $url]);
    $existingWh = $stmt->fetch();

    if ($existingWh) {
        // Webhook pro tuto URL už v tomto obchodu máme, vrátíme e-shopu ten existující
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

    // 4. Vytvoření zcela nového webhooku
    $whId = 'wh_' . substr(bin2hex(random_bytes(8)), 0, 10);
    $whSecret = bin2hex(random_bytes(16));

    $stmt = $db->getPdo()->prepare("INSERT INTO webhooks (id, store_id, url, secret) VALUES (?, ?, ?, ?)");
    $stmt->execute([$whId, $storeId, $url, $whSecret]);

    // 5. Odpověď e-shopu v očekávaném formátu
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


// Pokud e-shop zavolá špatnou adresu
http_response_code(404);
echo json_encode(["message" => "Endpoint nenalezen"]);