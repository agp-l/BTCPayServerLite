<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/UrlManager.php';

use BtcPayLite\UrlManager;

/** @var list<string> $passes */
$passes = [];

function sameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function rejectsUrl(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$server = [
    'REQUEST_URI' => '/BTCPayLite/admin/wallet?hide_empty=1',
    'SCRIPT_NAME' => '/BTCPayLite/index.php',
    'HTTP_HOST' => 'localhost',
];
$url = new UrlManager($server);
sameValue(['admin', 'wallet'], $url->getSegments(), 'Subdirectory route was not parsed');
sameValue('/admin/wallet', $url->getPath(), 'Application path was not normalized');
sameValue('http://localhost/BTCPayLite', $url->getBaseUrl(), 'Base URL is incorrect');
sameValue(
    'http://localhost/BTCPayLite/admin/wallet?hide_empty=1',
    $url->getFullUrl(),
    'Full URL is incorrect'
);
$passes[] = 'parses a subdirectory request without mixing in HTML escaping';

$trustedServer = [
    'REQUEST_URI' => '/BTCPayLite/login',
    'SCRIPT_NAME' => '/BTCPayLite/index.php',
    'HTTP_HOST' => 'attacker.example',
];
$trusted = new UrlManager($trustedServer, 'https://pay.example.com/BTCPayLite/');
sameValue('https://pay.example.com/BTCPayLite', $trusted->getBaseUrl(), 'Configured origin was ignored');
sameValue('https://pay.example.com/BTCPayLite/registrace', $trusted->url('/registrace'), 'URL builder failed');
sameValue(
    'https://pay.example.com/BTCPayLite/invoices?id=inv_1',
    $trusted->url('/invoices', ['id' => 'inv_1']),
    'Query builder failed'
);
$passes[] = 'uses configured app_url instead of an attacker-controlled Host header';

rejectsUrl(
    static fn () => new UrlManager($server, 'https://user:pass@pay.example.com'),
    'Credentials in app_url were accepted'
);
rejectsUrl(
    static fn () => new UrlManager($server, 'https://pay.example.com/?debug=1'),
    'Query string in app_url was accepted'
);
$passes[] = 'rejects ambiguous configured base URLs';

rejectsUrl(
    static fn () => new UrlManager([
        'REQUEST_URI' => '/',
        'SCRIPT_NAME' => '/index.php',
        'HTTP_HOST' => "good.example\r\nX-Injected: yes",
    ]),
    'Injected Host header was accepted'
);
$passes[] = 'rejects an invalid Host header when no app_url is configured';

$decoded = new UrlManager([
    'REQUEST_URI' => '/BTCPayLite/invoice%20history',
    'SCRIPT_NAME' => '/BTCPayLite/index.php',
    'HTTP_HOST' => 'localhost',
]);
sameValue('invoice history', $decoded->getSegment(0), 'Safe percent encoding was not decoded');
rejectsUrl(
    static fn () => new UrlManager([
        'REQUEST_URI' => '/BTCPayLite/admin%2Fwallet',
        'SCRIPT_NAME' => '/BTCPayLite/index.php',
        'HTTP_HOST' => 'localhost',
    ]),
    'Encoded slash was accepted'
);
rejectsUrl(
    static fn () => new UrlManager([
        'REQUEST_URI' => '/BTCPayLite/%2e%2e/config.php',
        'SCRIPT_NAME' => '/BTCPayLite/index.php',
        'HTTP_HOST' => 'localhost',
    ]),
    'Encoded traversal was accepted'
);
$passes[] = 'decodes safe segments and rejects route boundary bypasses';

$externalReferer = new UrlManager($trustedServer + [
    'HTTP_REFERER' => 'https://pay.example.com.evil.test/BTCPayLite/admin',
], 'https://pay.example.com/BTCPayLite/');
sameValue(
    'https://pay.example.com/BTCPayLite/',
    $externalReferer->getBackPage(),
    'Origin-prefix referer bypass was accepted'
);
$internalReferer = new UrlManager($trustedServer + [
    'HTTP_REFERER' => 'https://pay.example.com/BTCPayLite/admin/dashboard',
], 'https://pay.example.com/BTCPayLite/');
sameValue(
    'https://pay.example.com/BTCPayLite/admin/dashboard',
    $internalReferer->getBackPage(),
    'Valid same-origin referer was rejected'
);
$passes[] = 'validates back navigation by parsed origin and base path';

foreach ($passes as $pass) {
    echo '[PASS] ' . $pass . PHP_EOL;
}
echo count($passes) . ' UrlManager tests passed.' . PHP_EOL;
