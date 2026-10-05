<?php

declare(strict_types=1);

/** @var array<string, mixed> $checkout */
/** @var string $statusUrl */
/** @var string $assetBaseUrl */

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$isTerminal = in_array($checkout['status'], ['paid', 'expired'], true);
$statusLabels = [
    'unpaid' => 'Čeká na platbu',
    'underpaid' => 'Částečná platba',
    'pending_mempool' => 'Platba zachycena v síti',
    'paid' => 'Zaplaceno',
    'expired' => 'Platnost vypršela',
];
$statusLabel = $statusLabels[$checkout['status']] ?? 'Čeká na platbu';
$assetUrl = static fn (string $name): string => $assetBaseUrl . '/assets/' . $name . '?v='
    . substr(hash_file('sha256', __DIR__ . '/../../assets/' . $name), 0, 12);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="theme-color" content="#f5f8f3">
  <title><?= $escape($checkout['description']) ?> · Bitcoin platba</title>
  <link rel="stylesheet" href="<?= $escape($assetUrl('checkout.css')) ?>">
  <link rel="stylesheet" href="<?= $escape($assetUrl('stateless-checkout.css')) ?>">
  <script src="<?= $escape($assetUrl('stateless-checkout.js')) ?>" defer></script>
</head>
<body>
  <main class="invoice-shell">
    <article
      class="invoice-card"
      data-stateless-checkout
      data-status="<?= $escape($checkout['status']) ?>"
      data-terminal="<?= $isTerminal ? 'true' : 'false' ?>"
      data-seconds-remaining="<?= (int) $checkout['seconds_remaining'] ?>"
      data-status-url="<?= $escape($statusUrl) ?>"
    >
      <header class="checkout-header">
        <div class="checkout-brand"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 5h6a3.5 3.5 0 0 1 0 7H8m0 0h7a3.5 3.5 0 0 1 0 7H8M8 5v14M11 2v3m4-3v3M11 19v3m4-3v3"/></svg></span><span><strong>BTCPay Lite</strong><small>Platba bitcoinem</small></span></div>
        <div class="secure-pill"><span class="secure-dot" aria-hidden="true"></span>Bitcoin · on-chain</div>
      </header>
      <header class="invoice-card__header">
        <div>
          <p class="invoice-kicker">Platební požadavek</p>
          <h1 class="invoice-title"><?= $escape($checkout['description']) ?></h1>
          <p class="invoice-order">
            <?= $checkout['order_id'] !== ''
              ? 'Objednávka ' . $escape($checkout['order_id'])
              : 'Platba v bitcoinové síti' ?>
          </p>
        </div>
        <span class="status-pill" data-status-pill data-status="<?= $escape($checkout['status']) ?>">
          <?= $escape($statusLabel) ?>
        </span>
      </header>

      <?php if ($checkout['status'] !== 'paid'): ?>
        <p class="verification-notice"><?= $escape(\BtcPayLite\PaymentCheckPolicy::customerNotice()) ?></p>
      <?php endif; ?>
      <div class="amount-section">
        <span>Částka k úhradě</span>
        <div><strong><?= $escape($checkout['amount']) ?></strong> <small>BTC</small></div>
        <button class="text-button" type="button" data-copy-value="<?= $escape($checkout['amount']) ?>">Kopírovat částku</button>
      </div>

      <div class="invoice-card__body">
        <section class="invoice-qr-panel" aria-label="Bitcoin QR platba">
          <?php if (is_string($checkout['qr_code_data_uri']) && $checkout['qr_code_data_uri'] !== ''): ?>
            <div class="qr-frame">
              <img src="<?= $escape($checkout['qr_code_data_uri']) ?>" alt="QR kód s Bitcoin BIP21 platebním požadavkem" width="226" height="226">
            </div>
          <?php else: ?>
            <div class="qr-fallback">QR kód není na tomto serveru dostupný. Platbu otevřete tlačítkem níže.</div>
          <?php endif; ?>
          <?php if ($isTerminal): ?>
            <span class="wallet-link" aria-disabled="true"><?= $checkout['status'] === 'paid' ? 'Platba dokončena' : 'Faktura vypršela' ?></span>
          <?php else: ?>
            <a class="wallet-link" href="<?= $escape($checkout['bip21_uri']) ?>">Otevřít Bitcoin peněženku</a>
          <?php endif; ?>
        </section>

        <section class="invoice-detail-panel">
          <div class="expiry-section"><div><span>Uhraďte do</span><time data-invoice-deadline datetime="<?= $escape(gmdate('c', (int) $checkout['expires_at'])) ?>"><?= $escape(date('d. m. Y H:i T', (int) $checkout['expires_at'])) ?></time></div>
          <div class="invoice-timer" data-invoice-timer>
            <?= $checkout['status'] === 'paid'
              ? 'Platba byla úspěšně přijata.'
              : ($checkout['status'] === 'expired'
                ? 'Čas pro úhradu vypršel.'
                : 'Zbývá ' . (int) $checkout['seconds_remaining'] . ' sekund') ?>
          </div></div>

          <dl class="invoice-details">
            <div class="detail-row">
              <div>
                <dt class="detail-label">Bitcoin adresa</dt>
                <dd class="detail-value"><?= $escape($checkout['address']) ?></dd>
              </div>
              <button class="copy-button" type="button" data-copy-value="<?= $escape($checkout['address']) ?>">Kopírovat</button>
            </div>
          </dl>

          <div class="payment-progress" aria-live="polite">
            <div class="payment-progress__row">
              <span>Přijato</span>
              <strong data-received-amount><?= $escape($checkout['received_amount']) ?> BTC</strong>
            </div>
            <div class="payment-progress__row">
              <span>Zbývá</span>
              <strong data-missing-amount><?= $escape($checkout['missing_amount']) ?> BTC</strong>
            </div>
          </div>
        </section>
      </div>

      <footer class="invoice-footer">
        <span>Odesílejte pouze BTC v bitcoinové síti.</span>
        <span>Částka v BTC zůstává po dobu platnosti stejná.</span>
      </footer>
    </article>
  </main>
</body>
</html>
