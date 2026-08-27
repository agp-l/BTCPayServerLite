<?php
// test_stateless.php
// Simulace externího systému - NEZNÁ config.php!
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// URL tvého API (předpokládáme, že běží na stejném serveru pro účely testu, ale v reálu to bude plná adresa)
$apiUrl = 'http://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/api_stateless.php';

$response = null;
$error = null;
$httpCode = null;

// Pokud uživatel odeslal formulář, provedeme cURL požadavek
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim($_POST['api_key'] ?? '');
    $amount = $_POST['amount'] ?? '0.001';
    $description = $_POST['description'] ?? 'Testovací platba';
    $orderId = $_POST['order_id'] ?? 'ORD-' . rand(100, 999);
    $expiration = (int)($_POST['expiration_minutes'] ?? 15); // NOVÉ

    // Přidáno odesílání do API
    $payloadData = [
        'amount' => (float)$amount,
        'description' => $description,
        'order_id' => $orderId,
        'expiration_minutes' => $expiration // NOVÉ
    ];
  

    $payload = json_encode($payloadData);

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey // Klíč vložený z formuláře
    ]);

   $responseRaw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($responseRaw !== false) {
        $response = json_decode($responseRaw, true);
        if ($response === null) {
            // Pokud to není JSON (např. HTML výpis chyby z PHP), vypíšeme to surově
            $error = "Server nevrátil JSON. Surová odpověď: " . $responseRaw;
        }
    } else {
        $error = "cURL chyba sítě: " . $curlErr;
    }
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Test Stateless API</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    body { 
      margin: 0; color: #17201a; font-family: Inter, sans-serif; background-color: #fafcfa; 
      background-image: radial-gradient(circle at 50% 0%, rgba(47, 211, 90, 0.12) 0%, transparent 60%), linear-gradient(to right, rgba(229, 234, 231, 0.7) 1px, transparent 1px), linear-gradient(to bottom, rgba(229, 234, 231, 0.7) 1px, transparent 1px);
      background-size: 100% 100%, 24px 24px, 24px 24px; background-attachment: fixed; padding: 40px 20px; min-height: 100vh;
    }
    .container { max-width: 650px; margin: 0 auto; }
    .card { background: #fff; border: 1px solid #e5eae7; border-radius: 18px; padding: 30px; box-shadow: 0 8px 30px rgba(20,45,28,.06); margin-bottom: 24px; }
    h1 { font-size: 22px; margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px; }
    .field { margin-bottom: 18px; }
    label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 7px; color: #748078; text-transform: uppercase; }
    .input-wrap { display: flex; border: 1px solid #e5eae7; border-radius: 10px; overflow: hidden; background: #fff; transition: 0.2s; }
    .input-wrap:focus-within { box-shadow: inset 0 0 0 1px #2fd35a; border-color: #2fd35a; }
    input, select { width: 100%; border: 0; outline: 0; padding: 13px; font: inherit; background: transparent; }
    .primary { width: 100%; border: 0; background: #2fd35a; color: #fff; border-radius: 10px; padding: 13px; font-weight: 700; cursor: pointer; font-size: 14px; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .primary:hover { background: #20b948; }
    .result-box { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 12px; padding: 20px; margin-top: 20px; }
    .url-display { font-family: ui-monospace, monospace; font-size: 12px; background: #fff; padding: 10px; border: 1px dashed #2fd35a; border-radius: 8px; word-break: break-all; margin: 10px 0; color: #20b948; font-weight: 600; }
  </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1><i class="fa-solid fa-code" style="color: #2fd35a;"></i> Test externího klienta</h1>
        <p style="color: #748078; font-size: 13px; margin-bottom: 25px;">Tento formulář je 100% nezávislý a simuluje cizí systém. Nečte žádný config. Odesílá pouze to, co vyplníš níže, na endpoint <code style="background:#f0f4f1; padding:2px 6px; border-radius:4px;">api_stateless.php</code>.</p>

        <form method="POST">
            <div class="field">
                <label>Váš klientský API klíč</label>
                <div class="input-wrap">
                    <input type="text" name="api_key" placeholder="Např. MujVelmiTajnySifrovaciKlic_2026_Brno" value="<?php echo htmlspecialchars($_POST['api_key'] ?? ''); ?>" required>
                </div>
            </div>
            <div class="field">
                <label>Částka (BTC)</label>
                <div class="input-wrap"><input type="text" name="amount" value="0.001" required></div>
            </div>
            <div class="field">
                <label>Popis / Položka</label>
                <div class="input-wrap"><input type="text" name="description" value="VIP členství na webu" required></div>
            </div>
            <div class="field">
                <label>Interní ID objednávky</label>
                <div class="input-wrap"><input type="text" name="order_id" value="ORD-9988"></div>
            </div>
            <div class="field">
                <label>Platnost faktury (Expirace)</label>
                <div class="input-wrap">
                    <select name="expiration_minutes">
                        <option value="15">15 minut (Standard pro E-shopy)</option>
                        <option value="60">1 hodina</option>
                        <option value="1440">1 den (24 hodin)</option>
                        <option value="10080">1 týden (7 dní)</option>
                        <option value="43200">1 měsíc (30 dní)</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="primary"><i class="fa-solid fa-paper-plane"></i> Odeslat požadavek do API</button>
        </form>

        <?php if ($httpCode !== null): ?>
            <div class="result-box">
                <div style="font-weight: 700; margin-bottom: 10px; display: flex; justify-content: space-between;">
                    <span>Výsledek API volání:</span>
                    <span style="color: <?php echo $httpCode === 200 ? '#13aa3d' : '#ef4d4d'; ?>;">HTTP Kód: <?php echo $httpCode; ?></span>
                </div>

                <?php if ($response && isset($response['status']) && $response['status'] === 'success'): ?>
                    <div style="color: #13aa3d; font-weight: 600; margin-bottom: 8px;"><i class="fa-solid fa-circle-check"></i> Faktura úspěšně vytvořena!</div>
                    
                    <div style="font-size: 12px; color: #748078; padding: 10px; background: #fff; border-radius: 8px; border: 1px solid #e5eae7; margin-bottom: 10px;">
                        <strong>🔒 Ochrana serveru:</strong> Server ti automaticky přiřadil peněženku: <code><?php echo htmlspecialchars($response['data']['wallet']); ?></code>
                    </div>

                    <div style="font-size: 12px; color: #748078; margin-bottom: 4px;">Vygenerovaný odkaz pro zákazníka:</div>
                    <div class="url-display"><?php echo htmlspecialchars($response['data']['url']); ?></div>
                    
                    <div style="margin-top: 15px;">
                        <a href="<?php echo htmlspecialchars($response['data']['url']); ?>" target="_blank" class="primary" style="text-decoration:none; max-width: 220px;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Otevřít platební bránu</a>
                    </div>
                <?php else: ?>
                    <div style="color: #ef4d4d; font-weight: 600;">Chyba API:</div>
                    <pre style="background: #fff; padding: 10px; border-radius: 6px; font-size: 12px; overflow-x: auto;"><?php echo htmlspecialchars(json_encode($response ?? $error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>