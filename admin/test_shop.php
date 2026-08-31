<?php
// test_shop.php - Simulace e-shopu
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BtcPayLite\AuthManager;

AuthManager::requireRole('admin', '../login');

// ==========================================
// 1. NASTAVENÍ (Vyplň své Store ID z databáze)
// ==========================================
$storeId = 'store_32159cbb40'; // ZMĚŇ ZA SVÉ ID OBCHODU!
$apiUrl = 'http://localhost/api/v1/stores/' . $storeId . '/invoices';

// Náhodné číslo objednávky a částka
$orderId = 'OBJ-' . rand(1000, 9999);
$amountBtc = 0.00015; // Cena zboží v BTC

// ==========================================
// 2. ODESLÁNÍ POŽADAVKU NA NAŠE API
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy'])) {
    
    // Data, která WooCommerce posílá do BTCPay
    $payload = json_encode([
        'amount' => $amountBtc,
        'metadata' => [
            'orderId' => $orderId,
            'customerName' => 'Jan Novák'
        ],
        // Sem by náš Cron poslal webhook, až zákazník zaplatí
        'notificationUrl' => 'http://localhost/test_webhook_prijimac.php' 
    ]);

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

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        // Úspěšně vytvořeno - přesměrujeme zákazníka na platební bránu
        header("Location: " . $data['checkoutLink']);
        exit;
    } else {
        $error = "Chyba API (Kód $httpCode): " . $response;
    }
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>Můj Falešný E-shop</title>
  <style>
    body { font-family: sans-serif; background: #f4f4f5; padding: 50px; text-align: center; }
    .product { background: #fff; padding: 30px; border-radius: 10px; max-width: 400px; margin: 0 auto; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    button { background: #f7931a; color: white; border: none; padding: 15px 30px; font-size: 16px; font-weight: bold; border-radius: 5px; cursor: pointer; margin-top: 20px;}
    button:hover { background: #e08316; }
    .error { color: red; margin-top: 20px; word-break: break-all;}
  </style>
</head>
<body>
    <div class="product">
        <h2>Koupit Zlaté Hodinky</h2>
        <p>Cena: <strong><?php echo $amountBtc; ?> BTC</strong></p>
        <p>Číslo objednávky: <?php echo $orderId; ?></p>
        
        <form method="POST">
            <button type="submit" name="buy">Zaplatit přes Bitcoin</button>
        </form>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
    </div>
</body>
</html>