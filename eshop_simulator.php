<?php
// eshop_simulator.php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';
use BtcPayLite\Database;

// 1. ZACHYTÁVÁNÍ WEBHOOKŮ Z CRONU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_BTCPAY_SIG'])) {
    $log = date('H:i:s') . " | WEBHOOK PŘIJAT!\nPodpis: " . $_SERVER['HTTP_BTCPAY_SIG'] . "\nTělo: " . file_get_contents('php://input') . "\n---\n";
    file_put_contents(__DIR__ . '/webhook.log', $log, FILE_APPEND);
    http_response_code(200);
    exit;
}

// 2. INICIALIZACE A VYHLEDÁNÍ OBCHODU
$db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
$store = $db->getPdo()->query("SELECT id FROM stores LIMIT 1")->fetch();
if (!$store) die("Chyba: V systému není žádný obchod.");

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$apiUrl = $baseUrl . '/api.php/api/v1/stores/' . $store['id'] . '/invoices';
$webhookUrl = $baseUrl . '/eshop_simulator.php'; // Simulátor zachytí webhook sám na sebe

// 3. ODESLÁNÍ POŽADAVKU DO API
$responseJson = null;
if (isset($_POST['buy'])) {
    $payload = json_encode([
        'amount' => 0.00015,
        'currency' => 'BTC',
        'metadata' => ['orderId' => 'SIMULACE-' . rand(100, 999)],
        'notificationUrl' => $webhookUrl
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $responseJson = curl_exec($ch);
    curl_close($ch);
}

// Načtení historie webhooků
$logData = file_exists(__DIR__ . '/webhook.log') ? file_get_contents(__DIR__ . '/webhook.log') : 'Zatím žádné webhooky...';
?>
<!doctype html>
<html lang="cs">
<head><title>Simulátor E-shopu</title></head>
<body style="font-family:sans-serif; padding:40px; background:#f0f4f1; color:#17201a;">
    <div style="max-width:600px; background:#fff; padding:30px; border-radius:12px; margin:0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
        <h2>Testovací E-shop</h2>
        <form method="POST">
            <button type="submit" name="buy" style="padding:15px; width:100%; background:#2fd35a; border:none; border-radius:8px; color:#fff; font-weight:bold; cursor:pointer;">
                Vytvořit testovací objednávku
            </button>
        </form>
        
        <?php if ($responseJson): $data = json_decode($responseJson, true); ?>
            <div style="margin-top:20px; padding:15px; background:#eafbef; border-radius:8px; border: 1px solid #13aa3d;">
                <strong>Faktura vygenerována!</strong><br><br>
                <a href="<?php echo htmlspecialchars($data['checkoutLink'] ?? ''); ?>" target="_blank" style="color:#13aa3d; font-weight:bold;">Otevřít platební bránu ➔</a>
            </div>
        <?php endif; ?>

        <h3 style="margin-top:40px;">Přijaté Webhooky z Cronu</h3>
        <textarea style="width:100%; height:200px; background:#17201a; color:#2fd35a; padding:15px; border-radius:8px; border:none;" readonly><?php echo htmlspecialchars($logData); ?></textarea>
        <button onclick="location.reload()" style="margin-top:10px; padding:8px 15px; cursor:pointer;">Obnovit log</button>
    </div>
</body>
</html>