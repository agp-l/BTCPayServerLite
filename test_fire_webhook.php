<?php
// test_fire_webhook.php - Simulace odeslání notifikace o úspěšné platbě

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';
use BtcPayLite\Database;

// 1. ZDE ZADEJ SVÉ ID OBCHODU
$storeId = 'store_32159cbb40'; // Změň za své ID!
$invoiceId = 'inv_989537810bdc'; // Faktura, která se "jakoby" zaplatila

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    // VYBEREME VŠECHNY WEBHOOKY PRO DANÝ OBCHOD (bez LIMIT 1)
    $stmt = $db->getPdo()->prepare("SELECT url, secret FROM webhooks WHERE store_id = ?");
    $stmt->execute([$storeId]);
    $webhooks = $stmt->fetchAll();

    if (!$webhooks) {
        die("Chyba: Pro tento obchod není v databázi žádný webhook.");
    }

    echo "<h3>Odesílám notifikaci o zaplacení (Počet cílů: " . count($webhooks) . ")</h3>";

    // PROJDEME KAŽDÝ WEBHOOK A ODEŠLEME ZPRÁVU
    foreach ($webhooks as $index => $webhook) {
        echo "Cíl " . ($index + 1) . ": <strong>" . htmlspecialchars($webhook['url']) . "</strong><br>";

        $payload = json_encode([
            'type' => 'InvoiceSettled',
            'invoiceId' => $invoiceId,
            'storeId' => $storeId,
            'manuallyMarked' => false
        ]);

        // Podpis unikátní pro každý webhook (každý má svůj vlastní secret)
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $webhook['secret']);

        $ch = curl_init($webhook['url']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Btcpay-Sig: ' . $signature
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            echo "<p style='color:green; margin-top:5px;'>✅ Úspěch (HTTP $httpCode)</p>";
        } else {
            echo "<p style='color:red; margin-top:5px;'>❌ Chyba (HTTP $httpCode)</p>";
        }
        echo "<hr style='border: 0; border-top: 1px solid #ddd; margin: 15px 0;'>";
    }

} catch (Exception $e) {
    echo "Chyba: " . $e->getMessage();
}
?>