<?php
// test_api_webhook.php - Simulace e-shopu, který se snaží nastavit Webhook

require_once __DIR__ . '/../vendor/autoload.php';

use BtcPayLite\AuthManager;

AuthManager::requireRole('admin', '../login');

// 1. ZDE DOPLŇ SVÉ STORE ID z administrace!
$storeId = 'store_32159cbb40'; // Změň za své ID!
$apiUrl = 'http://localhost/api/v1/stores/' . $storeId . '/webhooks';

// 2. TUTO ADRESU ZA CHVÍLI ZMĚNÍME NA TU Z WEBHOK.SITE
$webhookUrl = 'https://webhook.site/b69d01d3-10a9-4173-8f22-0aaea9fc3b98';

$payload = json_encode([
    'url' => $webhookUrl
]);

echo "<h2>Odesílám požadavek na vytvoření webhooku do BTCPay Lite...</h2>";
echo "Cílové API: <strong>" . $apiUrl . "</strong><br>";
echo "URL pro notifikace: <strong>" . $webhookUrl . "</strong><br><br>";

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>Odpověď z API (HTTP Kód: $httpCode)</h3>";
if ($httpCode === 200) {
    echo "<p style='color:green; font-weight:bold;'>✅ Webhook úspěšně vytvořen (nebo už existoval)!</p>";
} else {
    echo "<p style='color:red; font-weight:bold;'>❌ Chyba při vytváření!</p>";
}

// Hezké naformátování JSON odpovědi
$data = json_decode($response, true);
echo "<pre style='background:#f4f4f4; padding:15px; border-radius:8px;'>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>";
?>