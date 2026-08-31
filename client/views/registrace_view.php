<?php

declare(strict_types=1);

$homeUrl = htmlspecialchars($urlManager->url('/'), ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($urlManager->url('/login'), ENT_QUOTES, 'UTF-8');
$registrationUrl = htmlspecialchars($urlManager->url('/registrace'), ENT_QUOTES, 'UTF-8');
$authCssUrl = htmlspecialchars($urlManager->url('/assets/auth.css'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Registrace - BTCPay Lite</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?php echo $authCssUrl; ?>">
</head>
<body class="auth-page">
  <main class="auth-shell">
    <a href="<?php echo $homeUrl; ?>" class="auth-brand">
      <span class="auth-brand-mark"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
      <span class="auth-brand-copy"><strong>BTCPay Lite</strong><span>Merchant onboarding</span></span>
    </a>
    <section class="auth-card">
      <header class="auth-card-header"><h1>Vytvořit účet</h1><p>Získáte vlastní obchod, API klíč a oddělenou Electrum peněženku.</p></header>
      <?php if ($error !== ''): ?>
        <div class="auth-alert auth-alert-error" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></div>
      <?php endif; ?>
      <?php if ($successMsg !== ''): ?>
        <div class="auth-success-actions">
          <div class="auth-alert auth-alert-success" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span><?php echo htmlspecialchars($successMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></div>
          <a href="<?php echo $loginUrl; ?>" class="auth-primary-link"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Přihlásit se</a>
        </div>
      <?php else: ?>
        <form method="post" action="<?php echo $registrationUrl; ?>" class="auth-form">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="auth-field"><label for="registerEmail">E-mail</label><div class="auth-input"><i class="fa-solid fa-envelope" aria-hidden="true"></i><input id="registerEmail" type="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" autocomplete="username" required></div></div>
          <div class="auth-field"><label for="registerPassword">Heslo</label><div class="auth-input"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="registerPassword" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></div></div>
          <div class="auth-field"><label for="registerConfirm">Potvrzení hesla</label><div class="auth-input"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="registerConfirm" type="password" name="password_confirm" minlength="12" maxlength="72" autocomplete="new-password" required></div></div>
          <button type="submit" class="auth-submit"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Vytvořit účet</button>
        </form>
        <div class="auth-footer">Už máte účet? <a href="<?php echo $loginUrl; ?>">Přihlásit se</a></div>
      <?php endif; ?>
      <div class="auth-security-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Heslo musí mít 12 až 72 znaků</div>
    </section>
  </main>
</body>
</html>
