<?php
// prezentace.php - Veřejná ukázková stránka (Landing Page)
declare(strict_types=1);

// Získáme základní URL pro správné fungování odkazů
use BtcPayLite\UrlManager;

if (!isset($urlManager) || !$urlManager instanceof UrlManager) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $config = isset($config) && is_array($config) ? $config : require __DIR__ . '/../config.php';
    $urlManager = new UrlManager(
        $_SERVER,
        is_string($config['app_url'] ?? null) ? $config['app_url'] : null
    );
}
$baseUrl = $urlManager->getBaseUrl();
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BTCPay Lite - Moderní platební brána</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body { 
      margin: 0; color: #17201a; font-family: Inter, sans-serif; background-color: #fafcfa; 
      background-image: radial-gradient(circle at 50% 0%, rgba(47, 211, 90, 0.12) 0%, transparent 60%), linear-gradient(to right, rgba(229, 234, 231, 0.7) 1px, transparent 1px), linear-gradient(to bottom, rgba(229, 234, 231, 0.7) 1px, transparent 1px);
      background-size: 100% 100%, 24px 24px, 24px 24px; background-attachment: fixed; min-height: 100vh;
    }
    .container { max-width: 1100px; margin: 0 auto; padding: 40px 20px; }
    
    /* Navigace */
    .navbar { display: flex; justify-content: space-between; align-items: center; padding-bottom: 20px; border-bottom: 1px solid rgba(229, 234, 231, 0.8); margin-bottom: 60px; }
    .logo { font-size: 24px; font-weight: 800; display: flex; align-items: center; gap: 10px; color: #17201a; text-decoration: none; }
    .nav-links a { color: #748078; text-decoration: none; font-weight: 600; margin-left: 20px; transition: 0.2s; }
    .nav-links a:hover { color: #2fd35a; }
    
    /* Hero sekce */
    .hero { text-align: center; max-width: 800px; margin: 0 auto 80px auto; }
    .hero h1 { font-size: 52px; letter-spacing: -1.5px; margin-bottom: 20px; line-height: 1.1; }
    .hero p { font-size: 18px; color: #748078; line-height: 1.6; margin-bottom: 40px; }
    
    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 15px 30px; border-radius: 12px; font-weight: 700; text-decoration: none; transition: 0.2s; font-size: 16px; }
    .btn-primary { background: #2fd35a; color: #fff; }
    .btn-primary:hover { background: #20b948; transform: translateY(-2px); box-shadow: 0 10px 20px rgba(47, 211, 90, 0.2); }
    .btn-outline { background: #fff; color: #17201a; border: 1px solid #e5eae7; margin-left: 15px; }
    .btn-outline:hover { border-color: #17201a; }

    /* Vlastnosti */
    .features { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; }
    .feature-card { background: #fff; padding: 30px; border-radius: 20px; border: 1px solid #e5eae7; box-shadow: 0 10px 30px rgba(20,45,28,.04); text-align: left; }
    .feature-icon { width: 50px; height: 50px; background: #eafbef; color: #20b948; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 20px; }
    .feature-card h3 { margin: 0 0 10px 0; font-size: 20px; }
    .feature-card p { margin: 0; color: #748078; line-height: 1.5; font-size: 14px; }
  </style>
</head>
<body>

<div class="container">
    <nav class="navbar">
        <a href="#" class="logo"><i class="fa-solid fa-bolt" style="color: #2fd35a;"></i> BTCPay Lite</a>
        <div class="nav-links">
            <a href="#features">Funkce</a>
            <a href="<?php echo htmlspecialchars($baseUrl); ?>/login">Přihlásit se</a>
            <a href="<?php echo htmlspecialchars($baseUrl); ?>/registrace" class="btn btn-primary" style="padding: 10px 20px; margin-left: 20px;">Vytvořit účet</a>
        </div>
    </nav>

    <header class="hero">
        <h1>Bitcoin platby pro váš byznys. <br>Rychle a bez prostředníků.</h1>
        <p>Nejlehčí a nejrychlejší self-hosted platební brána. Přijímejte Bitcoin přímo do své vlastní peněženky s plnou kontrolou nad svými klíči. Bez měsíčních poplatků, bez databáze, bez kompromisů.</p>
        <div>
            <a href="<?php echo htmlspecialchars($baseUrl); ?>/registrace" class="btn btn-primary">Začít zdarma</a>
            <a href="<?php echo htmlspecialchars($baseUrl); ?>/login" class="btn btn-outline">Prohlédnout demo</a>
        </div>
    </header>

    <section id="features" class="features">
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-link"></i></div>
            <h3>Stateless Architektura</h3>
            <p>Generujte platební odkazy bez nutnosti ukládat data do složitých databází. Veškeré informace nesou bezpečně podepsané URL adresy chráněné HMAC pečetí.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-wallet"></i></div>
            <h3>Self-Custody</h3>
            <p>Jste svou vlastní bankou. Systém napřímo komunikuje s vaším Electrum démonem. Vaše privátní klíče nikdy neopouští váš server a platby jdou přímo vám.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-plug"></i></div>
            <h3>Snadná integrace</h3>
            <p>Kompatibilní s Greenfield API. Systém snadno napojíte na svůj e-shop ve WooCommerce, nebo si vytvoříte vlastní řešení pomocí jednoduchých HTTP požadavků.</p>
        </div>
    </section>
</div>

</body>
</html>