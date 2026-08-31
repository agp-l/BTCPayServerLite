<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'client/login.php',
    'client/registrace.php',
    'client/index.php',
    'client/views/login_view.php',
    'client/views/registrace_view.php',
    'client/views/layout/header.php',
    'admin/views/layout/header.php',
    'pages/prezentace.php',
    'api_stateless.php',
];

/** @var array<string,string> $sources */
$sources = [];
foreach ($paths as $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read routing source: ' . $path);
    }
    $sources[$path] = $source;
}

$clientControllers = $sources['client/login.php']
    . $sources['client/registrace.php']
    . $sources['client/index.php'];
$clientViews = $sources['client/views/login_view.php']
    . $sources['client/views/registrace_view.php']
    . $sources['client/views/layout/header.php'];
$adminHeader = $sources['admin/views/layout/header.php'];

$checks = [
    'client controllers receive trusted URL context' => substr_count(
        $clientControllers,
        "is_string(\$config['app_url'] ?? null) ? \$config['app_url'] : null"
    ) === 3,
    'login redirects use the URL builder' => substr_count(
        $sources['client/login.php'],
        '$urlManager->url('
    ) >= 2,
    'client views use the URL builder' => substr_count($clientViews, '$urlManager->url(') >= 5,
    'admin header uses one route URL closure' => str_contains($adminHeader, '$routeUrl = static fn')
        && substr_count($adminHeader, '$routeUrl(') >= 10,
    'legacy admin path prefixes are gone' => !str_contains($adminHeader, '$adminPrefix')
        && !str_contains($adminHeader, '$rootPrefix'),
    'landing and stateless API honor app_url' => str_contains(
        $sources['pages/prezentace.php'],
        "is_string(\$config['app_url'] ?? null)"
    ) && str_contains(
        $sources['api_stateless.php'],
        "is_string(\$config['app_url'] ?? null)"
    ),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        throw new RuntimeException('Failed route link contract: ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

echo count($checks) . ' route link boundary tests passed.' . PHP_EOL;
