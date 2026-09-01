<?php

declare(strict_types=1);

$loginUrl = htmlspecialchars($urlManager->url('/login'), ENT_QUOTES, 'UTF-8');
$resetUrl = htmlspecialchars($urlManager->url('/reset-password'), ENT_QUOTES, 'UTF-8');
$authCssUrl = htmlspecialchars($urlManager->url('/assets/auth.css'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Nové heslo - BTCPay Lite</title><link rel="stylesheet" href="<?php echo $authCssUrl; ?>"></head>
<body class="auth-page"><main class="auth-shell">
  <a href="<?php echo $loginUrl; ?>" class="auth-brand"><span class="auth-brand-mark">₿</span><span class="auth-brand-copy"><strong>BTCPay Lite</strong><span>Obnova přístupu</span></span></a>
  <section class="auth-card">
    <header class="auth-card-header"><h1>Nastavit nové heslo</h1><p>Heslo musí mít 12 až 72 znaků.</p></header>
    <?php if ($error !== ''): ?><div class="auth-alert auth-alert-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="auth-success-actions"><div class="auth-alert auth-alert-success" role="status">Heslo bylo změněno. Všechny starší relace byly ukončeny.</div><a href="<?php echo $loginUrl; ?>" class="auth-primary-link">Přihlásit se</a></div>
    <?php else: ?>
      <form method="post" action="<?php echo $resetUrl; ?>" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="auth-field"><label for="newPassword">Nové heslo</label><div class="auth-input"><input id="newPassword" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></div></div>
        <div class="auth-field"><label for="newPasswordConfirm">Potvrzení hesla</label><div class="auth-input"><input id="newPasswordConfirm" type="password" name="password_confirm" minlength="12" maxlength="72" autocomplete="new-password" required></div></div>
        <button type="submit" class="auth-submit">Změnit heslo</button>
      </form>
    <?php endif; ?>
  </section>
</main></body></html>
