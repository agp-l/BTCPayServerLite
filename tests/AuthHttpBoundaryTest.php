<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [];
foreach ([
    'client/login.php',
    'client/registrace.php',
    'client/index.php',
    'client/views/login_view.php',
    'client/views/registrace_view.php',
    'client/views/layout/header.php',
    'admin/webhooks.php',
    'admin/dashboard.php',
] as $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    $sources[$path] = $source;
}

$checks = [
    'login validates CSRF' => str_contains(
        $sources['client/login.php'],
        "AuthManager::requireCsrfToken(\$_POST['csrf_token'] ?? null)"
    ),
    'registration validates CSRF' => str_contains(
        $sources['client/registrace.php'],
        "AuthManager::requireCsrfToken(\$_POST['csrf_token'] ?? null)"
    ),
    'client mutations validate CSRF' => str_contains(
        $sources['client/index.php'],
        "AuthManager::requireCsrfToken(\$_POST['csrf_token'] ?? null)"
    ),
    'logout no longer uses GET' => !str_contains(
        $sources['client/login.php'] . $sources['client/views/layout/header.php'],
        "\$_GET['logout']"
    ) && str_contains($sources['client/views/layout/header.php'], 'name="action" value="logout"'),
    'login errors are escaped' => str_contains(
        $sources['client/views/login_view.php'],
        'htmlspecialchars($error'
    ),
    'login and registration hide PHP errors' => !str_contains(
        $sources['client/login.php'] . $sources['client/registrace.php'],
        "ini_set('display_errors', '1')"
    ),
    'admin webhook entry requires admin' => str_contains(
        $sources['admin/webhooks.php'],
        "AuthManager::requireRole('admin'"
    ),
    'admin dashboard entry requires admin' => str_contains(
        $sources['admin/dashboard.php'],
        "AuthManager::requireRole('admin'"
    ),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        throw new RuntimeException('Failed boundary contract: ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

echo count($checks) . ' authentication HTTP boundary tests passed.' . PHP_EOL;
