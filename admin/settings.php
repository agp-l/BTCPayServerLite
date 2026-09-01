<?php

declare(strict_types=1);

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\PdoUserAccountRepository;
use BtcPayLite\UserAccountService;

AuthManager::requireRole('admin', $urlManager->url('/login'));
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
$pageError = null;
$toastMsg = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        if (($_POST['action'] ?? null) !== 'set_registration') {
            throw new AuthException('Neplatná akce formuláře.');
        }
        $service->setRegistrationEnabled(
            ($_POST['registration_enabled'] ?? '') === '1',
            (int) ($_SESSION['user_id'] ?? 0)
        );
        $toastMsg = 'Nastavení registrací bylo uloženo.';
    } catch (AuthException $exception) {
        $pageError = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Admin settings update failed: ' . $exception::class);
        $pageError = 'Nastavení nyní nelze uložit.';
    }
}

$registrationEnabled = $service->isRegistrationEnabled();
$csrfToken = AuthManager::csrfToken();
require __DIR__ . '/views/settings_view.php';
