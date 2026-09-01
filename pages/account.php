<?php

declare(strict_types=1);

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\PdoUserAccountRepository;
use BtcPayLite\UserAccountService;

$role = is_string($_SESSION['role'] ?? null) ? $_SESSION['role'] : '';
if (!in_array($role, ['admin', 'client'], true)) {
    header('Location: ' . $urlManager->url('/login'), true, 303);
    exit;
}
AuthManager::requireRole($role, $urlManager->url('/login'));

$error = '';
$success = '';
$database = isset($database) && $database instanceof Database
    ? $database
    : new Database(
        $config['db_host'],
        $config['db_name'],
        $config['db_user'],
        $config['db_pass'],
        (int) ($config['db_port'] ?? 3306)
    );
$service = new UserAccountService(new PdoUserAccountRepository($database));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $newVersion = $service->changePassword(
            (int) ($_SESSION['user_id'] ?? 0),
            is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '',
            is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '',
            is_string($_POST['new_password_confirm'] ?? null) ? $_POST['new_password_confirm'] : ''
        );
        $_SESSION['session_version'] = $newVersion;
        $_SESSION['auth_issued_at'] = time();
        $_SESSION['auth_last_activity'] = time();
        if (!session_regenerate_id(true)) {
            (new AuthManager($database))->logout();
            header('Location: ' . $urlManager->url('/login'), true, 303);
            exit;
        }
        $success = 'Heslo bylo změněno. Ostatní přihlášené relace byly ukončeny.';
    } catch (AuthException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Account password change failed: ' . $exception::class);
        $error = 'Heslo nyní nelze změnit. Zkuste to prosím později.';
    }
}

$csrfToken = AuthManager::csrfToken();
$backPath = $role === 'admin' ? '/admin/dashboard' : '/client';
require __DIR__ . '/account_view.php';
