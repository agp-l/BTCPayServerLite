<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'controller' => 'client/index.php',
    'view' => 'client/views/index_view.php',
    'header' => 'client/views/layout/header.php',
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

if (str_contains($sources['header'], '<style>') || !str_contains($sources['header'], '/assets/admin.css')) {
    throw new RuntimeException('Client layout does not use the shared application design system.');
}
echo "[PASS] uses the shared responsive design system\n";

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

echo "7 client UI boundary tests passed.\n";
