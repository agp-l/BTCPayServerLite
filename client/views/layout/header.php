<?php
// client/views/layout/header.php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Klientský panel';
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
    body { margin: 0; color: #17201a; font-family: Inter, sans-serif; background-color: #ffffff; min-height: 100vh; }
    
    .admin-wrapper { display: flex; min-height: 100vh; max-width: 1150px; margin: 0 auto; }
    
    .sidebar { width: 240px; background: #ffffff; border-right: 1px solid #e5eae7; display: flex; flex-direction: column; padding: 30px 16px; position: sticky; top: 0; height: 100vh; overflow-y: auto; flex-shrink: 0; }
    .sidebar-brand { font-size: 19px; font-weight: 800; color: #17201a; display: flex; align-items: center; gap: 10px; padding: 0 10px 24px 10px; border-bottom: 1px solid #f0f4f1; margin-bottom: 16px; text-decoration: none; }
    .sidebar-brand i { color: #2fd35a; font-size: 22px; }
    .sidebar-section-title { font-size: 11px; font-weight: 700; color: #8c9b92; text-transform: uppercase; letter-spacing: 0.6px; padding: 12px 10px 6px 10px; }
    .sidebar-nav { display: flex; flex-direction: column; gap: 3px; }
    .nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 9px; color: #526056; text-decoration: none; font-size: 13px; font-weight: 600; transition: 0.15s; }
    .nav-item i { width: 18px; font-size: 14px; color: #748078; transition: 0.15s; }
    .nav-item:hover { background: #f4f7f5; color: #17201a; }
    .nav-item:hover i { color: #20b948; }
    .nav-item.active { background: #17201a; color: #ffffff; }
    .nav-item.active i { color: #2fd35a; }
    
    .main-content { flex: 1; padding: 40px 50px; background: #ffffff; max-width: 850px; width: 100%; }
    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 24px; font-weight: 800; margin: 0; color: #17201a; display: flex; align-items: center; gap: 10px; }
    
    .card { background: #ffffff; border: 1px solid #e5eae7; border-radius: 16px; padding: 28px; margin-bottom: 24px; }
    .card-title { font-size: 16px; font-weight: 700; margin: 0 0 20px 0; display: flex; align-items: center; gap: 8px; }
    .field { margin-bottom: 18px; }
    label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 7px; color: #748078; text-transform: uppercase; }
    .input-wrap { display: flex; border: 1px solid #e5eae7; border-radius: 10px; overflow: hidden; background: #ffffff; transition: 0.2s; }
    .input-wrap:focus-within { border-color: #2fd35a; }
    input, select { width: 100%; border: 0; outline: 0; padding: 12px 14px; font: inherit; background: transparent; }
    
    .primary { border: 0; background: #2fd35a; color: #ffffff; border-radius: 10px; padding: 12px 18px; font-weight: 700; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .primary:hover { background: #20b948; }
    .ghost-btn { border: 1px solid #e5eae7; background: #ffffff; border-radius: 9px; padding: 8px 14px; color: #17201a; text-decoration: none; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: 0.2s; }
    .ghost-btn:hover { border-color: #17201a; background: #fafcfa; }
    .danger-btn { border: 1px solid #fee2e2; background: #fff0f0; border-radius: 9px; padding: 8px 14px; color: #ef4d4d; text-decoration: none; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: 0.2s; }
    .danger-btn:hover { background: #ef4d4d; color: #ffffff; border-color: #ef4d4d; }

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
    <aside class="sidebar">
        <div class="sidebar-brand">
            <i class="fa-solid fa-bolt"></i>
            <span>BTCPay Lite</span>
        </div>
        <div class="sidebar-section-title">Klientská sekce</div>
        <nav class="sidebar-nav">
            <a href="<?php echo htmlspecialchars($urlManager->url('/client'), ENT_QUOTES, 'UTF-8'); ?>" class="nav-item active">
                <i class="fa-solid fa-house-user"></i> Můj účet
            </a>
        </nav>
        <div style="margin-top: auto; padding-top: 20px;">
            <span style="font-size: 12px; font-weight: 600; color: #748078; display: block; padding: 0 12px; margin-bottom: 10px;">
                <i class="fa-solid fa-user" style="margin-right: 5px;"></i> <?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>
            </span>
            <form method="POST" action="<?php echo htmlspecialchars($urlManager->url('/login'), ENT_QUOTES, 'UTF-8'); ?>" style="margin: 0;">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="nav-item" style="color: #ef4d4d; border: 0; background: transparent; width: 100%; cursor: pointer;">
                    <i class="fa-solid fa-right-from-bracket" style="color: #ef4d4d;"></i> Odhlásit se
                </button>
            </form>
        </div>
    </aside>
    <main class="main-content">