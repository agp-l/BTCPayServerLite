<?php
// api_stateless.php
// Čistý koncový bod pro vzdálené API (Využívá Service Layer a File Locking)
declare(strict_types=1);
ini_set('display_errors', '0'); // Na produkci skrýváme interní chyby PHP
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\BtcStatelessService;

// 1. Ochrana proti souběhu (Concurrency File Lock) bez databáze
$lockFile = sys_get_temp_dir() . '/btcpay_electrum_stateless.lock';
$fp = fopen($lockFile, 'c');

if (!$fp || !flock($fp, LOCK_EX)) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Server je momentálně přetížen, zkuste to prosím za vteřinu.']);
    exit;
}

try {
    // 2. Inicializace závislostí a naší Service vrstvy
    $rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
    $wallet = new ElectrumWallet($rpc);
    
    // Pro stateless režim předáváme null místo databáze
    $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], null); 
    
    $service = new BtcStatelessService($config, $wallet, $invoiceManager);
    
    // 3. Univerzální načtení Authorization hlavičky napříč různými servery
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } else {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (isset($headers['Authorization'])) {
            $authHeader = trim($headers['Authorization']);
        } elseif (isset($headers['authorization'])) {
            $authHeader = trim($headers['authorization']);
        }
    }
    
    $apiKey = trim(str_replace('Bearer ', '', $authHeader));

    // 4. Přijetí vstupních dat
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    // 5. Předání veškeré těžké práce naší Service vrstvě
    $result = $service->createInvoiceFromApi($input, $apiKey);

    // 6. Sestavení finální URL a odeslání výsledku
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $baseDir = dirname($_SERVER['SCRIPT_NAME']);
    if ($baseDir === '/' || $baseDir === '\\') $baseDir = '';
    
    $result['url'] = $protocol . $host . rtrim($baseDir, '/\\') . '/admin/url_pay.php?inv=' . $result['token'];

    http_response_code(200);
    echo json_encode(['status' => 'success', 'data' => $result]);

} catch (\Exception $e) {
    // Chytřejší HTTP kódy podle typu výjimky (400, 401, nebo 500)
    $code = $e->getCode();
    $httpCode = ($code >= 400 && $code < 600) ? $code : 500;
    
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    // 7. BEZPEČNOSTNÍ POJISTKA: Uvolnění zámku i v případě havárie
    flock($fp, LOCK_UN);
    fclose($fp);
}