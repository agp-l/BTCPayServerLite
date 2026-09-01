<?php

declare(strict_types=1);

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\InstallationManager;
use BtcPayLite\InstallerException;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$installer = new InstallationManager(__DIR__);
$scriptName = is_string($_SERVER['SCRIPT_NAME'] ?? null)
    ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME'])
    : '/install.php';
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$loginUrl = ($basePath === '' ? '' : $basePath) . '/login';

if ($installer->isInstalled()) {
    header('Location: ' . $loginUrl, true, 303);
    exit;
}

try {
    AuthManager::startSession();
} catch (AuthException $exception) {
    http_response_code(500);
    echo 'Instalační relaci se nepodařilo bezpečně spustit.';
    exit;
}

$error = '';
$success = null;
$requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null)
    ? strtoupper($_SERVER['REQUEST_METHOD'])
    : 'GET';

if ($requestMethod === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $success = $installer->install($_POST);
        unset($_SESSION['csrf_token']);
    } catch (AuthException|InstallerException $exception) {
        $error = $exception->getMessage();
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            error_log('Installer failure: ' . $previous::class);
        }
    } catch (Throwable $exception) {
        error_log('Unexpected installer failure: ' . $exception::class);
        $error = 'Instalaci se nepodařilo dokončit. Zkontrolujte serverový log.';
    }
} elseif (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit;
}

$requirements = $installer->requirements();
$canInstall = $installer->canInstall();
$csrfToken = $success === null ? AuthManager::csrfToken() : '';

$posted = static function (string $key, string $default = ''): string {
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : $default;
};
$html = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$defaultAppUrl = '';
$host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : '';
if (preg_match('/\A[A-Za-z0-9.\-\[\]:]+\z/D', $host)) {
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $defaultAppUrl = $scheme . '://' . $host . ($basePath === '' ? '' : $basePath);
}

$formAction = $html($scriptName);
$cssUrl = $html(($basePath === '' ? '' : $basePath) . '/assets/install.css');
$loginUrlEscaped = $html($loginUrl);
$createDatabaseChecked = ($requestMethod !== 'POST' && !isset($_POST['create_database']))
    || ($_POST['create_database'] ?? null) === '1';
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>Instalace – BTCPay Server Lite</title>
  <link rel="stylesheet" href="<?php echo $cssUrl; ?>">
</head>
<body>
  <main class="installer-shell">
    <header class="installer-hero">
      <div class="brand-mark" aria-hidden="true">₿</div>
      <div><span class="eyebrow">BTCPay Server Lite</span><h1>První instalace</h1><p>Databáze, bezpečná konfigurace a první administrátor v jednom kroku.</p></div>
    </header>

    <?php if (is_array($success)): ?>
      <section class="installer-card success-card">
        <span class="success-icon" aria-hidden="true">✓</span>
        <h2>Instalace byla dokončena</h2>
        <p>Databáze a účet <strong><?php echo $html($success['admin_email']); ?></strong> jsou připravené. Náhodné API, podpisové a cron klíče byly uloženy do privátního <code>config.php</code>.</p>
        <a class="primary-button" href="<?php echo $loginUrlEscaped; ?>">Přejít k přihlášení</a>
        <p class="security-note">Instalátor je nyní uzamčen existencí konfiguračního souboru.</p>
      </section>
    <?php else: ?>
      <section class="installer-card requirements-card" aria-labelledby="requirements-title">
        <div class="section-heading"><div><span class="step">Kontrola</span><h2 id="requirements-title">Serverové požadavky</h2></div><span class="status-pill <?php echo $canInstall ? 'status-ok' : 'status-error'; ?>"><?php echo $canInstall ? 'Připraveno' : 'Vyžaduje zásah'; ?></span></div>
        <div class="requirements-grid">
          <?php foreach ($requirements as $requirement): ?>
            <div class="requirement <?php echo $requirement['ok'] ? 'requirement-ok' : 'requirement-error'; ?>">
              <span class="requirement-icon" aria-hidden="true"><?php echo $requirement['ok'] ? '✓' : '!'; ?></span>
              <div><strong><?php echo $html($requirement['name']); ?></strong><span><?php echo $html($requirement['detail']); ?></span></div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if ($error !== ''): ?>
        <div class="alert" role="alert"><strong>Instalaci nelze dokončit.</strong><span><?php echo $html($error); ?></span></div>
      <?php endif; ?>

      <form method="post" action="<?php echo $formAction; ?>" class="installer-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $html($csrfToken); ?>">

        <section class="installer-card">
          <div class="section-heading"><div><span class="step">Krok 1</span><h2>Databáze</h2><p>Použijte samostatnou prázdnou databázi MySQL nebo MariaDB.</p></div></div>
          <div class="field-grid four-columns">
            <label class="field field-wide"><span>Host databáze</span><input name="db_host" value="<?php echo $html($posted('db_host', '127.0.0.1')); ?>" maxlength="253" required></label>
            <label class="field"><span>Port</span><input name="db_port" type="number" min="1" max="65535" value="<?php echo $html($posted('db_port', '3306')); ?>" required></label>
            <label class="field"><span>Název databáze</span><input name="db_name" value="<?php echo $html($posted('db_name', 'btcpay_lite')); ?>" maxlength="64" pattern="[A-Za-z0-9_$-]+" required></label>
            <label class="field"><span>Uživatel</span><input name="db_user" value="<?php echo $html($posted('db_user')); ?>" maxlength="128" autocomplete="username" required></label>
            <label class="field field-wide"><span>Heslo databáze</span><input name="db_pass" type="password" autocomplete="new-password"></label>
          </div>
          <label class="check-field"><input type="checkbox" name="create_database" value="1" <?php echo $createDatabaseChecked ? 'checked' : ''; ?>><span><strong>Vytvořit databázi, pokud neexistuje</strong><small>Databázový uživatel k tomu potřebuje oprávnění CREATE. Existující databáze musí být prázdná.</small></span></label>
        </section>

        <section class="installer-card">
          <div class="section-heading"><div><span class="step">Krok 2</span><h2>Administrátor a veřejná URL</h2><p>Tyto údaje použijete pro první přihlášení do správy.</p></div></div>
          <div class="field-grid two-columns">
            <label class="field"><span>E-mail administrátora</span><input name="admin_email" type="email" value="<?php echo $html($posted('admin_email')); ?>" maxlength="254" autocomplete="username" required></label>
            <label class="field"><span>Veřejná URL aplikace</span><input name="app_url" type="url" value="<?php echo $html($posted('app_url', $defaultAppUrl)); ?>" placeholder="https://pay.example.com" required></label>
            <label class="field"><span>Heslo administrátora</span><input name="admin_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><small>12 až 72 znaků. Použijte jedinečné heslo.</small></label>
            <label class="field"><span>Heslo znovu</span><input name="admin_password_confirm" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <label class="field"><span>Odesílatel obnovy hesla</span><input name="password_reset_from" type="email" value="<?php echo $html($posted('password_reset_from')); ?>" placeholder="no-reply@example.com"></label>
          </div>
        </section>

        <section class="installer-card">
          <details <?php echo $error !== '' ? 'open' : ''; ?>>
            <summary><span><span class="step">Krok 3</span><strong>Electrum a cesty peněženek</strong><small>Výchozí hodnoty upravte podle serveru.</small></span><span class="summary-arrow" aria-hidden="true">⌄</span></summary>
            <div class="details-content">
              <div class="field-grid four-columns">
                <label class="field field-wide"><span>Electrum RPC host</span><input name="rpc_host" value="<?php echo $html($posted('rpc_host', '127.0.0.1')); ?>" required></label>
                <label class="field"><span>RPC port</span><input name="rpc_port" type="number" min="1" max="65535" value="<?php echo $html($posted('rpc_port', '7777')); ?>" required></label>
                <label class="field"><span>RPC uživatel</span><input name="rpc_user" value="<?php echo $html($posted('rpc_user')); ?>" autocomplete="off"></label>
                <label class="field"><span>RPC heslo</span><input name="rpc_pass" type="password" autocomplete="new-password"></label>
                <label class="field field-full"><span>Admin peněženka</span><input name="wallet_path" value="<?php echo $html($posted('wallet_path', '/opt/btcpay_wallets/admin_wallet')); ?>" required></label>
                <label class="field field-full"><span>Electrum CLI</span><input name="electrum_cli_path" value="<?php echo $html($posted('electrum_cli_path', '/opt/electrum/run_electrum')); ?>" required></label>
                <label class="field field-full"><span>Datový adresář Electrum</span><input name="electrum_data_dir" value="<?php echo $html($posted('electrum_data_dir', '/opt/electrum_config')); ?>" required></label>
                <label class="field field-full"><span>Adresář peněženek obchodů</span><input name="store_wallets_dir" value="<?php echo $html($posted('store_wallets_dir', '/opt/btcpay_wallets')); ?>" required></label>
              </div>
            </div>
          </details>
        </section>

        <section class="submit-card">
          <div><strong>Připraveno k instalaci</strong><span>Citlivá pole se nevracejí do HTML a náhodné klíče se generují na serveru.</span></div>
          <button type="submit" class="primary-button" <?php echo $canInstall ? '' : 'disabled'; ?>>Vytvořit databázi a admin účet</button>
        </section>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
