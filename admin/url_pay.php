<?php
// admin/url_pay.php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 1. Načtení závislostí o složku výše
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\BtcInvoiceManager;
use BtcPayLite\BtcDashboard;

$token = $_GET['inv'] ?? '';

try {
    // 2. Inicializace motoru
    $rpc = new ElectrumRPC($config['rpc_host'], $config['rpc_port'], $config['rpc_user'], $config['rpc_pass']);
    $wallet = new ElectrumWallet($rpc);
    $wallet->loadWallet($config['wallet_path']);

    // Bezstavový manažer faktur (null místo databáze)
    $invoiceManager = new BtcInvoiceManager($wallet, $config['secret_key'], null);
    
    // Dashboard pro načtení fiat kurzů
    $dashboard = new BtcDashboard($wallet, dirname($config['wallet_path']));

    // 3. AJAX Endpoint pro živou kontrolu (volá JavaScript na pozadí)
    if (isset($_GET['action']) && $_GET['action'] === 'check') {
        header('Content-Type: application/json');
        echo json_encode($invoiceManager->checkStatelessPaymentStatus($token));
        exit;
    }

    // 4. Počáteční načtení dat z URL tokenu
    $statusData = $invoiceManager->checkStatelessPaymentStatus($token);
    $invoice = $statusData['invoice'];
    $currentStatus = $statusData['status']; 
    $secondsRemaining = $statusData['seconds_remaining'] ?? 0;
    $bip21Uri = $statusData['bip21_uri'] ?? "bitcoin:{$invoice['a']}?amount={$invoice['v']}";

    // Přepočet na CZK pro lepší orientaci zákazníka
    $fiatRate = $dashboard->getFiatPrice('CZK');
    $fiatAmount = $fiatRate > 0 ? number_format((float)$invoice['v'] * $fiatRate, 2, ',', ' ') : '0,00';

} catch (Exception $e) {
    die("<div style='text-align:center; padding:50px; color:#ef4d4d; font-family:sans-serif;'><h1>Chyba faktury</h1><p>" . htmlspecialchars($e->getMessage()) . "</p></div>");
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
    
    /* Věrná kopie moderního pozadí (šedá mřížka + zelená zář) */
    body { 
      margin: 0; 
      color: #17201a; 
      font-family: Inter, sans-serif; 
      background-color: #fafcfa; 
      background-image: 
        radial-gradient(circle at 50% 0%, rgba(47, 211, 90, 0.12) 0%, transparent 60%),
        linear-gradient(to right, rgba(229, 234, 231, 0.7) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(229, 234, 231, 0.7) 1px, transparent 1px);
      background-size: 100% 100%, 24px 24px, 24px 24px;
      background-attachment: fixed;
      display: grid; 
      place-items: center; 
      min-height: 100vh; 
      padding: 20px;
    }

    .pay-card { background: #fff; border: 1px solid #e5eae7; border-radius: 20px; width: min(440px, 100%); padding: 35px 30px; box-shadow: 0 10px 40px rgba(20,45,28,.08); text-align: center; position: relative; z-index: 1; }
    h2 { margin: 0 0 5px; font-size: 20px; color: #17201a; }
    .desc { color: #748078; font-size: 13px; margin-bottom: 25px; }
    
    .interactive-area { transition: 0.3s opacity; }
    .interactive-area.disabled { opacity: 0.2; pointer-events: none; filter: grayscale(1); }
    
    .qr-box { background: #fff; border: 1px solid #e5eae7; padding: 12px; border-radius: 14px; display: inline-block; margin-bottom: 20px; }
    .address-box { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 10px; padding: 12px; font-family: ui-monospace, monospace; font-size: 12px; word-break: break-all; margin-bottom: 20px; text-align: left; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    
    .copy-btn { background: none; border: 0; cursor: pointer; color: #748078; font-size: 16px; padding: 5px; transition: 0.2s; }
    .copy-btn:hover { color: #2fd35a; }
    
    .amount-box { font-size: 32px; font-weight: 800; color: #17201a; margin-bottom: 2px; letter-spacing: -1px; }
    .fiat-amount { font-size: 14px; color: #748078; font-weight: 600; margin-bottom: 10px; }
    
    .timer { font-size: 13px; color: #748078; margin-bottom: 25px; font-weight: 600; font-variant-numeric: tabular-nums; }
    
    .status-badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 20px; font-weight: 700; font-size: 13px; margin-bottom: 20px; }
    .status-unpaid { background: #fef3c7; color: #d97706; }
    .status-pending { background: #e0f2fe; color: #0284c7; }
    .status-paid { background: #eafbef; color: #13aa3d; }
    .status-expired { background: #fee2e2; color: #ef4d4d; }
    
    .paid-overlay { display: none; padding: 20px 0; }
    .paid-overlay.active { display: block; animation: popIn 0.5s ease forwards; }
    .paid-overlay i { font-size: 60px; color: #20b948; margin-bottom: 15px; }
    
    @keyframes popIn { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
  </style>
</head>
<body>
<div class="pay-card" id="payCard">
    <div id="badgeContainer">
        <?php if ($currentStatus === 'paid'): ?>
            <div class="status-badge status-paid"><i class="fa-solid fa-circle-check"></i> Zaplaceno a potvrzeno</div>
        <?php elseif ($currentStatus === 'pending_mempool'): ?>
            <div class="status-badge status-pending"><i class="fa-solid fa-spinner fa-spin"></i> Čeká na potvrzení sítě</div>
        <?php elseif ($currentStatus === 'expired'): ?>
            <div class="status-badge status-expired"><i class="fa-solid fa-triangle-exclamation"></i> Faktura vypršela</div>
        <?php else: ?>
            <div class="status-badge status-unpaid"><i class="fa-solid fa-clock"></i> Čeká se na platbu</div>
        <?php endif; ?>
    </div>

    <h2><?php echo htmlspecialchars($invoice['d'] ?? 'Faktura k úhradě'); ?></h2>
    <div class="desc">
        <?php echo isset($invoice['p']['order_id']) && $invoice['p']['order_id'] !== '' ? 'ID Objednávky: ' . htmlspecialchars($invoice['p']['order_id']) : 'Platba objednávky'; ?>
    </div>

    <div class="amount-box"><?php echo htmlspecialchars(number_format((float)$invoice['v'], 8, '.', '')); ?> BTC</div>
    <div class="fiat-amount">~ <?php echo htmlspecialchars($fiatAmount); ?> CZK</div>
    
    <div class="timer" id="timer">
        <?php echo $currentStatus === 'expired' ? 'Čas vypršel' : 'Načítání času...'; ?>
    </div>

    <div id="interactiveArea" class="interactive-area <?php echo $currentStatus === 'expired' ? 'disabled' : ''; ?>" style="<?php echo $currentStatus === 'paid' ? 'display:none;' : ''; ?>">
        <div class="qr-box">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?php echo urlencode($bip21Uri); ?>" alt="QR" style="width:180px;height:180px;display:block;">
        </div>
        <div class="address-box">
            <span id="btcAddr"><?php echo htmlspecialchars($invoice['a']); ?></span>
            <button class="copy-btn" id="copyBtn"><i class="fa-regular fa-copy"></i></button>
        </div>
    </div>

    <div id="successScreen" class="paid-overlay <?php echo $currentStatus === 'paid' ? 'active' : ''; ?>">
        <i class="fa-solid fa-circle-check"></i>
        <h3 style="margin:0 0 10px 0; color:#17201a;">Děkujeme za platbu!</h3>
        <p style="color:#748078; font-size:13px; margin:0;">Platba byla úspěšně přijata.</p>
    </div>
</div>
<script>
  let secondsRemaining = <?php echo (int)$secondsRemaining; ?>;
  const token = "<?php echo htmlspecialchars($token); ?>";
  const timerEl = document.getElementById('timer');
  const interactiveArea = document.getElementById('interactiveArea');
  const badgeContainer = document.getElementById('badgeContainer');
  const successScreen = document.getElementById('successScreen');
  let currentStatus = "<?php echo $currentStatus; ?>";

  // Kopírování adresy
  document.getElementById('copyBtn').addEventListener('click', async () => {
      const addr = document.getElementById('btcAddr').innerText;
      try { await navigator.clipboard.writeText(addr); } catch (err) {}
      document.getElementById('copyBtn').innerHTML = '<i class="fa-solid fa-check" style="color:#20b948;"></i>';
      setTimeout(() => document.getElementById('copyBtn').innerHTML = '<i class="fa-regular fa-copy"></i>', 2000);
  });

  // Odpočet (pokud není zaplaceno nebo expirováno)
  if (currentStatus === 'unpaid' || currentStatus === 'pending_mempool') {
      const countdown = setInterval(() => {
          if (secondsRemaining > 0) {
              secondsRemaining--;
              
              // VÝPOČET TÝDNŮ, DNŮ, HODIN, MINUT A SEKUND
              let w = Math.floor(secondsRemaining / 604800);
              let d = Math.floor((secondsRemaining % 604800) / 86400);
              let h = Math.floor((secondsRemaining % 86400) / 3600);
              let m = Math.floor((secondsRemaining % 3600) / 60);
              let s = secondsRemaining % 60;
              
              // Správné české skloňování
              let wText = w === 1 ? 'týden' : (w >= 2 && w <= 4 ? 'týdny' : 'týdnů');
              let dText = d === 1 ? 'den' : (d >= 2 && d <= 4 ? 'dny' : 'dní');
              
              let timeString = '';
              
              // Sestavení finálního textu
              if (w > 0) timeString += `${w} ${wText} `;
              if (d > 0) timeString += `${d} ${dText} `;
              
              if (h > 0 || d > 0 || w > 0) {
                  timeString += `${h}:${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
              } else {
                  timeString += `${m}:${s < 10 ? '0' : ''}${s}`;
              }
              
              timerEl.innerHTML = `<i class="fa-regular fa-clock"></i> Zbývající čas: ${timeString}`;
          } else {
              clearInterval(countdown);
              timerEl.textContent = "Čas pro platbu vypršel";
              interactiveArea.classList.add('disabled');
              badgeContainer.innerHTML = `<div class="status-badge status-expired"><i class="fa-solid fa-triangle-exclamation"></i> Faktura vypršela</div>`;
              currentStatus = 'expired';
          }
      }, 1000);
  }

  // Živá AJAX kontrola platby z blockchainu
  if (currentStatus !== 'paid' && currentStatus !== 'expired') {
      const pollInterval = setInterval(async () => {
          if (currentStatus === 'expired') return clearInterval(pollInterval);
          try {
              let res = await fetch(`url_pay.php?inv=${token}&action=check`);
              let data = await res.json();
              
              if (data.status === 'paid') {
                  clearInterval(pollInterval);
                  badgeContainer.innerHTML = `<div class="status-badge status-paid"><i class="fa-solid fa-circle-check"></i> Zaplaceno a potvrzeno</div>`;
                  interactiveArea.style.display = 'none';
                  successScreen.classList.add('active');
                  timerEl.style.display = 'none';
                  currentStatus = 'paid';
              } else if (data.status === 'pending_mempool' && currentStatus !== 'pending_mempool') {
                  badgeContainer.innerHTML = `<div class="status-badge status-pending"><i class="fa-solid fa-spinner fa-spin"></i> Platba detekována v síti</div>`;
                  currentStatus = 'pending_mempool';
              }
          } catch (e) {
              console.error("Nelze navázat spojení se serverem pro ověření.");
          }
      }, 5000);
  }
</script>
</body>
</html>