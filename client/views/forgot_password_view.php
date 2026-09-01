<?php

declare(strict_types=1);

$loginUrl = htmlspecialchars($urlManager->url('/login'), ENT_QUOTES, 'UTF-8');
$forgotUrl = htmlspecialchars($urlManager->url('/forgot-password'), ENT_QUOTES, 'UTF-8');
$authCssUrl = htmlspecialchars($urlManager->url('/assets/auth.css'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Zapomenuté heslo - BTCPay Lite</title><link rel="stylesheet" href="<?php echo $authCssUrl; ?>"></head>
<body class="auth-page"><main class="auth-shell">
  <a href="<?php echo $loginUrl; ?>" class="auth-brand"><span class="auth-brand-mark">₿</span><span class="auth-brand-copy"><strong>BTCPay Lite</strong><span>Obnova přístupu</span></span></a>
  <section class="auth-card">
    <header class="auth-card-header"><h1>Zapomenuté heslo</h1><p>Pošleme jednorázový odkaz platný 30 minut.</p></header>
    <?php if ($error !== ''): ?><div class="auth-alert auth-alert-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="auth-alert auth-alert-success" role="status">Pokud účet existuje, odeslali jsme na něj pokyny k obnově hesla.</div>
    <?php else: ?>
      <form method="post" action="<?php echo $forgotUrl; ?>" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="auth-field"><label for="resetEmail">E-mail</label><div class="auth-input"><input id="resetEmail" type="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email" required></div></div>
        <button type="submit" class="auth-submit">Odeslat odkaz</button>
      </form>
    <?php endif; ?>
    <div class="auth-footer"><a href="<?php echo $loginUrl; ?>">Zpět na přihlášení</a></div>
  </section>
</main></body></html>
