<?php

declare(strict_types=1);

use BtcPayLite\AdminOperationsException;
use BtcPayLite\AdminOperationsFactory;
use BtcPayLite\AdminOperationsService;
use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';
$urlManager = new UrlManager(
    $_SERVER,
    is_string($config['app_url'] ?? null) ? $config['app_url'] : null
);
AuthManager::requireRole('admin', $urlManager->url('/login'));

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$csrfToken = AuthManager::csrfToken();
$toastMsg = '';
$pageError = null;
$stores = [];
$service = null;

try {
    $service = AdminOperationsFactory::fromConfig($config);
} catch (Throwable $exception) {
    error_log(sprintf('Admin stores initialization failed: %s (%s)', $exception->getMessage(), $exception::class));
    http_response_code(500);
    $pageError = 'Správa obchodů nyní není dostupná.';
}

if ($service instanceof AdminOperationsService && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action !== 'create') {
            throw new AdminOperationsException('Neznámá operace správy obchodů.');
        }

        $name = is_string($_POST['store_name'] ?? null) ? $_POST['store_name'] : '';
        $service->createStore($name);
        $toastMsg = 'Obchod a jeho peněženka byly úspěšně vytvořeny.';
    } catch (AuthException $exception) {
        http_response_code(403);
        $pageError = 'Platnost formuláře vypršela. Obnovte stránku a zkuste akci znovu.';
        error_log('Admin stores CSRF validation failed.');
    } catch (AdminOperationsException $exception) {
        http_response_code($exception->getHttpStatus());
        $pageError = $exception->getMessage();
        error_log('Admin store operation failed: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()));
    } catch (Throwable $exception) {
        http_response_code(500);
        $pageError = 'Operaci se nyní nepodařilo dokončit.';
        error_log(sprintf('Unexpected admin store failure: %s (%s)', $exception->getMessage(), $exception::class));
    }
}

if ($service instanceof AdminOperationsService) {
    try {
        $stores = $service->stores();
    } catch (Throwable $exception) {
        error_log(sprintf('Admin stores data load failed: %s (%s)', $exception->getMessage(), $exception::class));
        http_response_code(500);
        $pageError = 'Seznam obchodů nyní není dostupný.';
    }
}

require __DIR__ . '/views/stores_view.php';
