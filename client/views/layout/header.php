<?php

declare(strict_types=1);

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Klientský panel';
$clientEmail = is_string($_SESSION['email'] ?? null) ? $_SESSION['email'] : 'Klient';
$clientInitial = strtoupper(substr($clientEmail, 0, 1));
$activeMenu = isset($activeMenu) && is_string($activeMenu) ? $activeMenu : 'client';
$routeUrl = static fn (string $path): string => htmlspecialchars(
    $urlManager->url($path),
    ENT_QUOTES,
    'UTF-8'
);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?php echo $routeUrl('/assets/admin.css'); ?>">
</head>
<body>
<div class="admin-shell">
  <aside class="admin-sidebar" id="clientSidebar" aria-label="Klientská navigace">
    <a href="<?php echo $routeUrl('/client'); ?>" class="admin-brand">
      <span class="admin-brand-mark"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
      <span class="admin-brand-copy"><strong>BTCPay Lite</strong><span>Merchant portal</span></span>
    </a>

    <nav class="admin-nav">
      <div class="admin-nav-group">
        <div class="admin-nav-label">Můj účet</div>
        <a href="<?php echo $routeUrl('/client'); ?>" class="admin-nav-link<?php echo $activeMenu === 'client' ? ' is-active' : ''; ?>" <?php echo $activeMenu === 'client' ? 'aria-current="page"' : ''; ?>>
          <i class="fa-solid fa-grid-2" aria-hidden="true"></i><span>Přehled</span>
        </a>
        <a href="<?php echo $routeUrl('/client/account'); ?>" class="admin-nav-link<?php echo $activeMenu === 'account' ? ' is-active' : ''; ?>" <?php echo $activeMenu === 'account' ? 'aria-current="page"' : ''; ?>>
          <i class="fa-solid fa-key" aria-hidden="true"></i><span>Změna hesla</span>
        </a>
      </div>
      <div class="admin-nav-group">
        <div class="admin-nav-label">Nápověda</div>
        <a href="<?php echo $routeUrl('/'); ?>" class="admin-nav-link" target="_blank" rel="noopener">
          <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span>Otevřít web</span>
        </a>
      </div>
    </nav>

    <div class="admin-sidebar-footer">
      <div class="admin-profile">
        <span class="admin-profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($clientInitial, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="admin-profile-copy"><strong>Klientský účet</strong><span title="<?php echo htmlspecialchars($clientEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($clientEmail, ENT_QUOTES, 'UTF-8'); ?></span></span>
      </div>
      <form method="post" action="<?php echo $routeUrl('/login'); ?>" class="admin-logout">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="admin-nav-link"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Odhlásit se</span></button>
      </form>
    </div>
  </aside>

  <div class="admin-overlay" data-sidebar-close aria-hidden="true"></div>
  <div class="admin-main">
    <header class="admin-topbar">
      <div class="admin-topbar-context">
        <button type="button" class="ghost-btn admin-mobile-toggle" data-sidebar-open aria-label="Otevřít navigaci" aria-controls="clientSidebar" aria-expanded="false"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>Zabezpečený klientský portál</span>
      </div>
      <span class="badge s-paid"><i class="fa-solid fa-circle" aria-hidden="true"></i> Systém online</span>
    </header>
    <main class="admin-content">
