<?php
///api/create_invoice.php
declare(strict_types=1);
header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';
use BtcPayLite\Database;

// 1. Získání API klíče z hlavičky
$headers = getallheaders();
$apiKey = $headers['X-API-Key'] ?? $_POST['api_key'] ?? null;

if (!$apiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Chyba: Chybí API klíč']);
    exit;
}

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    // 2. Ověření obchodu v databázi
    $stmt = $db->getPdo()->prepare("SELECT id, wallet_path FROM stores WHERE api_key = ?");
    $stmt->execute([$apiKey]);
    $store = $stmt->fetch();

    if (!$store) {
        http_response_code(403);
        echo json_encode(['error' => 'Chyba: Neplatný API klíč']);
        exit;
    }

    // 3. Načtení dat od klienta
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $amount = (float)($input['amount'] ?? 0);
    
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chyba: Neplatná částka faktury']);
        exit;
    }

    $walletPath = $store['wallet_path'];
    $invoiceId = 'inv_' . substr(bin2hex(random_bytes(8)), 0, 10);

    // 4. Komunikace s Electrum Démonem (Opravené lomítko)
    function electrumRpc($method, $params = [], $walletPath = null) {
        $url = 'http://127.0.0.1:7777';
        
        if ($walletPath) {
            $url .= '/?wallet=' . $walletPath;
        }
        
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => time(),
            'method' => $method,
            'params' => $params
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        // Naše heslo pro Electrum
        curl_setopt($ch, CURLOPT_USERPWD, "ag:silne-heslo");

        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }

    // A. Načteme peněženku do běžícího Electra
    electrumRpc('load_wallet', ['wallet_path' => $walletPath]);

    // B. Vytvoříme platební požadavek (Opravený název 'add_request' a 'memo')
    $req = electrumRpc('add_request', ['amount' => $amount, 'memo' => $invoiceId], $walletPath);

    if (isset($req['error'])) {
        throw new Exception("Chyba při generování adresy: " . json_encode($req['error']));
    }

    $address = $req['result']['address'];

// 5. Uložení do naší databáze (s číselným časem - UNIX timestamp)
    $createdAt = time(); // Aktuální čas jako číslo
    $expiresAt = $createdAt + (15 * 60); // Přidáme 15 minut (15 * 60 vteřin)

    $ins = $db->getPdo()->prepare("INSERT INTO invoices (id, store_id, amount, btc_address, status, created_at, expires_at) VALUES (?, ?, ?, ?, 'New', ?, ?)");
    $ins->execute([$invoiceId, $store['id'], $amount, $address, $createdAt, $expiresAt]);

    // 6. Odpověď zpět do WooCommerce
    echo json_encode([
        'invoice_id' => $invoiceId,
        'address' => $address,
        'amount' => round($amount, 8),
        'status' => 'New'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Interní chyba systému: ' . $e->getMessage()]);
}