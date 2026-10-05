<?php

declare(strict_types=1);

require __DIR__ . '/support/CoreTestSupport.php';

$binary = getenv('BTCPAY_TEST_APACHE_BINARY');
if ($binary === false || $binary === '') {
    echo "[SKIP] Set BTCPAY_TEST_APACHE_BINARY to test real Apache .htaccess boundaries\n";
    exit(0);
}
coreCheck(is_executable($binary), 'Configured Apache is not executable');
$modules = getenv('BTCPAY_TEST_APACHE_MODULES') ?: dirname(dirname($binary)) . '/lib/apache2/modules';
$dir = coreDirectory();
chmod($dir, 0755);
$documentRoot = $dir . '/htdocs';
mkdir($documentRoot, 0755);
$project = $documentRoot . '/BTCPayLite';
mkdir($project, 0755);
// Never serve the real repository or real configuration without a PHP handler.
copy(dirname(__DIR__) . '/.htaccess', $project . '/.htaccess');
$blocked = [
    'config.php', 'config.php.bak', 'sql.sql', 'composer.json', 'composer.lock',
    'dump.SQL', 'notes.log', 'old.php~', '.git/config', '.env',
    'vendor/autoload.php', 'classes/Database.php', 'tests/fixture.txt',
    'migrations/001.sql', 'docs/README.md', 'var/blockchain/observation.json',
    'var/blockchain/tx-test.hex', 'bin/health_check.php', 'admin/views/layout/header.php',
];
foreach (array_merge($blocked, ['assets/site.css', 'index.php', 'api.php']) as $path) {
    $parent = dirname($project . '/' . $path);
    if (!is_dir($parent)) { mkdir($parent, 0755, true); }
    file_put_contents($project . '/' . $path, $path === 'index.php' ? 'FRONT' : ($path === 'api.php' ? 'API' : 'FIXTURE'));
}
$reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
coreCheck(is_resource($reservation), 'Cannot reserve HTTP port');
$port = (int) substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
fclose($reservation);
$user = function_exists('posix_geteuid') && posix_geteuid() !== 0 ? '#' . posix_geteuid() : 'www-data';
$group = function_exists('posix_getegid') && posix_getegid() !== 0 ? '#' . posix_getegid() : 'www-data';
$configuration = "ServerRoot \"{$dir}\"\nPidFile \"{$dir}/pid\"\nListen 127.0.0.1:{$port}\nServerName localhost\nUser {$user}\nGroup {$group}\n";
foreach (['mpm_event', 'authz_core', 'authz_host', 'rewrite', 'headers'] as $module) {
    $configuration .= "LoadModule {$module}_module \"{$modules}/mod_{$module}.so\"\n";
}
$configuration .= "ErrorLog \"{$dir}/error.log\"\nDocumentRoot \"{$documentRoot}\"\n<Directory \"{$documentRoot}\">\nOptions FollowSymLinks\nAllowOverride All\nRequire all granted\n</Directory>\nHeader always set X-Test-Authorization \"%{HTTP_AUTHORIZATION}e\"\n";
file_put_contents($dir . '/httpd.conf', $configuration);
$pipes = [];
$process = proc_open([$binary, '-f', $dir . '/httpd.conf', '-DFOREGROUND'],
    [0 => ['pipe', 'r'], 1 => ['file', $dir . '/output.log', 'a'], 2 => ['file', $dir . '/output.log', 'a']], $pipes);
coreCheck(is_resource($process), 'Cannot start isolated Apache');
try {
    $ready = false;
    for ($i = 0; $i < 200; ++$i) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($socket !== false) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    coreCheck($ready, 'Apache did not start: ' . file_get_contents($dir . '/output.log'));
    foreach ($blocked as $path) {
        $body = file_get_contents('http://127.0.0.1:' . $port . '/BTCPayLite/' . $path, false,
            stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]));
        coreCheck(str_contains($http_response_header[0] ?? '', '403'), 'Internal file was served: ' . $path);
        coreCheck(!str_contains($body, 'FIXTURE'), 'Protected file content leaked: ' . $path);
    }
    foreach (['assets/site.css' => 'FIXTURE', 'pay?id=test' => 'FRONT', 'dokumentace' => 'FRONT', 'api/v1/health' => 'API'] as $path => $expected) {
        $body = file_get_contents('http://127.0.0.1:' . $port . '/BTCPayLite/' . $path, false,
            stream_context_create(['http' => ['timeout' => 3, 'header' => 'Authorization: token fixture-key']]));
        coreSame($expected, $body, 'Public route/asset broken: ' . $path);
        coreCheck(in_array('X-Test-Authorization: token fixture-key', $http_response_header, true), 'Authorization header lost: ' . $path);
    }
    echo "[PASS] Real Apache denies internal files and keeps subdirectory public routes/assets/auth\n";
} finally {
    proc_terminate($process); fclose($pipes[0]); proc_close($process);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($dir);
}
