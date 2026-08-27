<?php
// admin/views/layout/header.php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'BTCPay Lite';
$activeMenu = $activeMenu ?? '';

// Detekce složky pro správné relativní cesty odkazů
$inAdmin = strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false;
$adminPrefix = $inAdmin ? '' : 'admin/';
$rootPrefix  = $inAdmin ? '../' : '';
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($pageTitle); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    
    body { 
      margin: 0; 
      color: #17201a; 
      font-family: Inter, sans-serif; 
      background-color: #ffffff; /* Čistě bílé pozadí */
      min-height: 100vh; 
    }

    /* Celkové rozvržení vycentrované na střed */
    .admin-wrapper {
      display: flex;
      min-height: 100vh;
      max-width: 1150px; /* Omezení maximální šířky celé aplikace */
      margin: 0 auto; /* Dokonalé vycentrování na velkých monitorech */
    }

    /* Levý postranní panel (Sidebar) */
    .sidebar {
      width: 240px; /* Lehce zúžené menu */
      background: #ffffff;
      border-right: 1px solid #e5eae7;
      display: flex;
      flex-direction: column;
      padding: 30px 16px;
      position: sticky;
      top: 0;
      height: 100vh;
      overflow-y: auto;
      flex-shrink: 0;
    }

    .sidebar-brand {
      font-size: 19px;
      font-weight: 800;
      color: #17201a;
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 0 10px 24px 10px;
      border-bottom: 1px solid #f0f4f1;
      margin-bottom: 16px;
      text-decoration: none;
    }

    .sidebar-brand i {
      color: #2fd35a;
      font-size: 22px;
    }

    .sidebar-section-title {
      font-size: 11px;
      font-weight: 700;
      color: #8c9b92;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      padding: 12px 10px 6px 10px;
    }

    .sidebar-nav {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 12px;
      border-radius: 9px;
      color: #526056;
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      transition: background 0.15s, color 0.15s;
    }

    .nav-item i {
      width: 18px;
      font-size: 14px;
      color: #748078;
      transition: color 0.15s;
    }

    .nav-item:hover {
      background: #f4f7f5;
      color: #17201a;
    }

    .nav-item:hover i {
      color: #20b948;
    }

    .nav-item.active {
      background: #17201a;
      color: #ffffff;
    }

    .nav-item.active i {
      color: #2fd35a;
    }

    /* Hlavní obsahová část (Pravá část) */
    .main-content {
      flex: 1;
      padding: 40px 50px;
      background: #ffffff;
      max-width: 850px; /* Užší a čitelnější obsah */
      width: 100%;
    }

    .page-header {
      margin-bottom: 28px;
    }

    .page-header h1 {
      font-size: 24px;
      font-weight: 800;
      margin: 0;
      color: #17201a;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* Karty a UI prvky */
    .card { 
      background: #ffffff; 
      border: 1px solid #e5eae7; 
      border-radius: 16px; 
      padding: 28px; 
      margin-bottom: 24px; 
    }

    .card-title { 
      font-size: 16px; 
      font-weight: 700; 
      margin: 0 0 20px 0; 
      display: flex; 
      align-items: center; 
      justify-content: space-between; 
    }

    .field { margin-bottom: 18px; }
    label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 7px; color: #748078; text-transform: uppercase; }
    
    .input-wrap { display: flex; border: 1px solid #e5eae7; border-radius: 10px; overflow: hidden; background: #ffffff; transition: border-color 0.2s; }
    .input-wrap:focus-within { border-color: #2fd35a; }
    input, select { width: 100%; border: 0; outline: 0; padding: 12px 14px; font: inherit; background: transparent; }
    select { cursor: pointer; color: #17201a; font-weight: 600; }
    .unit { padding: 12px 15px; font-weight: 700; color: #748078; border-left: 1px solid #e5eae7; background: #fafcfa; }

    .primary { 
      border: 0; 
      background: #2fd35a; 
      color: #ffffff; 
      border-radius: 10px; 
      padding: 12px 18px; 
      font-weight: 700; 
      cursor: pointer; 
      font-size: 13px; 
      display: inline-flex; 
      align-items: center; 
      justify-content: center; 
      gap: 8px; 
      transition: background 0.2s; 
    }
    .primary:hover { background: #20b948; }

    .ghost-btn { 
      border: 1px solid #e5eae7; 
      background: #ffffff; 
      border-radius: 9px; 
      padding: 8px 14px; 
      color: #17201a; 
      text-decoration: none; 
      font-weight: 600; 
      font-size: 12px; 
      display: inline-flex; 
      align-items: center; 
      gap: 6px; 
      cursor: pointer; 
      transition: border-color 0.2s, background 0.2s; 
    }
    .ghost-btn:hover { border-color: #17201a; background: #fafcfa; }

    .invoice-item { padding: 16px; border: 1px solid #e5eae7; border-radius: 12px; margin-bottom: 14px; background: #fafcfa; }
    .invoice-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .invoice-amount { font-family: ui-monospace, monospace; font-weight: 800; color: #17201a; font-size: 15px; }
    .invoice-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }

    .status-badge { display: inline-block; padding: 5px 10px; border-radius: 6px; font-weight: 700; font-size: 11px; text-transform: uppercase; }
    .s-paid { background: #eafbef; color: #13aa3d; border: 1px solid #13aa3d; }
    .s-paid_late { background: #f3e8ff; color: #7e22ce; border: 1px solid #7e22ce; }
    .s-unpaid { background: #ffffff; color: #748078; border: 1px solid #e5eae7; }
    .s-expired { background: #fee2e2; color: #ef4d4d; border: 1px solid #ef4d4d; }
    .s-pending_mempool { background: #e0f2fe; color: #0284c7; border: 1px solid #0284c7; }
    .s-underpaid { background: #fef3c7; color: #d97706; border: 1px solid #d97706; }
    .s-unknown { background: #f9fafa; color: #748078; border: 1px solid #e5eae7; }

    .toast { position: fixed; right: 25px; bottom: 25px; background: #17201a; color: #fff; padding: 12px 18px; border-radius: 10px; font-weight: 600; font-size: 13px; opacity: 0; transform: translateY(10px); transition: 0.3s; z-index: 1000; }
    .toast.show { opacity: 1; transform: translateY(0); }

    @media (max-width: 900px) {
      .admin-wrapper { flex-direction: column; }
      .sidebar { width: 100%; height: auto; position: static; border-right: 0; border-bottom: 1px solid #e5eae7; padding: 20px; }
      .main-content { padding: 25px 20px; }
    }
  </style>
</head>
<body>
<div class="admin-wrapper">

    <!-- LEVÉ MENU -->
    <aside class="sidebar">
        <a href="<?php echo $adminPrefix; ?>index.php" class="sidebar-brand">
            <i class="fa-solid fa-bolt"></i>
            <span>BTCPay Lite</span>
        </a>

        <div class="sidebar-section-title">Správa a Obchody</div>
        <nav class="sidebar-nav">
            <a href="<?php echo $adminPrefix; ?>index.php" class="nav-item <?php echo $activeMenu === 'dashboard' ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-pie"></i> Přehled
            </a>
            <a href="<?php echo $adminPrefix; ?>wallet.php" class="nav-item <?php echo $activeMenu === 'wallet' ? 'active' : ''; ?>">
                <i class="fa-solid fa-wallet"></i> Peněženka
            </a>
            <a href="<?php echo $adminPrefix; ?>stores.php" class="nav-item <?php echo $activeMenu === 'stores' ? 'active' : ''; ?>">
                <i class="fa-solid fa-shop"></i> E-shopy
            </a>
            <a href="<?php echo $adminPrefix; ?>invoices.php" class="nav-item <?php echo $activeMenu === 'invoices' ? 'active' : ''; ?>">
                <i class="fa-solid fa-database"></i> DB Faktury
            </a>
            <a href="<?php echo $adminPrefix; ?>webhooks.php" class="nav-item <?php echo $activeMenu === 'webhooks' ? 'active' : ''; ?>">
                <i class="fa-solid fa-satellite-dish"></i> Webhooky
            </a>
        </nav>

        <div class="sidebar-section-title" style="margin-top: 16px;">Bezstavový systém</div>
        <nav class="sidebar-nav">
            <a href="<?php echo $adminPrefix; ?>url_invoices.php" class="nav-item <?php echo $activeMenu === 'url_invoices' ? 'active' : ''; ?>">
                <i class="fa-solid fa-link"></i> URL Faktury
            </a>
        </nav>

        <div class="sidebar-section-title" style="margin-top: 16px;">Testy & Diagnostika</div>
        <nav class="sidebar-nav">
            <a href="<?php echo $adminPrefix; ?>test_shop.php" class="nav-item <?php echo $activeMenu === 'test_shop' ? 'active' : ''; ?>">
                <i class="fa-solid fa-store"></i> Test Obchodu
            </a>
            <a href="<?php echo $adminPrefix; ?>test_api_webhook.php" class="nav-item <?php echo $activeMenu === 'test_webhook' ? 'active' : ''; ?>">
                <i class="fa-solid fa-vial"></i> Test Webhooku
            </a>
            <a href="<?php echo $rootPrefix; ?>debugger.php" class="nav-item <?php echo $activeMenu === 'debugger' ? 'active' : ''; ?>">
                <i class="fa-solid fa-bug"></i> Master Debugger
            </a>
            <a href="<?php echo $rootPrefix; ?>test_stateless.php" class="nav-item <?php echo $activeMenu === 'test_stateless' ? 'active' : ''; ?>">
                <i class="fa-solid fa-code"></i> Test Stateless API
            </a>
            <a href="<?php echo $rootPrefix; ?>eshop_simulator.php" class="nav-item <?php echo $activeMenu === 'eshop_simulator' ? 'active' : ''; ?>">
                <i class="fa-solid fa-cart-shopping"></i> E-shop Simulátor
            </a>
            <a href="<?php echo $rootPrefix; ?>test_direct.php" class="nav-item <?php echo $activeMenu === 'test_direct' ? 'active' : ''; ?>">
                <i class="fa-solid fa-bolt-lightning"></i> Test Direct
            </a>
            <a href="<?php echo $rootPrefix; ?>test_create_wallet.php" class="nav-item <?php echo $activeMenu === 'test_create_wallet' ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder-plus"></i> Test Peněženky
            </a>
        </nav>
    </aside>

    <!-- HLAVNÍ PLOCHA OBSAHU -->
    <main class="main-content">