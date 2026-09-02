<?php

declare(strict_types=1);

$dashboard = file_get_contents(__DIR__ . '/../admin/dashboard.php');
$wallet = file_get_contents(__DIR__ . '/../admin/wallet.php');
$header = file_get_contents(__DIR__ . '/../admin/views/layout/header.php');
$footer = file_get_contents(__DIR__ . '/../admin/views/layout/footer.php');
$walletView = file_get_contents(__DIR__ . '/../admin/views/wallet_view.php');
$adminCss = file_get_contents(__DIR__ . '/../assets/admin.css');
$adminJs = file_get_contents(__DIR__ . '/../assets/admin.js');
$dashboardView = file_get_contents(__DIR__ . '/../admin/views/index_view.php');
$storesView = file_get_contents(__DIR__ . '/../admin/views/stores_view.php');
$invoicesView = file_get_contents(__DIR__ . '/../admin/views/invoices_view.php');
$urlInvoices = file_get_contents(__DIR__ . '/../admin/url_invoices.php');
$urlInvoicesView = file_get_contents(__DIR__ . '/../admin/views/url_invoices_view.php');
$urlInvoicesScript = file_get_contents(__DIR__ . '/../assets/url-invoices.js');

foreach (compact(
    'dashboard',
    'wallet',
    'header',
    'footer',
    'walletView',
    'adminCss',
    'adminJs',
    'dashboardView',
    'storesView',
    'invoicesView',
    'urlInvoices',
    'urlInvoicesView',
    'urlInvoicesScript'
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
    !str_contains($adminCss, 'color-scheme: light')
    || !str_contains($adminCss, '--surface-page: #f3f2f154')
    || !str_contains($adminCss, '--surface-card: #ffffff')
    || !str_contains($adminCss, '--brand-primary: #20c875')
    || !str_contains($adminCss, '--text-primary: #14171a')
    || str_contains($adminCss, '#1417 1a')
    || str_contains($adminCss, ';;')
) {
    throw new RuntimeException('Admin design tokens are invalid or no longer match the accepted palette.');
}
echo "[PASS] keeps the accepted palette in valid centralized design tokens\n";

preg_match_all('/(--[a-z0-9-]+)\\s*:/i', $adminCss, $definedTokenMatches);
preg_match_all('/var\\((--[a-z0-9-]+)/i', $adminCss, $usedTokenMatches);
$missingTokens = array_values(array_diff(
    array_unique($usedTokenMatches[1]),
    array_unique($definedTokenMatches[1])
));
if ($missingTokens !== []) {
    throw new RuntimeException('Admin CSS references undefined tokens: ' . implode(', ', $missingTokens));
}
echo "[PASS] resolves every referenced design token\n";

if (
    !str_contains($adminCss, '.card-title-group i,')
    || !str_contains($adminCss, 'background: var(--surface-subtle)')
    || !str_contains($adminCss, 'color: var(--brand-primary) !important')
    || !str_contains($adminCss, 'border: 1px solid var(--border-strong)')
) {
    throw new RuntimeException('Admin icons or form controls lost their accepted visual treatment.');
}
echo "[PASS] keeps green icons on subtle surfaces and visible form borders\n";

if (
    !str_contains($adminCss, '--shadow-card: 0 0.125rem 0.625rem rgba(90, 97, 105, 0.1)')
    || !str_contains($adminCss, '--radius-card: 0.5rem')
    || !str_contains($adminCss, 'box-shadow: var(--shadow-card)')
    || !str_contains($adminCss, 'border: 0')
    || str_contains($adminCss, 'gradient(')
    || str_contains($adminCss, 'backdrop-filter')
) {
    throw new RuntimeException('Admin cards do not use the accepted clean elevated style.');
}
echo "[PASS] keeps borderless elevated cards behind shared tokens\n";

if (
    !str_contains($adminCss, '.admin-nav-link.is-active::before')
    || !str_contains($adminCss, '--sidebar-background: #ffffff')
    || !str_contains($adminCss, '--sidebar-text: #030303')
    || !str_contains($adminCss, 'font-weight: 200')
) {
    throw new RuntimeException('Admin navigation no longer matches the accepted light, regular-weight treatment.');
}
echo "[PASS] keeps the accepted light and regular-weight navigation\n";

if (
    !str_contains($adminCss, '--font-ui: Inter')
    || !str_contains($adminCss, '--font-mono:')
    || !str_contains($adminCss, 'font-family: var(--font-ui)')
    || !str_contains($adminCss, 'font-family: var(--font-mono)')
    || !str_contains($adminCss, '.card-title-group i')
) {
    throw new RuntimeException('Admin typography or icon hierarchy is incomplete.');
}
echo "[PASS] centralizes typography and preserves the icon hierarchy\n";

if (
    !str_contains($adminCss, '--status-success: #20c875')
    || !str_contains($adminCss, '--status-danger: #b23a3a')
    || !str_contains($adminCss, '.transaction-amount.incoming { color: var(--status-success); }')
    || !str_contains($adminCss, '.transaction-amount.outgoing')
    || !str_contains($adminCss, '.address-funds.has-utxo')
    || !str_contains($walletView, 'transaction-item <?php echo $direction; ?>')
    || !str_contains($walletView, 'address-row <?php echo $address[\'hasFunds\']')
    || !str_contains($walletView, 'class="address-funds <?php echo')
    || substr_count($walletView, 'class="ghost-btn icon-btn"') < 3
    || str_contains($walletView, '> Kopírovat</button>')
) {
    throw new RuntimeException('Wallet identity, payment direction colors or icon-only copy controls are incomplete.');
}
echo "[PASS] keeps semantic wallet colors and icon-only copy controls\n";

if (
    str_contains($header, 'admin-topbar')
    || !str_contains($header, 'admin-mobile-bar')
    || !str_contains($header, 'admin-system-status')
    || !str_contains($footer, '/assets/admin.js')
    || str_contains($footer, '<script>')
) {
    throw new RuntimeException('Admin shell still contains the desktop top bar or duplicated inline behavior.');
}
echo "[PASS] moves system status into the sidebar and keeps only mobile navigation\n";

if (
    !str_contains($adminCss, '.data-table.is-responsive td::before')
    || !str_contains($adminCss, 'content: attr(data-label)')
    || !str_contains($adminJs, 'cell.dataset.label')
    || !str_contains($adminJs, "table.classList.add('is-responsive')")
    || str_contains($adminJs, 'innerHTML')
) {
    throw new RuntimeException('Responsive tables are missing safe header-to-card labeling.');
}
echo "[PASS] converts long tables into labeled mobile rows\n";

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
    || preg_match('/<script>(.|\\n)*<\\/script>/', $urlInvoicesView)
) {
    throw new RuntimeException('Stateless invoice view still performs unsafe inline rendering.');
}
echo "[PASS] renders stateless invoice history without unsafe inline HTML\n";

if (
    !str_contains($urlInvoices, "ini_set('display_errors', '0')")
    || !str_contains($urlInvoices, 'requireCsrfToken')
    || !str_contains($urlInvoicesView, 'data-csrf-token')
    || !str_contains($urlInvoicesScript, "formData.set('csrf_token'")
) {
    throw new RuntimeException('Stateless invoice admin mutations are not protected at the HTTP boundary.');
}
echo "[PASS] protects stateless invoice admin requests with CSRF\n";

echo "19 admin UI boundary tests passed.\n";
