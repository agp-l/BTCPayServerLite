<?php

declare(strict_types=1);

/** @var array<string,mixed> $checkout */
/** @var string $statusUrl */
/** @var string $stylesheetUrl */
/** @var string $scriptUrl */
/** @var string|null $qrCodeDataUri */

$statusLabels = [
    'New' => ['Čekáme na platbu', 'new'],
    'Processing' => ['Platba přijata, čekáme na potvrzení', 'processing'],
    'Settled' => ['Platba potvrzena', 'settled'],
    'Expired' => ['Platnost faktury vypršela', 'expired'],
];
[$statusLabel, $statusTone] = $statusLabels[$checkout['status']];
$isSettled = $checkout['status'] === 'Settled';
$isExpired = $checkout['status'] === 'Expired';
$isPartial = $checkout['additional_status'] === 'PaidPartial';
$hasQrCode = is_string($qrCodeDataUri) && str_starts_with($qrCodeDataUri, 'data:image/');
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= htmlspecialchars((string) $checkout['title'], ENT_QUOTES, 'UTF-8') ?> · Bitcoin platba</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($stylesheetUrl, ENT_QUOTES, 'UTF-8') ?>">
    <script src="<?= htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8') ?>" defer></script>
</head>
<body>
<main id="checkout-app"
      class="checkout-shell"
      data-status-url="<?= htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') ?>"
      data-initial-status="<?= htmlspecialchars((string) $checkout['status'], ENT_QUOTES, 'UTF-8') ?>"
      data-seconds-remaining="<?= htmlspecialchars((string) $checkout['seconds_remaining'], ENT_QUOTES, 'UTF-8') ?>">
    <section class="checkout-card" aria-labelledby="checkout-title">
        <header class="checkout-header">
            <div class="checkout-brand">
                <span class="brand-mark" aria-hidden="true">₿</span>
                <span>
                    <strong>BTCPay Lite</strong>
                    <small>Bitcoin checkout</small>
                </span>
            </div>
            <div class="secure-pill">
                <span class="secure-dot" aria-hidden="true"></span>
                Lokální a soukromé
            </div>
        </header>

        <div class="checkout-summary">
            <p class="eyebrow">Platební požadavek</p>
            <h1 id="checkout-title"><?= htmlspecialchars((string) $checkout['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="invoice-id">ID <?= htmlspecialchars((string) $checkout['id'], ENT_QUOTES, 'UTF-8') ?></p>

            <div id="status-badge"
                 class="status-badge status-<?= htmlspecialchars($statusTone, ENT_QUOTES, 'UTF-8') ?>"
                 role="status"
                 aria-live="polite">
                <span class="status-dot" aria-hidden="true"></span>
                <span id="status-label"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <section id="success-panel" class="success-panel<?= $isSettled ? ' is-visible' : '' ?>" aria-live="polite">
            <span class="success-symbol" aria-hidden="true">✓</span>
            <div>
                <p class="eyebrow">Hotovo</p>
                <h2>Děkujeme, platba je potvrzena</h2>
                <p>Faktura byla bezpečně uhrazena v bitcoinové síti.</p>
            </div>
        </section>

        <section id="payment-panel" class="payment-panel<?= $isSettled ? ' is-hidden' : '' ?>">
            <div class="payment-grid">
                <div class="qr-column">
                    <p class="section-label">Naskenujte v peněžence</p>
                    <?php if ($hasQrCode): ?>
                        <a id="qr-payment-link"
                           class="qr-payment-link<?= $isExpired ? ' is-disabled' : '' ?>"
                           href="<?= $isExpired ? '#' : htmlspecialchars((string) $checkout['bip21_uri'], ENT_QUOTES, 'UTF-8') ?>"
                           <?= $isExpired ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                           aria-label="Otevřít bitcoinovou platbu v peněžence">
                            <span class="qr-frame">
                                <img src="<?= htmlspecialchars((string) $qrCodeDataUri, ENT_QUOTES, 'UTF-8') ?>"
                                     width="248"
                                     height="248"
                                     alt="QR kód bitcoinové platby">
                                <span class="qr-center" aria-hidden="true">₿</span>
                            </span>
                        </a>
                    <?php else: ?>
                        <div class="qr-placeholder">
                            <span aria-hidden="true">₿</span>
                            <strong>QR kód není dostupný</strong>
                            <small>Použijte tlačítko pro otevření peněženky.</small>
                        </div>
                    <?php endif; ?>
                    <p class="qr-help">QR obsahuje adresu i přesnou částku.</p>
                </div>

                <div class="payment-details">
                    <div class="amount-section">
                        <span>Částka k úhradě</span>
                        <strong id="payment-amount"><?= htmlspecialchars((string) $checkout['amount'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <small>BTC</small>
                        <button class="text-button" type="button" data-copy-value="<?= htmlspecialchars((string) $checkout['amount'], ENT_QUOTES, 'UTF-8') ?>">
                            Kopírovat částku
                        </button>
                    </div>

                    <p id="checkout-timer" class="checkout-timer" aria-live="polite">
                        <?= $isExpired ? 'Čas pro platbu vypršel.' : 'Načítáme zbývající čas…' ?>
                    </p>

                    <div id="partial-notice" class="notice notice-warning<?= $isPartial ? ' is-visible' : '' ?>" role="alert">
                        Dorazila pouze část platby. Zbývá doplatit
                        <strong id="missing-amount"><?= htmlspecialchars((string) $checkout['missing_amount'], ENT_QUOTES, 'UTF-8') ?> BTC</strong>.
                    </div>

                    <div class="address-section">
                        <div class="section-heading">
                            <span>Bitcoinová adresa</span>
                            <button class="text-button" type="button" data-copy-target="payment-address">Kopírovat</button>
                        </div>
                        <code id="payment-address"><?= htmlspecialchars((string) $checkout['address'], ENT_QUOTES, 'UTF-8') ?></code>
                    </div>

                    <a id="wallet-link"
                       class="wallet-button<?= $isExpired ? ' is-disabled' : '' ?>"
                       href="<?= $isExpired ? '#' : htmlspecialchars((string) $checkout['bip21_uri'], ENT_QUOTES, 'UTF-8') ?>"
                       <?= $isExpired ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                        <span aria-hidden="true">₿</span>
                        Otevřít Bitcoin peněženku
                    </a>
                </div>
            </div>
        </section>

        <footer class="checkout-footer">
            <div class="checkout-steps" aria-label="Postup platby">
                <span><strong>1</strong> Naskenujte QR</span>
                <span><strong>2</strong> Odešlete přesnou částku</span>
                <span><strong>3</strong> Vyčkejte na potvrzení</span>
            </div>
            <p>Odesílejte pouze BTC v bitcoinové síti. Stav se obnovuje automaticky.</p>
            <div class="privacy-note">
                <span aria-hidden="true">◆</span>
                QR kód vzniká přímo na serveru. Platební údaje neopouštějí aplikaci.
            </div>
        </footer>

        <noscript>
            <p class="notice notice-static">
                Pro automatickou kontrolu platby povolte JavaScript nebo stránku ručně obnovte.
            </p>
        </noscript>
    </section>
</main>
<div id="copy-toast" class="copy-toast" role="status" aria-live="polite">Zkopírováno</div>
</body>
</html>
