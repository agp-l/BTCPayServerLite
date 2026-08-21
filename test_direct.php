<?php
$wallet = '/opt/btcpay_wallets/store_e828d46940_wallet';

echo "<h3>1. Načítám peněženku...</h3>";
$payload1 = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'load_wallet', 'params' => ['wallet_path' => $wallet]]);
$ch = curl_init('http://127.0.0.1:7777');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, "ag:silne-heslo");
$res1 = curl_exec($ch);
echo "<pre style='background:#eee; padding:10px;'>" . htmlspecialchars((string)$res1) . "</pre>";

echo "<h3>2. Generuji adresu...</h3>";
// Změněno: method na 'add_request' a memo na 'message'
// Změněno: vracíme se k 'memo'
$payload2 = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'add_request', 'params' => ['amount' => 0.0015, 'memo' => 'test_123']]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload2);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch2, CURLOPT_USERPWD, "ag:silne-heslo");
$res2 = curl_exec($ch2);
echo "<pre style='background:#eee; padding:10px;'>" . htmlspecialchars((string)$res2) . "</pre>";
?>