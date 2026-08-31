<?php

declare(strict_types=1);

$dashboard = file_get_contents(__DIR__ . '/../admin/dashboard.php');
$wallet = file_get_contents(__DIR__ . '/../admin/wallet.php');
$header = file_get_contents(__DIR__ . '/../admin/views/layout/header.php');
$walletView = file_get_contents(__DIR__ . '/../admin/views/wallet_view.php');
$adminCss = file_get_contents(__DIR__ . '/../assets/admin.css');
$dashboardView = file_get_contents(__DIR__ . '/../admin/views/index_view.php');
$storesView = file_get_contents(__DIR__ . '/../admin/views/stores_view.php');
$invoicesView = file_get_contents(__DIR__ . '/../admin/views/invoices_view.php');
$urlInvoices = file_get_contents(__DIR__ . '/../admin/url_invoices.php');
$urlInvoicesView = file_get_contents(__DIR__ . '/../admin/views/url_invoices_view.php');

foreach (compact(
    'dashboard',
    'wallet',
    'header',
    'walletView',
    'adminCss',
    'dashboardView',
    'storesView',
    'invoicesView',
    'urlInvoices',
    'urlInvoicesView'
) as $name => $source) {
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

if (
    !str_contains($adminCss, 'color-scheme: dark')
    || !str_contains($adminCss, '--admin-bg: #090a0f')
    || !str_contains($adminCss, '--admin-accent: #a855f7')
    || str_contains($adminCss, 'color-scheme: light')
) {
    throw new RuntimeException('Admin design system is not consistently dark and purple.');
}
echo "[PASS] uses the dark graphite and purple admin design system\n";

if (!str_contains($adminCss, '1680px') || !str_contains($dashboardView, 'dashboard-grid')) {
    throw new RuntimeException('Admin workspace does not use the wider responsive layout.');
}
echo "[PASS] uses the wider responsive admin workspace\n";

if (!str_contains($storesView, 'management-grid') || !str_contains($invoicesView, 'management-grid')) {
    throw new RuntimeException('Management views did not adopt the shared master-detail layout.');
}
echo "[PASS] shares the management workspace across stores and invoices\n";

if (
    str_contains($urlInvoicesView, 'innerHTML')
    || str_contains($urlInvoicesView, 'onclick=')
    || str_contains($urlInvoicesView, 'onchange=')
    || str_contains($urlInvoicesView, 'style=')
) {
    throw new RuntimeException('Stateless invoice view still performs unsafe inline rendering.');
}
echo "[PASS] renders stateless invoice history without unsafe inline HTML\n";

if (
    !str_contains($urlInvoices, "ini_set('display_errors', '0')")
    || !str_contains($urlInvoices, 'requireCsrfToken')
    || !str_contains($urlInvoicesView, "formData.set('csrf_token'")
) {
    throw new RuntimeException('Stateless invoice admin mutations are not protected at the HTTP boundary.');
}
echo "[PASS] protects stateless invoice admin requests with CSRF\n";

echo "11 admin UI boundary tests passed.\n";
