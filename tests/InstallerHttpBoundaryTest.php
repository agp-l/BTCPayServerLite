<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$installer = file_get_contents($root . '/install.php');
$frontController = file_get_contents($root . '/index.php');
$apache = file_get_contents($root . '/.htaccess');
$css = file_get_contents($root . '/assets/install.css');

foreach ([$installer, $frontController, $apache, $css] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('An installer boundary file is missing.');
    }
}

$checks = [
    'front controller redirects an unconfigured installation' =>
        str_contains($frontController, "is_file(__DIR__ . '/config.php')")
        && str_contains($frontController, "'/install.php'"),
    'installer validates CSRF before mutation' =>
        str_contains($installer, 'AuthManager::requireCsrfToken'),
    'installer blocks framing and external form submissions' =>
        str_contains($installer, "frame-ancestors 'none'")
        && str_contains($installer, "form-action 'self'"),
    'installer does not repopulate submitted passwords' =>
        !str_contains($installer, "posted('db_pass'")
        && !str_contains($installer, "posted('admin_password'")
        && !str_contains($installer, "posted('rpc_pass'"),
    'Apache denies direct reads of configuration and temporary files' =>
        str_contains($apache, 'config\\.php')
        && str_contains($apache, '\\.btcpay-config-'),
    'installer has a dedicated responsive stylesheet' =>
        str_contains($css, '@media (max-width: 620px)')
        && str_contains($css, '--accent: #a855f7'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        throw new RuntimeException('Failed installer HTTP boundary: ' . $name);
    }
}

echo count($checks) . " installer HTTP boundary tests passed.\n";
