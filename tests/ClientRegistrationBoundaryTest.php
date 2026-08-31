<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/client/registrace.php');
$loginView = file_get_contents($root . '/client/views/login_view.php');
$registrationView = file_get_contents($root . '/client/views/registrace_view.php');
foreach (compact('controller', 'loginView', 'registrationView') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException($name . ' source could not be read.');
    }
}

if (
    str_contains($controller, '->prepare(')
    || str_contains($controller, '->getPdo(')
    || str_contains($controller, 'exec(')
    || str_contains($controller, 'shell_exec(')
    || str_contains($controller, 'unlink(')
) {
    throw new RuntimeException('Registration controller contains persistence, process, or file implementation details.');
}
echo "[PASS] keeps SQL, processes and wallet files outside registration controller\n";

if (!str_contains($controller, 'ClientRegistrationService') || !str_contains($controller, 'requireCsrfToken')) {
    throw new RuntimeException('Registration controller does not use its service and CSRF boundary.');
}
echo "[PASS] delegates registration behind a CSRF-protected service boundary\n";

$views = $loginView . $registrationView;
if (str_contains($views, '<style>') || str_contains($views, ' style=')) {
    throw new RuntimeException('Authentication views contain inline styles.');
}
echo "[PASS] removes inline styles from authentication views\n";

if (substr_count($views, '/assets/auth.css') !== 2) {
    throw new RuntimeException('Authentication views do not share the reusable stylesheet.');
}
echo "[PASS] shares one authentication design system\n";

if (
    !str_contains($registrationView, 'name="csrf_token"')
    || !str_contains($registrationView, "url('/registrace')")
    || !str_contains($loginView, 'name="csrf_token"')
    || !str_contains($loginView, "url('/login')")
) {
    throw new RuntimeException('Authentication forms are not canonical CSRF-protected POST forms.');
}
echo "[PASS] uses canonical protected authentication forms\n";

if (
    !str_contains($controller, "config['store_wallets_dir']")
    || !str_contains($controller, "config['wallet_directory']")
    || !str_contains($controller, 'Wallet directory configuration is missing.')
) {
    throw new RuntimeException('Registration can silently provision a wallet outside a configured directory.');
}
echo "[PASS] requires an explicit managed wallet directory\n";

if (
    !str_contains($controller, "config['electrum_cli_path']")
    || !str_contains($controller, "config['electrum_cli']")
    || !str_contains($controller, "config['electrum_data_dir']")
    || !str_contains($controller, "config['electrum_data_directory']")
) {
    throw new RuntimeException('Registration dropped compatibility with installed Electrum configuration keys.');
}
echo "[PASS] keeps registration Electrum configuration backward compatible\n";

echo "7 client registration boundary tests passed.\n";
