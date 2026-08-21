<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<h2><span style='color:#2fd35a;'>BTCPay Lite</span> - Systémový Debugger & Health Check</h2>";

// 1. ZÁKLADNÍ NAČTENÍ
$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'system_checks' => [],
    'api_test' => [],
    'file_tree' => []
];

try {
    require __DIR__ . '/vendor/autoload.php';
    $config = require __DIR__ . '/config.php';
    $report['system_checks']['config'] = "✅ Config.php a Autoloader načteny úspěšně.";
} catch (Exception $e) {
    die("Kritická chyba: Nelze načíst config nebo vendor/autoload.php. " . $e->getMessage());
}

use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;

// 2. SKENOVÁNÍ STRUKTURY SOUBORŮ
function scanProjectDir($dir, &$results = [], $prefix = '') {
    $files = scandir($dir);
    foreach ($files as $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
            $results[] = $prefix . $value . " (" . substr(sprintf('%o', fileperms($path)), -4) . ")";
        } else if ($value != "." && $value != ".." && $value != "vendor" && $value != ".git") {
            $results[] = $prefix . "[" . $value . "]/";
            scanProjectDir($path, $results, $prefix . '  ');
        }
    }
    return $results;
}
$report['file_tree'] = scanProjectDir(__DIR__);

// 3. TEST DATABÁZE
$db = null;
try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    $report['system_checks']['database'] = "✅ Databáze připojena úspěšně.";
    $stmt = $db->getPdo()->query("SELECT COUNT(*) FROM stores");
    $report['system_checks']['db_stores_count'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $report['system_checks']['database'] = "❌ Chyba databáze: " . $e->getMessage();
}

// 4. TEST ELECTRUM DÉMONA
try {
    $rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
    $version = $rpc->call('version');
    $report['system_checks']['electrum_rpc'] = "✅ Electrum Démon odpovídá (Verze: " . json_encode($version) . ")";
} catch (Exception $e) {
    $report['system_checks']['electrum_rpc'] = "❌ Chyba Electrum RPC: " . $e->getMessage();
}

// 5. TEST PRÁV PRO VYTVÁŘENÍ PENĚŽENEK
$report['system_checks']['wallet_folder_writable'] = is_writable('/opt/btcpay_wallets') ? "✅ Složka /opt/btcpay_wallets je zapisovatelná." : "❌ Nelze zapisovat do /opt/btcpay_wallets! Zkontroluj práva.";

// 6. TEST GREENFIELD API (Simulace WooCommerce)
if ($db !== null) {
    $stmt = $db->getPdo()->query("SELECT id FROM stores LIMIT 1");
    $store = $stmt->fetch();
    
    if ($store) {
        $storeId = $store['id'];
        
        // Dynamické sestavení URL pro lokální test
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $baseDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($baseDir === '/' || $baseDir === '\\') $baseDir = '';
        
        // Cílová adresa simulující API volání přes api.php
        $apiUrl = $protocol . $host . $baseDir . '/api.php/api/v1/stores/' . $storeId . '/invoices';
        
        // Falešná data z WooCommerce
        $payload = json_encode([
            'amount' => 0.0015,
            'currency' => 'BTC',
            'metadata' => ['orderId' => 'DEBUG-TEST-' . rand(1000, 9999)],
            'checkout' => ['redirectURL' => 'http://localhost/success']
        ]);
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode((string)$response, true);
        
        $report['api_test'] = [
            'target_url' => $apiUrl,
            'http_code' => $httpCode,
            'request_payload' => json_decode($payload, true),
            'raw_response' => $decoded ?? $response // Uloží parsovaný JSON nebo surový text
        ];
        
        // Validace Greenfield parametrů
        if ($httpCode === 200 && is_array($decoded) && isset($decoded['currency'], $decoded['type'], $decoded['status'])) {
            if ($decoded['currency'] === 'BTC' && $decoded['type'] === 'Standard') {
                $report['system_checks']['api_simulation'] = "✅ API vrátilo 100% validní Greenfield odpověď!";
            } else {
                $report['system_checks']['api_simulation'] = "⚠️ API funguje, ale chybí správné Greenfield parametry (currency/type).";
            }
        } else {
            $report['system_checks']['api_simulation'] = "❌ Chyba API. HTTP Kód: $httpCode.";
        }
    } else {
        $report['system_checks']['api_simulation'] = "⚠️ Nelze otestovat API - v databázi není žádný obchod.";
    }
}

?>
<div style="font-family: sans-serif; background: #f9fafa; padding: 20px; border: 1px solid #e5eae7; border-radius: 8px; margin-bottom: 20px;">
    <h3>Výsledky rychlého testu:</h3>
    <ul style="list-style: none; padding: 0;">
        <?php foreach ($report['system_checks'] as $key => $val): ?>
            <li style="margin-bottom: 8px; font-size: 15px;"><strong><?php echo htmlspecialchars($key); ?>:</strong> <?php echo htmlspecialchars((string)$val); ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<h3>Kompletní systémový report (Debug log)</h3>
<p>Zde se nyní loguje i přesná struktura toho, co náš router `api.php` vygeneroval. Zkontroluj sekci <code>api_test</code> pro potvrzení detailů.</p>

<textarea style="width: 100%; height: 500px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; padding: 15px; background: #17201a; color: #2fd35a; border-radius: 8px; border: none; box-shadow: inset 0 2px 10px rgba(0,0,0,0.5);" onclick="this.select()">
<?php echo htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>
</textarea>