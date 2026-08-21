<?php
// test_rpc.php
ini_set('display_errors', '0');
$url = 'http://127.0.0.1:7777';
$payload = json_encode([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'version', // Změněno z get_config na version
    'params' => []
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

// Tady je přidáno přihlášení pro tvého Electrum démona!
curl_setopt($ch, CURLOPT_USERPWD, "ag:silne-heslo");

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Kód: " . $httpCode . "<br>";
echo "Odpověď: " . htmlspecialchars((string)$response);
?>