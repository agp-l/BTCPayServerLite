<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'controller' => 'client/index.php',
    'view' => 'client/views/index_view.php',
    'header' => 'client/views/layout/header.php',
    'footer' => 'client/views/layout/footer.php',
    'css' => 'assets/admin.css',
    'script' => 'assets/admin.js',
    'provisioner' => 'classes/ElectrumCliWalletProvisioner.php',
];
$sources = [];
foreach ($paths as $name => $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    $sources[$name] = $source;
}

if (
    str_contains($sources['controller'], '->prepare(')
    || str_contains($sources['controller'], '->query(')
    || str_contains($sources['controller'], 'shell_exec(')
    || str_contains($sources['controller'], 'proc_open(')
) {
    throw new RuntimeException('Client controller contains persistence or process implementation details.');
}
echo "[PASS] keeps SQL and process execution outside the client controller\n";

$operationPosition = strpos($sources['controller'], "=== 'POST'");
$loadPosition = strpos($sources['controller'], '$service->load($userId)');
if ($operationPosition === false || $loadPosition === false || $loadPosition <= $operationPosition) {
    throw new RuntimeException('Client dashboard does not reload data after processing an action.');
}
echo "[PASS] reloads client data after action processing\n";

if (
    !str_contains($sources['controller'], 'catch (AuthException $exception)')
    || !str_contains($sources['controller'], 'http_response_code(403)')
    || !str_contains($sources['controller'], 'AuthManager::requireCsrfToken')
) {
    throw new RuntimeException('Client mutation boundary does not handle CSRF failures safely.');
}
echo "[PASS] handles client CSRF failures explicitly\n";

if (substr_count($sources['view'], 'name="csrf_token"') < 3) {
    throw new RuntimeException('Client view does not protect every mutation with CSRF.');
}
echo "[PASS] protects every client dashboard mutation with CSRF\n";

if (
    !str_contains($sources['view'], 'name="action" value="rename_store"')
    || !str_contains($sources['view'], 'name="action" value="rotate_store_key"')
    || !str_contains($sources['controller'], '$service->renameStore(')
    || !str_contains($sources['controller'], '$service->rotateStoreApiKey(')
) {
    throw new RuntimeException('Client store name or API key management is missing from the UI boundary.');
}
echo "[PASS] exposes client store name and API key management\n";

if (str_contains($sources['header'], '<style>') || !str_contains($sources['header'], '/assets/admin.css')) {
    throw new RuntimeException('Client layout does not use the shared application design system.');
}
echo "[PASS] uses the shared responsive design system\n";

if (
    str_contains($sources['header'], 'admin-topbar')
    || !str_contains($sources['header'], 'admin-mobile-bar')
    || !str_contains($sources['header'], 'admin-system-status')
    || !str_contains($sources['footer'], '/assets/admin.js')
    || !str_contains($sources['css'], '.data-table.is-responsive td::before')
    || !str_contains($sources['script'], 'cell.dataset.label')
    || str_contains($sources['script'], 'innerHTML')
) {
    throw new RuntimeException('Client shell does not share the mobile navigation and responsive table behavior.');
}
echo "[PASS] shares the streamlined shell and labeled mobile tables\n";

if (
    !str_contains($sources['header'], 'method="post"')
    || !str_contains($sources['header'], 'name="action" value="logout"')
    || !str_contains($sources['header'], 'name="csrf_token"')
) {
    throw new RuntimeException('Client logout is not a protected POST mutation.');
}
echo "[PASS] keeps client logout as a protected mutation\n";

if (
    str_contains($sources['provisioner'], 'shell_exec(')
    || !str_contains($sources['provisioner'], 'proc_open($command')
    || !str_contains($sources['provisioner'], "['bypass_shell' => true]")
    || !str_contains($sources['provisioner'], 'creation timed out')
) {
    throw new RuntimeException('Wallet provisioning process boundary is not hardened.');
}
echo "[PASS] provisions wallets without invoking a command shell\n";

if (
    !str_contains($sources['provisioner'], 'public function discard(string $walletPath): void')
    || !str_contains($sources['provisioner'], 'Refusing to discard a wallet symlink.')
    || !str_contains($sources['provisioner'], "store_[a-f0-9]{32}_wallet")
    || !str_contains($sources['provisioner'], 'dirname($resolvedWallet) !== $resolvedWalletDirectory')
) {
    throw new RuntimeException('Wallet cleanup is not restricted to managed store wallet files.');
}
echo "[PASS] restricts wallet cleanup to managed store wallets\n";

echo "10 client UI boundary tests passed.\n";
