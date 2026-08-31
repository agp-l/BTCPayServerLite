<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/RouterException.php';
require_once __DIR__ . '/../classes/ApplicationRoute.php';
require_once __DIR__ . '/../classes/ApplicationRouter.php';

use BtcPayLite\ApplicationRouter;
use BtcPayLite\RouterException;

/** @var list<string> $passes */
$passes = [];
$router = new ApplicationRouter();

function routeSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

$home = $router->match('/', 'GET');
routeSame('pages/prezentace.php', $home->getHandler(), 'Home handler mismatch');
routeSame('home', $home->getMenu(), 'Home menu mismatch');
$passes[] = 'matches an exact public route';

$admin = $router->match('/admin/wallet', 'POST');
routeSame('admin/wallet.php', $admin->getHandler(), 'Admin handler mismatch');
routeSame('admin', $admin->getRequiredRole(), 'Admin role is missing');
routeSame('wallet', $admin->getMenu(), 'Admin menu mismatch');
$passes[] = 'describes handler role and active menu in one route';

$alias = $router->match('/dashboard', 'GET');
routeSame(true, $alias->isRedirect(), 'Dashboard alias is not a redirect');
routeSame('/client', $alias->getRedirectPath(), 'Dashboard redirect target mismatch');
$passes[] = 'uses explicit canonical redirects for legacy aliases';

$registrationAlias = $router->match('/registrace.php', 'GET');
routeSame(true, $registrationAlias->isRedirect(), 'Legacy registration path is not a redirect');
routeSame('/registrace', $registrationAlias->getRedirectPath(), 'Registration redirect target mismatch');
$passes[] = 'redirects the removed registration entry point';

try {
    $router->match('/admin/wallet/extra', 'GET');
    throw new RuntimeException('Unknown nested route was accepted');
} catch (RouterException $exception) {
    routeSame(404, $exception->getHttpStatus(), 'Unknown route status mismatch');
}
$passes[] = 'returns a real 404 for unknown and overlong paths';

try {
    $router->match('/api', 'GET');
    throw new RuntimeException('Wrong API method was accepted');
} catch (RouterException $exception) {
    routeSame(405, $exception->getHttpStatus(), 'Wrong method status mismatch');
    routeSame(['POST'], $exception->getAllowedMethods(), 'Allow methods mismatch');
}
$passes[] = 'returns 405 with an exact Allow contract';

$head = $router->match('/admin/dashboard', 'HEAD');
routeSame('admin/dashboard.php', $head->getHandler(), 'HEAD did not reuse the GET page');
$passes[] = 'supports HEAD on read-only pages';

$handlerCases = [
    ['/', 'GET'],
    ['/login', 'GET'],
    ['/registrace', 'GET'],
    ['/client', 'GET'],
    ['/api', 'POST'],
    ['/pay', 'GET'],
    ['/admin/dashboard', 'GET'],
    ['/admin/wallet', 'GET'],
    ['/admin/stores', 'GET'],
    ['/admin/invoices', 'GET'],
    ['/admin/webhooks', 'GET'],
    ['/admin/url_invoices', 'GET'],
];
foreach ($handlerCases as [$path, $method]) {
    $handler = $router->match($path, $method)->getHandler();
    if ($handler === null || !is_file(dirname(__DIR__) . '/' . $handler)) {
        throw new RuntimeException('Route handler does not exist: ' . $path);
    }
}
$passes[] = 'maps every executable route to an existing handler';

foreach ($passes as $pass) {
    echo '[PASS] ' . $pass . PHP_EOL;
}
echo count($passes) . ' application router tests passed.' . PHP_EOL;
