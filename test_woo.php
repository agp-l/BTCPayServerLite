<?php
// test_woo.php - Simulátor WooCommerce

// 1. ZDE VLOŽ SVŮJ API KLÍČ Z KLIENTSKÉ ADMINSTRACE
$mujApiKey = 'fa4db009e80a746fc1c76901ef1c461f'; 

$url = 'http://localhost/gate/create_invoice.php'; // Uprav, pokud máš složku projektu jinak

// Data, která "e-shop" posílá do API
$payload = json_encode([
    'amount' => 0.0015
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
// Přidáme API klíč do hlavičky, jak to vyžaduje tvůj skript
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-Key: ' . $mujApiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>Odpověď z našeho API:</h3>";
echo "HTTP Kód: <strong>" . $httpCode . "</strong><br><br>";
echo "JSON Odpověď:<br>";
echo "<pre style='background: #f4f4f4; padding: 15px; border-radius: 8px;'>" . htmlspecialchars((string)$response) . "</pre>";
?>