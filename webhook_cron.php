<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

// Ochrana
if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== $config['cron_key']) {
    die("Přístup odepřen.");
}

use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcInvoiceManager;

echo "Začínám kontrolu faktur...\n<br>";

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    $rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
    $wallet = new ElectrumWallet($rpc);
    
    // Potřebujeme i API klíč obchodu pro podepsání webhooku!
    $stmt = $db->getPdo()->query("
        SELECT i.id, i.store_id, i.status, s.wallet_path, s.api_key 
        FROM invoices i
        JOIN stores s ON i.store_id = s.id
        WHERE i.status IN ('New', 'Processing')
    ");
    $activeInvoices = $stmt->fetchAll();

    if (!$activeInvoices) {
        die("Žádné nezaplacené faktury ke kontrole.\n");
    }

    $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], $db);

    foreach ($activeInvoices as $inv) {
        echo "Faktura {$inv['id']}... ";
        
        $wallet->loadWallet($inv['wallet_path']);
        $statusData = $invoiceManager->checkDatabasePaymentStatus($inv['id']);
        $newStatus = $statusData['status'];

        if ($newStatus !== $inv['status']) {
            echo "STAV ZMĚNĚN na {$newStatus}! ";
            
            $eventType = null;
            if ($newStatus === 'Processing') $eventType = 'InvoiceProcessing';
            elseif ($newStatus === 'Settled') $eventType = 'InvoiceSettled';
            elseif ($newStatus === 'Expired') $eventType = 'InvoiceExpired';
            
            if ($eventType) {
                $whStmt = $db->getPdo()->prepare("SELECT url FROM webhooks WHERE store_id = ?");
                $whStmt->execute([$inv['store_id']]);
                
                foreach ($whStmt->fetchAll() as $wh) {
                    $payload = json_encode([
                        'storeId' => $inv['store_id'],
                        'invoiceId' => $inv['id'],
                        'type' => $eventType,
                        'timestamp' => time()
                    ]);
                    
                    // Bezpečnostní podpis BTCPay Greenfield API
                    $signature = 'sha256=' . hash_hmac('sha256', $payload, $inv['api_key']);
                    
                    $ch = curl_init($wh['url']);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Prevence zaseknutí Cronu
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($payload),
                        'BTCPay-Sig: ' . $signature // Zásadní hlavička pro e-shopy!
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                    
                    echo "Webhook odeslán. ";
                }
            }
        } else {
            echo "beze změny. ";
        }
        echo "\n<br>";
    }
    echo "Hotovo.\n";
} catch (Exception $e) {
    die("Kritická chyba: " . $e->getMessage());
}