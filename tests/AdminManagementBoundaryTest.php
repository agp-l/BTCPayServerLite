<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [];
foreach ([
    'stores_controller' => 'admin/stores.php',
    'webhooks_controller' => 'admin/webhooks.php',
    'invoices_controller' => 'admin/invoices.php',
    'stores_view' => 'admin/views/stores_view.php',
    'webhooks_view' => 'admin/views/webhooks_view.php',
    'invoices_view' => 'admin/views/invoices_view.php',
] as $name => $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    $sources[$name] = $source;
}

$controllers = $sources['stores_controller'] . $sources['webhooks_controller'] . $sources['invoices_controller'];
$views = $sources['stores_view'] . $sources['webhooks_view'] . $sources['invoices_view'];

if (str_contains($controllers, '->prepare(') || str_contains($controllers, '->query(')) {
    throw new RuntimeException('Admin management controllers contain SQL.');
}
echo "[PASS] keeps SQL outside admin management controllers\n";

if (substr_count($controllers, 'AuthManager::requireCsrfToken') !== 3) {
    throw new RuntimeException('Admin management controllers do not consistently validate CSRF.');
}
echo "[PASS] validates CSRF in every admin management controller\n";

if (
    substr_count($sources['stores_view'], 'name="csrf_token"') < 1
    || substr_count($sources['webhooks_view'], 'name="csrf_token"') < 2
    || substr_count($sources['invoices_view'], 'name="csrf_token"') < 2
) {
    throw new RuntimeException('Admin management forms are missing CSRF tokens.');
}
echo "[PASS] protects every admin management form with CSRF\n";

if (str_contains($views, '<style>') || str_contains($views, ' style=')) {
    throw new RuntimeException('Admin management views contain inline styling.');
}
echo "[PASS] uses the shared design system without inline styles\n";

if (str_contains($views, 'addslashes(') || str_contains($views, 'toastMsg.innerHTML')) {
    throw new RuntimeException('Admin management views contain unsafe JavaScript interpolation.');
}
echo "[PASS] uses safe JSON and text-only toast messages\n";

if (str_contains($sources['stores_view'], 'name="wallet_path"')) {
    throw new RuntimeException('Admin store form accepts an arbitrary server wallet path.');
}
echo "[PASS] removes arbitrary wallet paths from the admin form\n";

if (str_contains($sources['invoices_controller'], '(float)') || !str_contains($sources['invoices_controller'], "url('/checkout/pay.php'")) {
    throw new RuntimeException('Admin invoice boundary does not preserve exact amounts or trusted URLs.');
}
echo "[PASS] keeps exact invoice amounts and trusted checkout URLs\n";

echo "7 admin management boundary tests passed.\n";
