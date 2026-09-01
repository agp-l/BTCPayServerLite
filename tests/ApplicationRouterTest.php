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

$forgotPassword = $router->match('/forgot-password', 'POST');
routeSame('client/forgot_password.php', $forgotPassword->getHandler(), 'Forgot password handler mismatch');
routeSame(null, $forgotPassword->getRequiredRole(), 'Forgot password route must remain public');
$clientAccount = $router->match('/client/account', 'POST');
routeSame('client', $clientAccount->getRequiredRole(), 'Client account role is missing');
$adminSettings = $router->match('/admin/settings', 'POST');
routeSame('admin', $adminSettings->getRequiredRole(), 'Admin settings role is missing');
$adminUsers = $router->match('/admin/users', 'POST');
routeSame('admin', $adminUsers->getRequiredRole(), 'Admin users role is missing');
$passes[] = 'protects account settings while keeping password recovery public';

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

try {
    $router->match('/pay', 'POST');
    throw new RuntimeException('Checkout accepted a mutating method');
} catch (RouterException $exception) {
    routeSame(405, $exception->getHttpStatus(), 'Checkout method status mismatch');
    routeSame(['GET', 'HEAD'], $exception->getAllowedMethods(), 'Checkout Allow methods mismatch');
}
$passes[] = 'keeps the public checkout read-only';

$statelessCheckout = $router->match('/url-invoice', 'GET');
routeSame('admin/url_pay.php', $statelessCheckout->getHandler(), 'Stateless checkout handler mismatch');
routeSame(null, $statelessCheckout->getRequiredRole(), 'Stateless checkout must remain public');
$passes[] = 'exposes signed stateless invoices without an admin session';

$statelessApi = $router->match('/api/stateless/invoices', 'POST');
routeSame('api_stateless.php', $statelessApi->getHandler(), 'Canonical stateless API mismatch');
$passes[] = 'maps the canonical stateless API endpoint';

$head = $router->match('/admin/dashboard', 'HEAD');
routeSame('admin/dashboard.php', $head->getHandler(), 'HEAD did not reuse the GET page');
$passes[] = 'supports HEAD on read-only pages';

$handlerCases = [
    ['/', 'GET'],
    ['/login', 'GET'],
    ['/forgot-password', 'GET'],
    ['/reset-password', 'GET'],
    ['/registrace', 'GET'],
    ['/client', 'GET'],
    ['/client/account', 'GET'],
    ['/api', 'POST'],
    ['/pay', 'GET'],
    ['/url-invoice', 'GET'],
    ['/url_pay', 'GET'],
    ['/admin/url_pay', 'GET'],
    ['/api/stateless/invoices', 'POST'],
    ['/admin/dashboard', 'GET'],
    ['/admin/account', 'GET'],
    ['/admin/settings', 'GET'],
    ['/admin/users', 'GET'],
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
