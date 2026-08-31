<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;

AuthManager::startSession();
AuthManager::sendPrivateResponseHeaders();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'login';
        $db = new Database(
            $config['db_host'],
            $config['db_name'],
            $config['db_user'],
            $config['db_pass'],
            (int) ($config['db_port'] ?? 3306)
        );
        $auth = new AuthManager($db);

        if ($action === 'logout') {
            $auth->logout();
            header('Location: login', true, 303);
            exit;
        }
        if ($action !== 'login') {
            throw new AuthException('Neplatná akce formuláře.');
        }

        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $clientIdentity = is_string($_SERVER['REMOTE_ADDR'] ?? null)
            ? $_SERVER['REMOTE_ADDR']
            : '';
        $user = $auth->login($email, $password, $clientIdentity);

        header('Location: ' . ($user['role'] === 'admin' ? 'admin/dashboard' : 'client'), true, 303);
        exit;
    } catch (AuthException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Unexpected login failure: ' . $exception->getMessage());
        $error = 'Došlo k interní systémové chybě. Zkuste to prosím později.';
    }
}

$csrfToken = AuthManager::csrfToken();
require __DIR__ . '/views/login_view.php';
