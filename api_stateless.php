<?php
// api_stateless.php
// Tajné API pro vzdálené generování bezstavových URL faktur
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Vždy odpovídáme jako čistý JSON
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcDashboard;
use BtcPayLite\BtcInvoiceManager;

// 1. Zabezpečení pomocí tajného API klíče
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$expectedToken = 'Bearer ' . $config['admin_api_key']; // Zákazník musí poslat tento klíč

if ($authHeader !== $expectedToken) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Odmítnuto: Neplatný nebo chybějící API klíč.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Odmítnuto: Tento endpoint přijímá pouze POST požadavky.']);
    exit;
}

ob_start();
try {
    // 2. Načtení dat od vzdáleného systému (podporuje JSON payload i běžné POST proměnné)
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    $amount = (float)str_replace(',', '.', (string)($input['amount'] ?? '0'));
    $desc = trim($input['description'] ?? '');
    $orderId = trim($input['order_id'] ?? '');
    
    // Zvolená peněženka, nebo fallback na výchozí
    $requestedWallet = trim($input['wallet'] ?? basename($config['wallet_path']));

    if ($amount <= 0 || empty($desc)) {
        throw new \Exception("Parametry 'amount' a 'description' jsou povinné a částka musí být platná.");
    }

    // 3. Inicializace našeho Bitcoinového motoru[cite: 1]
    $rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
    $wallet = new ElectrumWallet($rpc);

    // 4. Bezpečné zacílení a načtení správné peněženky
    $walletsDirectory = dirname($config['wallet_path']);
    $walletPath = $walletsDirectory . '/' . basename($requestedWallet);
    $wallet->loadWallet($walletPath);

    // 5. Inicializace bezstavového manažera (null místo DB)[cite: 1]
    $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], null);

    // Custom data, která chceme do tokenu zašifrovat
    $customData = [
        'order_id' => $orderId,
        'wallet' => $requestedWallet
    ];

    // 6. Vygenerování tokenu s 15min expirací[cite: 1]
    $res = $invoiceManager->createStatelessInvoice($amount, $desc, $customData, 15);

    // 7. Sestavení plné URL adresy na platební bránu (url_pay.php ve složce admin)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $baseDir = dirname($_SERVER['SCRIPT_NAME']);
    if ($baseDir === '/' || $baseDir === '\\') $baseDir = '';
    
    // Tuto URL adresu posíláme zpět externímu systému
    $checkoutLink = $protocol . $host . rtrim($baseDir, '/\\') . '/admin/url_pay.php?inv=' . $res['token'];

    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => [
            'url' => $checkoutLink,
            'token' => $res['token'],
            'amount' => number_format($amount, 8, '.', ''),
            'description' => $desc,
            'order_id' => $orderId,
            'wallet' => $requestedWallet,
            'expires_in_minutes' => 15
        ]
    ]);
    exit;

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'PHP Chyba: ' . $e->getMessage()]);
    exit;
}