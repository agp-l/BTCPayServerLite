<?php
// checkout/pay.php
declare(strict_types=1);
ini_set('display_errors', '1'); // Zapnuto pro vývoj
error_reporting(E_ALL);

// Cesty o složku výš do kořene
require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

// 2. Import moderních tříd
use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcInvoiceManager;
use Exception;

$invoiceId = $_GET['id'] ?? '';

try {
    $db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
    
    // Zjištění obchodu a cesty k peněžence
    $stmt = $db->getPdo()->prepare("
        SELECT i.store_id, s.wallet_path 
        FROM invoices i 
        JOIN stores s ON i.store_id = s.id 
        WHERE i.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch();
    
    if (!$row) {
        die("<div style='text-align:center; padding:50px; color:#ef4d4d; font-family:sans-serif;'><h1>Chyba faktury</h1><p>Faktura nebyla nalezena.</p></div>");
    }

    // Inicializace motoru pro správnou peněženku
    $rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
    $wallet = new ElectrumWallet($rpc);
    $wallet->loadWallet($row['wallet_path']);
    $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], $db);

    // AJAX Endpoint pro živou kontrolu (volá se z JS)
    if (isset($_GET['action']) && $_GET['action'] === 'check') {
        header('Content-Type: application/json');
        echo json_encode($invoiceManager->checkDatabasePaymentStatus($invoiceId));
        exit;
    }

    // Počáteční vykreslení
    $statusData = $invoiceManager->checkDatabasePaymentStatus($invoiceId);
    $invoice = $statusData['invoice'];
    $currentStatus = $statusData['status']; // 'New', 'Processing', 'Settled', 'Expired'
    $secondsRemaining = max(0, $invoice['expires_at'] - time());
    if ($currentStatus === 'Expired') $secondsRemaining = 0;

} catch (Exception $e) {
    die("<div style='text-align:center; padding:50px; color:#ef4d4d; font-family:sans-serif;'><h1>Chyba</h1><p>" . htmlspecialchars($e->getMessage()) . "</p></div>");
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Platba faktury</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; color: #17201a; font-family: Inter, system-ui, sans-serif; background: #f0f4f1; display: grid; place-items: center; min-height: 100vh; padding: 20px; }
    .pay-card { background: #fff; border: 1px solid #e5eae7; border-radius: 20px; width: min(440px, 100%); padding: 35px 30px; box-shadow: 0 10px 40px rgba(20,45,28,.08); text-align: center; }
    h2 { margin: 0 0 5px; font-size: 20px; }
    .desc { color: #748078; font-size: 13px; margin-bottom: 25px; }
    .interactive-area { transition: 0.3s opacity; }
    .interactive-area.disabled { opacity: 0.2; pointer-events: none; filter: grayscale(1); }
    .qr-box { background: #fff; border: 1px solid #e5eae7; padding: 12px; border-radius: 14px; display: inline-block; margin-bottom: 20px; }
    .address-box { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 10px; padding: 12px; font-family: ui-monospace, monospace; font-size: 12px; word-break: break-all; margin-bottom: 20px; text-align: left; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .copy-btn { background: none; border: 0; cursor: pointer; color: #748078; font-size: 16px; padding: 5px; transition: 0.2s; }
    .copy-btn:hover { color: #2fd35a; }
    .amount-box { font-size: 32px; font-weight: 800; color: #17201a; margin-bottom: 5px; letter-spacing: -1px; }
    .timer { font-size: 13px; color: #748078; margin-bottom: 25px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .status-badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 20px; font-weight: 700; font-size: 13px; margin-bottom: 20px; }
    .status-new { background: #fef3c7; color: #d97706; }
    .status-processing { background: #e0f2fe; color: #0284c7; }
    .status-settled { background: #eafbef; color: #13aa3d; }
    .status-expired { background: #fee2e2; color: #ef4d4d; }
    .btn { background: #2fd35a; color: #fff; border: 0; border-radius: 10px; padding: 14px; font-weight: 700; width: 100%; cursor: pointer; text-decoration: none; display: block; font-size: 15px; transition: 0.2s; }
    .btn:hover { background: #20b948; }
    .paid-overlay { display: none; padding: 20px 0; }
    .paid-overlay.active { display: block; animation: popIn 0.5s ease forwards; }
    .paid-overlay i { font-size: 60px; color: #20b948; margin-bottom: 15px; }
    @keyframes popIn { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
  </style>
</head>
<body>
<div class="pay-card" id="payCard">
    <div id="badgeContainer">
        <?php if ($currentStatus === 'Settled'): ?>
            <div class="status-badge status-settled"><i class="fa-solid fa-circle-check"></i> Zaplaceno a potvrzeno</div>
        <?php elseif ($currentStatus === 'Processing'): ?>
            <div class="status-badge status-processing"><i class="fa-solid fa-spinner fa-spin"></i> Čeká na potvrzení sítě</div>
        <?php elseif ($currentStatus === 'Expired'): ?>
            <div class="status-badge status-expired"><i class="fa-solid fa-triangle-exclamation"></i> Faktura vypršela</div>
        <?php else: ?>
            <div class="status-badge status-new"><i class="fa-solid fa-clock"></i> Čeká se na platbu</div>
        <?php endif; ?>
    </div>

    <?php 
    $desc = "Faktura k úhradě";
    if (isset($invoice['metadata']['orderId'])) {
        $desc = "Objednávka " . $invoice['metadata']['orderId'];
    }
    ?>
    <h2><?php echo htmlspecialchars($desc); ?></h2>
    <div class="desc">ID: <?php echo htmlspecialchars($invoice['id']); ?></div>

    <div class="amount-box"><?php echo htmlspecialchars(number_format((float)$invoice['amount'], 8, '.', '')); ?> BTC</div>
    <div class="timer" id="timer">
        <?php echo $currentStatus === 'Expired' ? 'Čas vypršel' : 'Načítání...'; ?>
    </div>

    <div id="interactiveArea" class="interactive-area <?php echo $currentStatus === 'Expired' ? 'disabled' : ''; ?>" style="<?php echo $currentStatus === 'Settled' ? 'display:none;' : ''; ?>">
        <div class="qr-box">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?php echo urlencode($invoice['bip21_uri']); ?>" alt="QR" style="width:180px;height:180px;display:block;">
        </div>
        <div class="address-box">
            <span id="btcAddr"><?php echo htmlspecialchars($invoice['btc_address']); ?></span>
            <button class="copy-btn" id="copyBtn"><i class="fa-regular fa-copy"></i></button>
        </div>
    </div>

    <div id="successScreen" class="paid-overlay <?php echo $currentStatus === 'Settled' ? 'active' : ''; ?>">
        <i class="fa-solid fa-circle-check"></i>
        <h3 style="margin:0 0 20px 0; color:#17201a;">Děkujeme za platbu!</h3>
    </div>
</div>

<script>
  let secondsRemaining = <?php echo $secondsRemaining; ?>;
  const invoiceId = "<?php echo htmlspecialchars($invoiceId); ?>";
  const timerEl = document.getElementById('timer');
  const interactiveArea = document.getElementById('interactiveArea');
  const badgeContainer = document.getElementById('badgeContainer');
  const successScreen = document.getElementById('successScreen');
  let currentStatus = "<?php echo $currentStatus; ?>";

  document.getElementById('copyBtn').addEventListener('click', async () => {
      const addr = document.getElementById('btcAddr').innerText;
      try { await navigator.clipboard.writeText(addr); } catch (err) {}
      document.getElementById('copyBtn').innerHTML = '<i class="fa-solid fa-check" style="color:#20b948;"></i>';
      setTimeout(() => document.getElementById('copyBtn').innerHTML = '<i class="fa-regular fa-copy"></i>', 2000);
  });

  if (currentStatus === 'New' || currentStatus === 'Processing') {
      const countdown = setInterval(() => {
          if (secondsRemaining > 0) {
              secondsRemaining--;
              let m = Math.floor(secondsRemaining / 60);
              let s = secondsRemaining % 60;
              timerEl.innerHTML = `<i class="fa-regular fa-clock"></i> Zbývající čas: ${m}:${s < 10 ? '0' : ''}${s}`;
          } else {
              clearInterval(countdown);
              timerEl.textContent = "Čas pro platbu vypršel";
              interactiveArea.classList.add('disabled');
              badgeContainer.innerHTML = `<div class="status-badge status-expired"><i class="fa-solid fa-triangle-exclamation"></i> Faktura vypršela</div>`;
              currentStatus = 'Expired';
          }
      }, 1000);
  }

  if (currentStatus !== 'Settled' && currentStatus !== 'Expired') {
      const pollInterval = setInterval(async () => {
          if (currentStatus === 'Expired') return clearInterval(pollInterval);
          try {
              let res = await fetch(`pay.php?id=${invoiceId}&action=check`);
              let data = await res.json();
              if (data.status === 'Settled') {
                  clearInterval(pollInterval);
                  badgeContainer.innerHTML = `<div class="status-badge status-settled"><i class="fa-solid fa-circle-check"></i> Zaplaceno a potvrzeno</div>`;
                  interactiveArea.style.display = 'none';
                  successScreen.classList.add('active');
                  timerEl.style.display = 'none';
                  currentStatus = 'Settled';
              } else if (data.status === 'Processing' && currentStatus !== 'Processing') {
                  badgeContainer.innerHTML = `<div class="status-badge status-processing"><i class="fa-solid fa-spinner fa-spin"></i> Platba v síti</div>`;
                  currentStatus = 'Processing';
              }
          } catch (e) {}
      }, 5000);
  }
</script>
</body>
</html>