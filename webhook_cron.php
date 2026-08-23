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
    
    // Nyní už nepotřebujeme api_key, stačí nám cesty a stavy
    $stmt = $db->getPdo()->query("
        SELECT i.id, i.store_id, i.status, s.wallet_path 
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
        
        // ZÍSKÁNÍ ZÁMKU PRO ELECTRUM DÉMONA
        $db->getPdo()->query("SELECT GET_LOCK('electrum_rpc', 10)")->fetchColumn();
        
        try {
            $wallet->loadWallet($inv['wallet_path']);
            $statusData = $invoiceManager->checkDatabasePaymentStatus($inv['id']);
            
            // DŮLEŽITÉ: Uvolnění zámku PŘED odesíláním webhooků, aby se neblokoval démon!
            $db->getPdo()->query("SELECT RELEASE_LOCK('electrum_rpc')")->fetchColumn();
        } catch (Exception $e) {
            // Záchranné uvolnění zámku při chybě
            $db->getPdo()->query("SELECT RELEASE_LOCK('electrum_rpc')")->fetchColumn();
            echo "Chyba RPC: " . $e->getMessage() . ". ";
            continue; // Přeskočíme na další fakturu
        }

        $newStatus = $statusData['status'];

        if ($newStatus !== $inv['status']) {
            echo "STAV ZMĚNĚN na {$newStatus}! ";
            
            $eventType = null;
            if ($newStatus === 'Processing') $eventType = 'InvoiceProcessing';
            elseif ($newStatus === 'Settled') $eventType = 'InvoiceSettled';
            elseif ($newStatus === 'Expired') $eventType = 'InvoiceExpired';
            
            if ($eventType) {
                // OPRAVA: Vybereme URL i SECRET pro všechny webhooky daného obchodu
                $whStmt = $db->getPdo()->prepare("SELECT url, secret FROM webhooks WHERE store_id = ?");
                $whStmt->execute([$inv['store_id']]);
                
                foreach ($whStmt->fetchAll() as $wh) {
                    $payload = json_encode([
                        'storeId' => $inv['store_id'],
                        'invoiceId' => $inv['id'],
                        'type' => $eventType,
                        'timestamp' => time()
                    ]);
                    
                    // OPRAVA: Bezpečnostní podpis pomocí správného webhook secretu
                    $signature = 'sha256=' . hash_hmac('sha256', $payload, $wh['secret']);
                    
                    $ch = curl_init($wh['url']);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($payload),
                        'Btcpay-Sig: ' . $signature // Správná hlavička
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