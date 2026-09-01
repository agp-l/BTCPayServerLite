<?php

declare(strict_types=1);

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\PdoUserAccountRepository;
use BtcPayLite\UrlManager;
use BtcPayLite\UserAccountService;

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';
AuthManager::startSession();
AuthManager::sendPrivateResponseHeaders();
$urlManager = isset($urlManager) && $urlManager instanceof UrlManager
    ? $urlManager
    : new UrlManager($_SERVER, is_string($config['app_url'] ?? null) ? $config['app_url'] : null);

$error = '';
$success = false;
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $database = new Database(
            $config['db_host'],
            $config['db_name'],
            $config['db_user'],
            $config['db_pass'],
            (int) ($config['db_port'] ?? 3306)
        );
        (new UserAccountService(new PdoUserAccountRepository($database)))->resetPassword(
            $token,
            is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
            is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : ''
        );
        $success = true;
        $token = '';
    } catch (AuthException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Password reset failed: ' . $exception::class);
        $error = 'Heslo nyní nelze změnit. Zkuste to prosím později.';
    }
}

$csrfToken = AuthManager::csrfToken();
require __DIR__ . '/views/reset_password_view.php';
