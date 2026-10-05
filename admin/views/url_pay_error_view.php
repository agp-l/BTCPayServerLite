<?php

declare(strict_types=1);

/** @var string $assetBaseUrl */
$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title>Faktura není dostupná</title>
  <link rel="stylesheet" href="<?= $escape($assetBaseUrl) ?>/assets/checkout.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/checkout.css'), 0, 12) ?>">
  <link rel="stylesheet" href="<?= $escape($assetBaseUrl) ?>/assets/stateless-checkout.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/stateless-checkout.css'), 0, 12) ?>">
</head>
<body>
  <main class="invoice-error" role="alert">
    <span class="invoice-error__code">Neplatná faktura</span>
    <h1>Platební odkaz nelze otevřít</h1>
    <p>Odkaz je neúplný, změněný nebo už není platný. Vyžádejte si prosím nový platební odkaz od vystavitele.</p>
  </main>
</body>
</html>
