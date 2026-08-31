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
$successMsg = '';
$email = '';
$walletPath = null;
$walletCreated = false;
$db = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['password_confirm'] ?? null)
            ? $_POST['password_confirm']
            : '';

        $db = new Database(
            $config['db_host'],
            $config['db_name'],
            $config['db_user'],
            $config['db_pass'],
            (int) ($config['db_port'] ?? 3306)
        );
        $auth = new AuthManager($db);
        $db->getPdo()->beginTransaction();

        $clientIdentity = is_string($_SERVER['REMOTE_ADDR'] ?? null)
            ? $_SERVER['REMOTE_ADDR']
            : '';
        $userId = $auth->registerUser($email, $password, $passwordConfirm, $clientIdentity);
        $storeId = 'store_' . bin2hex(random_bytes(16));
        $apiKey = 'sk_' . bin2hex(random_bytes(32));
        $walletDirectory = rtrim(
            (string) ($config['wallet_directory'] ?? '/opt/btcpay_wallets'),
            DIRECTORY_SEPARATOR
        );
        if ($walletDirectory === '' || !is_dir($walletDirectory) || !is_writable($walletDirectory)) {
            throw new RuntimeException('Wallet directory is unavailable.');
        }
        $walletPath = $walletDirectory . DIRECTORY_SEPARATOR . $storeId . '_wallet';
        $electrumCli = (string) ($config['electrum_cli'] ?? '/opt/electrum/run_electrum');
        $electrumData = (string) ($config['electrum_data_directory'] ?? '/opt/electrum_config');
        $command = 'timeout --signal=KILL 30s python3 ' . escapeshellarg($electrumCli)
            . ' -D ' . escapeshellarg($electrumData)
            . ' create --offline -w ' . escapeshellarg($walletPath)
            . ' > /dev/null 2>&1';
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);
        $walletCreated = is_file($walletPath);
        if ($exitCode !== 0 || !$walletCreated) {
            throw new RuntimeException('Wallet creation failed.');
        }
        if (!chmod($walletPath, (int) ($config['wallet_file_mode'] ?? 0660))) {
            throw new RuntimeException('Wallet permissions could not be set.');
        }

        $statement = $db->getPdo()->prepare(
            'INSERT INTO stores (id, name, api_key, wallet_path, user_id) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$storeId, 'Můj první e-shop', $apiKey, $walletPath, $userId]);
        $db->getPdo()->commit();

        $successMsg = 'Registrace proběhla úspěšně! Nyní se můžete přihlásit.';
        $email = '';
        $walletPath = null;
        $walletCreated = false;
    } catch (AuthException $exception) {
        if ($db instanceof Database && $db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        if ($walletCreated && is_string($walletPath) && is_file($walletPath)) {
            unlink($walletPath);
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($db instanceof Database && $db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        if ($walletCreated && is_string($walletPath) && is_file($walletPath)) {
            unlink($walletPath);
        }
        error_log('Unexpected registration failure: ' . $exception->getMessage());
        $error = 'Došlo k interní systémové chybě. Zkuste to prosím později.';
    }
}

$csrfToken = AuthManager::csrfToken();
require __DIR__ . '/views/registrace_view.php';
