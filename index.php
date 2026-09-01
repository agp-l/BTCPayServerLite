<?php

declare(strict_types=1);

use BtcPayLite\ApplicationRouter;
use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\PdoUserAccountRepository;
use BtcPayLite\RouterException;
use BtcPayLite\UserAccountService;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

$urlManager = null;
$requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null)
    ? strtoupper($_SERVER['REQUEST_METHOD'])
    : '';

try {
    $config = require __DIR__ . '/config.php';
    if (!is_array($config)) {
        throw new RuntimeException('Application configuration must be an array.');
    }

    AuthManager::startSession();

    $configuredBaseUrl = is_string($config['app_url'] ?? null)
        ? $config['app_url']
        : null;
    $urlManager = new UrlManager($_SERVER, $configuredBaseUrl);
    if ($requestMethod === '') {
        throw new RouterException('Požadavek nemá platnou HTTP metodu.', 400);
    }

    $route = (new ApplicationRouter())->match($urlManager->getPath(), $requestMethod);
    if ($route->isRedirect()) {
        header('Location: ' . $urlManager->url((string) $route->getRedirectPath()), true, 308);
        exit;
    }

    $requiredRole = $route->getRequiredRole();
    if ($requiredRole !== null) {
        AuthManager::requireRole($requiredRole, $urlManager->url('/login'));
        $database = new Database(
            $config['db_host'],
            $config['db_name'],
            $config['db_user'],
            $config['db_pass'],
            (int) ($config['db_port'] ?? 3306)
        );
        $accounts = new UserAccountService(new PdoUserAccountRepository($database));
        try {
            $recordedAt = $accounts->validateSession(
                (int) ($_SESSION['user_id'] ?? 0),
                is_string($_SESSION['role'] ?? null) ? $_SESSION['role'] : '',
                (int) ($_SESSION['session_version'] ?? 0),
                is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null,
                (int) ($_SESSION['auth_seen_recorded_at'] ?? 0)
            );
            $_SESSION['auth_seen_recorded_at'] = $recordedAt;
        } catch (AuthException $exception) {
            (new AuthManager($database))->logout();
            header('Location: ' . $urlManager->url('/login'), true, 303);
            exit;
        }
    }

    $handler = $route->getHandler();
    if ($handler === null) {
        throw new RuntimeException('Matched route has no handler.');
    }
    $root = realpath(__DIR__);
    $handlerPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . $handler);
    if ($root === false
        || $handlerPath === false
        || !str_starts_with($handlerPath, $root . DIRECTORY_SEPARATOR)
        || !is_file($handlerPath)
    ) {
        throw new RuntimeException('Matched route handler is unavailable.');
    }

    $activeMenu = $route->getMenu();
    if ($requestMethod === 'HEAD') {
        ob_start();
        require $handlerPath;
        ob_end_clean();
        exit;
    }

    require $handlerPath;
} catch (RouterException $exception) {
    $errorStatus = $exception->getHttpStatus();
    if ($errorStatus === 405 && $exception->getAllowedMethods() !== []) {
        header('Allow: ' . implode(', ', $exception->getAllowedMethods()));
    }
    http_response_code($errorStatus);
    header('Cache-Control: no-store');
    $errorTitle = $errorStatus === 404
        ? 'Stránka nebyla nalezena'
        : ($errorStatus === 405 ? 'Nepovolená metoda' : 'Neplatný požadavek');
    $errorMessage = $exception->getMessage();
    $homeUrl = $urlManager instanceof UrlManager ? $urlManager->url('/') : '/';
    if ($requestMethod !== 'HEAD') {
        require __DIR__ . '/pages/error.php';
    }
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    header('Cache-Control: no-store');
    $errorStatus = 400;
    $errorTitle = 'Neplatná adresa';
    $errorMessage = 'Požadovaná URL není platná.';
    $homeUrl = $urlManager instanceof UrlManager ? $urlManager->url('/') : '/';
    if ($requestMethod !== 'HEAD') {
        require __DIR__ . '/pages/error.php';
    }
} catch (Throwable $exception) {
    error_log('Front controller failed: ' . $exception->getMessage());
    http_response_code(500);
    header('Cache-Control: no-store');
    $errorStatus = 500;
    $errorTitle = 'Interní chyba';
    $errorMessage = 'Požadavek nyní nelze dokončit. Zkuste to prosím později.';
    $homeUrl = $urlManager instanceof UrlManager ? $urlManager->url('/') : '/';
    if ($requestMethod !== 'HEAD') {
        require __DIR__ . '/pages/error.php';
    }
}
