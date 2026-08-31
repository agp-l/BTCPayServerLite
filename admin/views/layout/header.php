<?php

declare(strict_types=1);

use BtcPayLite\AuthManager;
use BtcPayLite\UrlManager;

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'BTCPay Lite';
$config = isset($config) && is_array($config) ? $config : require __DIR__ . '/../../../config.php';
$urlManager = isset($urlManager) && $urlManager instanceof UrlManager
    ? $urlManager
    : new UrlManager(
        $_SERVER,
        is_string($config['app_url'] ?? null) ? $config['app_url'] : null
    );
$activeMenu = isset($activeMenu) && is_string($activeMenu)
    ? $activeMenu
    : $urlManager->getActiveMenu();
$csrfToken = isset($csrfToken) && is_string($csrfToken) && $csrfToken !== ''
    ? $csrfToken
    : AuthManager::csrfToken();
$adminEmail = is_string($_SESSION['email'] ?? null) ? $_SESSION['email'] : 'Administrator';
$adminInitial = strtoupper(substr($adminEmail, 0, 1));
$routeUrl = static fn (string $path): string => htmlspecialchars(
    $urlManager->url($path),
    ENT_QUOTES,
    'UTF-8'
);
$navItem = static function (
    string $path,
    string $menu,
    string $icon,
    string $label
) use ($routeUrl, $activeMenu): void {
    $active = $activeMenu === $menu;
    ?>
    <a href="<?php echo $routeUrl($path); ?>"
       class="admin-nav-link<?php echo $active ? ' is-active' : ''; ?>"
       <?php echo $active ? 'aria-current="page"' : ''; ?>>
        <i class="fa-solid <?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
        <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
    <?php
};
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
  <aside class="admin-sidebar" id="adminSidebar" aria-label="Hlavní navigace">
    <a href="<?php echo $routeUrl('/admin/dashboard'); ?>" class="admin-brand">
      <span class="admin-brand-mark"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
      <span class="admin-brand-copy"><strong>BTCPay Lite</strong><span>Payment operations</span></span>
    </a>

    <nav class="admin-nav">
      <div class="admin-nav-group">
        <div class="admin-nav-label">Přehled</div>
        <?php $navItem('/admin/dashboard', 'dashboard', 'fa-chart-line', 'Dashboard'); ?>
        <?php $navItem('/admin/wallet', 'wallet', 'fa-wallet', 'Peněženka'); ?>
      </div>

      <div class="admin-nav-group">
        <div class="admin-nav-label">Platby</div>
        <?php $navItem('/admin/stores', 'stores', 'fa-store', 'Obchody'); ?>
        <?php $navItem('/admin/invoices', 'invoices', 'fa-file-invoice', 'Faktury'); ?>
        <?php $navItem('/admin/webhooks', 'webhooks', 'fa-wave-square', 'Webhooky'); ?>
      </div>

      <div class="admin-nav-group">
        <div class="admin-nav-label">Nástroje</div>
        <?php $navItem('/admin/url_invoices', 'url_invoices', 'fa-link', 'URL faktury'); ?>
        <a href="<?php echo $routeUrl('/'); ?>" class="admin-nav-link" target="_blank" rel="noopener">
          <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
          <span>Otevřít web</span>
        </a>
      </div>
    </nav>

    <div class="admin-sidebar-footer">
      <div class="admin-profile">
        <span class="admin-profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($adminInitial, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="admin-profile-copy">
          <strong>Administrátor</strong>
          <span title="<?php echo htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8'); ?></span>
        </span>
      </div>
      <form method="post" action="<?php echo $routeUrl('/login'); ?>" class="admin-logout">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="admin-nav-link">
          <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
          <span>Odhlásit se</span>
        </button>
      </form>
    </div>
  </aside>

  <div class="admin-overlay" data-sidebar-close aria-hidden="true"></div>

  <div class="admin-main">
    <header class="admin-topbar">
      <div class="admin-topbar-context">
        <button type="button" class="ghost-btn admin-mobile-toggle" data-sidebar-open aria-label="Otevřít navigaci" aria-controls="adminSidebar" aria-expanded="false">
          <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        <span>Zabezpečená administrace</span>
      </div>
      <div class="admin-topbar-actions">
        <span class="badge s-paid"><i class="fa-solid fa-circle" aria-hidden="true"></i> Systém online</span>
      </div>
    </header>
    <main class="admin-content">
