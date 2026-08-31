<?php

declare(strict_types=1);

/** @var int $checkoutErrorStatus */
/** @var string $checkoutErrorMessage */
/** @var string $stylesheetUrl */
/** @var string $homeUrl */
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Platbu nelze zobrazit</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($stylesheetUrl, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<main class="checkout-shell">
    <section class="checkout-card error-card" aria-labelledby="error-title">
        <span class="error-code"><?= htmlspecialchars((string) $checkoutErrorStatus, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="error-symbol" aria-hidden="true">!</span>
        <p class="eyebrow">Bitcoin checkout</p>
        <h1 id="error-title">Platbu nelze zobrazit</h1>
        <p class="error-message"><?= htmlspecialchars($checkoutErrorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($checkoutErrorStatus >= 500): ?>
            <p class="error-help">Služba může být dočasně nedostupná. Zkuste stránku za chvíli obnovit.</p>
        <?php endif; ?>
        <a class="secondary-button" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">
            Zpět na hlavní stránku
        </a>
    </section>
</main>
</body>
</html>
