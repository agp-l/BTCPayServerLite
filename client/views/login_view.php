<?php

declare(strict_types=1);

$homeUrl = htmlspecialchars($urlManager->url('/'), ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($urlManager->url('/login'), ENT_QUOTES, 'UTF-8');
$registrationUrl = htmlspecialchars($urlManager->url('/registrace'), ENT_QUOTES, 'UTF-8');
$forgotPasswordUrl = htmlspecialchars($urlManager->url('/forgot-password'), ENT_QUOTES, 'UTF-8');
$authCssUrl = htmlspecialchars($urlManager->url('/assets/auth.css'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Přihlášení - BTCPay Lite</title>
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
      <span class="auth-brand-copy"><strong>BTCPay Lite</strong><span>Self-hosted payments</span></span>
    </a>
    <section class="auth-card">
      <header class="auth-card-header"><h1>Vítejte zpět</h1><p>Přihlaste se do zabezpečeného platebního portálu.</p></header>
      <?php if ($error !== ''): ?>
        <div class="auth-alert auth-alert-error" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></div>
      <?php endif; ?>
      <form method="post" action="<?php echo $loginUrl; ?>" class="auth-form">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="auth-field"><label for="loginEmail">E-mail</label><div class="auth-input"><i class="fa-solid fa-envelope" aria-hidden="true"></i><input id="loginEmail" type="email" name="email" autocomplete="username" required></div></div>
        <div class="auth-field"><label for="loginPassword">Heslo</label><div class="auth-input"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="loginPassword" type="password" name="password" autocomplete="current-password" required></div></div>
        <div class="auth-footer"><a href="<?php echo $forgotPasswordUrl; ?>">Zapomenuté heslo</a></div>
        <button type="submit" class="auth-submit"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Přihlásit se</button>
      </form>
      <div class="auth-footer">Nemáte účet? <a href="<?php echo $registrationUrl; ?>">Vytvořit registraci</a></div>
      <div class="auth-security-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Relace chráněná CSRF a bezpečnostními cookies</div>
    </section>
  </main>
</body>
</html>
