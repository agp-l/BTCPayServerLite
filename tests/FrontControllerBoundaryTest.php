<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$errorPage = file_get_contents($root . '/pages/error.php');
$statelessApi = file_get_contents($root . '/api_stateless.php');
$landingPage = file_get_contents($root . '/pages/prezentace.php');

foreach ([$index, $errorPage, $statelessApi, $landingPage] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('A routing boundary source could not be read.');
    }
}

$checks = [
    'front controller delegates route matching' => str_contains(
        $index,
        '(new ApplicationRouter())->match($urlManager->getPath(), $requestMethod)'
    ),
    'front controller uses configured app_url' => str_contains(
        $index,
        "is_string(\$config['app_url'] ?? null)"
    ),
    'front controller validates handler containment' => str_contains(
        $index,
        '!str_starts_with($handlerPath, $root . DIRECTORY_SEPARATOR)'
    ),
    'front controller returns structured HTTP errors' => str_contains($index, 'http_response_code($errorStatus)')
        && str_contains($index, "header('Allow: '"),
    'front controller hides PHP errors' => str_contains($index, "ini_set('display_errors', '0')"),
    'error page escapes dynamic output' => substr_count($errorPage, 'htmlspecialchars(') >= 4,
    'stateless API uses trusted URL context' => str_contains(
        $statelessApi,
        "is_string(\$config['app_url'] ?? null) ? \$config['app_url'] : null"
    ),
    'landing page reuses injected URL context' => str_contains(
        $landingPage,
        '!isset($urlManager) || !$urlManager instanceof UrlManager'
    ),
    'legacy switch router is gone' => !str_contains($index, 'switch ($segment'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        throw new RuntimeException('Failed routing boundary contract: ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

echo count($checks) . ' front controller boundary tests passed.' . PHP_EOL;
