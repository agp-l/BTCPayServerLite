<?php

declare(strict_types=1);

$accountUrl = htmlspecialchars(
    $urlManager->url($role === 'admin' ? '/admin/account' : '/client/account'),
    ENT_QUOTES,
    'UTF-8'
);
$backUrl = htmlspecialchars($urlManager->url($backPath), ENT_QUOTES, 'UTF-8');
$authCssUrl = htmlspecialchars($urlManager->url('/assets/auth.css'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Změna hesla - BTCPay Lite</title><link rel="stylesheet" href="<?php echo $authCssUrl; ?>"></head>
<body class="auth-page"><main class="auth-shell">
  <a href="<?php echo $backUrl; ?>" class="auth-brand"><span class="auth-brand-mark">₿</span><span class="auth-brand-copy"><strong>BTCPay Lite</strong><span>Zabezpečení účtu</span></span></a>
  <section class="auth-card">
    <header class="auth-card-header"><h1>Změna hesla</h1><p>Změna ukončí všechny ostatní přihlášené relace.</p></header>
    <?php if ($error !== ''): ?><div class="auth-alert auth-alert-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="auth-alert auth-alert-success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post" action="<?php echo $accountUrl; ?>" class="auth-form">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <div class="auth-field"><label for="currentPassword">Současné heslo</label><div class="auth-input"><input id="currentPassword" type="password" name="current_password" autocomplete="current-password" required></div></div>
      <div class="auth-field"><label for="accountNewPassword">Nové heslo</label><div class="auth-input"><input id="accountNewPassword" type="password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" required></div></div>
      <div class="auth-field"><label for="accountNewPasswordConfirm">Potvrzení hesla</label><div class="auth-input"><input id="accountNewPasswordConfirm" type="password" name="new_password_confirm" minlength="12" maxlength="72" autocomplete="new-password" required></div></div>
      <button type="submit" class="auth-submit">Změnit heslo</button>
    </form>
    <div class="auth-footer"><a href="<?php echo $backUrl; ?>">Zpět do přehledu</a></div>
  </section>
</main></body></html>
