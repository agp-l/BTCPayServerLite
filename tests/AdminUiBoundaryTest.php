<?php

declare(strict_types=1);

$dashboard = file_get_contents(__DIR__ . '/../admin/dashboard.php');
$wallet = file_get_contents(__DIR__ . '/../admin/wallet.php');
$header = file_get_contents(__DIR__ . '/../admin/views/layout/header.php');
$walletView = file_get_contents(__DIR__ . '/../admin/views/wallet_view.php');

foreach (compact('dashboard', 'wallet', 'header', 'walletView') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException("{$name} source could not be read.");
    }
}

if (str_contains($dashboard, '->query(') || str_contains($dashboard, '->prepare(')) {
    throw new RuntimeException('Admin dashboard controller contains SQL.');
}
echo "[PASS] keeps SQL outside the admin dashboard controller\n";

if (!str_contains($wallet, "ini_set('display_errors', '0')") || str_contains($wallet, "ini_set('display_errors', '1')")) {
    throw new RuntimeException('Admin wallet exposes PHP errors.');
}
echo "[PASS] hides PHP errors in the admin wallet boundary\n";

if (!str_contains($wallet, 'requireCsrfToken') || substr_count($walletView, 'name="csrf_token"') < 3) {
    throw new RuntimeException('Admin wallet mutations are not consistently protected by CSRF.');
}
echo "[PASS] protects every admin wallet mutation with CSRF\n";

if (str_contains($header, '<style>') || !str_contains($header, '/assets/admin.css')) {
    throw new RuntimeException('Admin layout did not move its design system to a reusable asset.');
}
echo "[PASS] loads the shared admin design system\n";

if (!str_contains($header, 'method="post"') || !str_contains($header, 'name="csrf_token"')) {
    throw new RuntimeException('Admin logout is not a CSRF-protected POST form.');
}
echo "[PASS] keeps admin logout as a protected mutation\n";

if (str_contains($walletView, 'api.qrserver.com') || str_contains($walletView, 'addslashes(')) {
    throw new RuntimeException('Wallet view still leaks addresses or embeds unsafe JavaScript strings.');
}
echo "[PASS] avoids external QR disclosure and unsafe JavaScript interpolation\n";

echo "6 admin UI boundary tests passed.\n";
