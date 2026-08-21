<?php
declare(strict_types=1);
header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';
use BtcPayLite\Database;

// 1. Získání API klíče z hlavičky nebo z POST dat
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

    // 3. Načtení dat od klienta (např. z WooCommerce)
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $amount = (float)($input['amount'] ?? 0);
    
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chyba: Neplatná částka faktury']);
        exit;
    }

    $walletPath = $store['wallet_path'];
    $invoiceId = 'inv_' . substr(bin2hex(random_bytes(8)), 0, 10);

    // 4. Komunikace s Electrum Démonem (RPC)
    function electrumRpc($method, $params = [], $walletPath = null) {
        $url = 'http://127.0.0.1:7777';
        
        // Pokud pracujeme s konkrétní peněženkou, přidáme ji do parametru URL
        if ($walletPath) {
            $url .= '?wallet=' . urlencode($walletPath);
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
        
        // ODPOZNÁMKOVAT POKUD MÁ ELECTRUM HESLO:
         curl_setopt($ch, CURLOPT_USERPWD, "ag:silne-heslo");

        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }

    // A. Načteme peněženku do běžícího Electra
    electrumRpc('load_wallet', ['wallet_path' => $walletPath]);

    // B. Vytvoříme platební požadavek (vygeneruje adresu pro danou peněženku)
    $req = electrumRpc('addrequest', ['amount' => $amount, 'memo' => $invoiceId], $walletPath);

    if (isset($req['error'])) {
        throw new Exception("Chyba při generování adresy: " . $req['error']['message']);
    }

    $address = $req['result']['address'];

    // 5. Uložení do naší databáze
    $ins = $db->getPdo()->prepare("INSERT INTO invoices (id, store_id, amount, address, status) VALUES (?, ?, ?, ?, 'New')");
    $ins->execute([$invoiceId, $store['id'], $amount, $address]);

    // 6. Odpověď zpět do WooCommerce
    echo json_encode([
        'invoice_id' => $invoiceId,
        'address' => $address,
        'amount' => $amount,
        'status' => 'New'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Interní chyba systému: ' . $e->getMessage()]);
}