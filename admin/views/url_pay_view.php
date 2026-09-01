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
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title><?= $escape($checkout['description']) ?> · Bitcoin platba</title>
  <link rel="stylesheet" href="<?= $escape($assetBaseUrl) ?>/assets/stateless-checkout.css">
  <script src="<?= $escape($assetBaseUrl) ?>/assets/stateless-checkout.js" defer></script>
</head>
<body>
  <main class="invoice-shell">
    <header class="invoice-brand">
      <span class="invoice-brand__name"><span class="invoice-brand__mark">₿</span> BTCPayLite</span>
      <span>Podepsaná stateless faktura</span>
    </header>

    <article
      class="invoice-card"
      data-stateless-checkout
      data-status="<?= $escape($checkout['status']) ?>"
      data-terminal="<?= $isTerminal ? 'true' : 'false' ?>"
      data-seconds-remaining="<?= (int) $checkout['seconds_remaining'] ?>"
      data-status-url="<?= $escape($statusUrl) ?>"
    >
      <header class="invoice-card__header">
        <div>
          <p class="invoice-kicker">Bitcoin invoice</p>
          <h1 class="invoice-title"><?= $escape($checkout['description']) ?></h1>
          <p class="invoice-order">
            <?= $checkout['order_id'] !== ''
              ? 'Objednávka ' . $escape($checkout['order_id'])
              : 'Bez databázového záznamu faktury' ?>
          </p>
        </div>
        <span class="status-pill" data-status-pill data-status="<?= $escape($checkout['status']) ?>">
          Načítání stavu
        </span>
      </header>

      <div class="invoice-card__body">
        <section class="invoice-qr-panel" aria-label="Bitcoin QR platba">
          <?php if (is_string($checkout['qr_code_data_uri']) && $checkout['qr_code_data_uri'] !== ''): ?>
            <div class="qr-frame">
              <img src="<?= $escape($checkout['qr_code_data_uri']) ?>" alt="QR kód s Bitcoin BIP21 platebním požadavkem" width="226" height="226">
            </div>
          <?php else: ?>
            <div class="qr-fallback">QR kód není na tomto serveru dostupný. Platbu otevřete tlačítkem níže.</div>
          <?php endif; ?>
          <a class="wallet-link" href="<?= $escape($checkout['bip21_uri']) ?>">Otevřít Bitcoin peněženku</a>
        </section>

        <section class="invoice-detail-panel">
          <span class="amount-label">Částka k úhradě</span>
          <div class="invoice-amount"><?= $escape($checkout['amount']) ?> <span class="invoice-unit">BTC</span></div>
          <div class="invoice-timer" data-invoice-timer aria-live="polite"></div>

          <dl class="invoice-details">
            <div class="detail-row">
              <div>
                <dt class="detail-label">Bitcoin adresa</dt>
                <dd class="detail-value"><?= $escape($checkout['address']) ?></dd>
              </div>
              <button class="copy-button" type="button" data-copy-value="<?= $escape($checkout['address']) ?>">Kopírovat</button>
            </div>
            <div class="detail-row">
              <div>
                <dt class="detail-label">Přesná částka</dt>
                <dd class="detail-value"><?= $escape($checkout['amount']) ?> BTC</dd>
              </div>
              <button class="copy-button" type="button" data-copy-value="<?= $escape($checkout['amount']) ?>">Kopírovat</button>
            </div>
            <div class="detail-row">
              <div>
                <dt class="detail-label">Platnost do</dt>
                <dd class="detail-value">
                  <time datetime="<?= $escape(gmdate('c', (int) $checkout['expires_at'])) ?>">
                    <?= $escape(date('d.m.Y H:i', (int) $checkout['expires_at'])) ?>
                  </time>
                </dd>
              </div>
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
        <span>Částka a adresa jsou chráněné HMAC podpisem.</span>
        <span>Faktura se neukládá do databáze.</span>
      </footer>
    </article>
  </main>
</body>
</html>
