<?php

declare(strict_types=1);

use BtcPayLite\AdminUserService;
use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\PdoAdminUserRepository;

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
$service = new AdminUserService(
    new PdoAdminUserRepository($database),
    static function (string $walletPath) use ($config): array {
        $wallet = new ElectrumWallet(new ElectrumRPC(
            $config['rpc_host'] ?? '',
            (int) ($config['rpc_port'] ?? 0),
            is_string($config['rpc_user'] ?? null) ? $config['rpc_user'] : null,
            is_string($config['rpc_pass'] ?? null) ? $config['rpc_pass'] : null
        ));
        $wallet->loadWallet($walletPath);
        return $wallet->getWalletBalance();
    }
);

$pageError = null;
$toastMsg = '';
$selectedUserId = is_string($_GET['user_id'] ?? null) && ctype_digit($_GET['user_id'])
    ? (int) $_GET['user_id']
    : 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $selectedUserId = is_string($_POST['user_id'] ?? null) && ctype_digit($_POST['user_id'])
            ? (int) $_POST['user_id']
            : 0;
        if ($action === 'set_status') {
            $service->setStatus(
                $selectedUserId,
                is_string($_POST['status'] ?? null) ? $_POST['status'] : ''
            );
            $toastMsg = 'Stav klienta byl změněn a jeho starší relace ukončeny.';
        } elseif ($action === 'adopt_wallet') {
            $service->adoptSingleWallet($selectedUserId);
            $toastMsg = 'Jediná historická peněženka byla přiřazena klientovi.';
        } elseif ($action === 'set_wallet') {
            $service->setWallet(
                $selectedUserId,
                is_string($_POST['wallet_path'] ?? null) ? $_POST['wallet_path'] : ''
            );
            $toastMsg = 'Hlavní peněženka klienta byla změněna.';
        } else {
            throw new AuthException('Neplatná akce formuláře.');
        }
    } catch (AuthException $exception) {
        $pageError = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Admin client status update failed: ' . $exception::class);
        $pageError = 'Stav klienta nyní nelze změnit.';
    }
}

$clients = [];
$detail = null;
try {
    $clients = $service->listClients();
    if ($selectedUserId > 0) {
        $detail = $service->detail($selectedUserId);
    }
} catch (Throwable $exception) {
    error_log('Admin user overview failed: ' . $exception::class);
    $pageError = 'Přehled klientů nyní nelze načíst.';
}
$csrfToken = AuthManager::csrfToken();
require __DIR__ . '/views/users_view.php';
