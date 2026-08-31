<?php

declare(strict_types=1);

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\ClientRegistrationService;
use BtcPayLite\Database;
use BtcPayLite\ElectrumCliWalletProvisioner;
use BtcPayLite\PdoClientDashboardRepository;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

AuthManager::startSession();
AuthManager::sendPrivateResponseHeaders();
$urlManager = isset($urlManager) && $urlManager instanceof UrlManager
    ? $urlManager
    : new UrlManager(
        $_SERVER,
        is_string($config['app_url'] ?? null) ? $config['app_url'] : null
    );

$error = '';
$successMsg = '';
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['password_confirm'] ?? null)
            ? $_POST['password_confirm']
            : '';

        $database = new Database(
            $config['db_host'],
            $config['db_name'],
            $config['db_user'],
            $config['db_pass'],
            (int) ($config['db_port'] ?? 3306)
        );
        $walletPath = is_string($config['wallet_path'] ?? null) ? $config['wallet_path'] : '';
        if (
            trim($walletPath) === ''
            && !is_string($config['store_wallets_dir'] ?? null)
            && !is_string($config['wallet_directory'] ?? null)
        ) {
            throw new RuntimeException('Wallet directory configuration is missing.');
        }
        $walletDirectory = is_string($config['store_wallets_dir'] ?? null)
            ? $config['store_wallets_dir']
            : (is_string($config['wallet_directory'] ?? null)
                ? $config['wallet_directory']
                : dirname($walletPath));
        $electrumCli = is_string($config['electrum_cli_path'] ?? null)
            ? $config['electrum_cli_path']
            : (is_string($config['electrum_cli'] ?? null)
                ? $config['electrum_cli']
                : '/opt/electrum/run_electrum');
        $electrumData = is_string($config['electrum_data_dir'] ?? null)
            ? $config['electrum_data_dir']
            : (is_string($config['electrum_data_directory'] ?? null)
                ? $config['electrum_data_directory']
                : '/opt/electrum_config');

        $service = new ClientRegistrationService(
            new AuthManager($database),
            new PdoClientDashboardRepository($database),
            new ElectrumCliWalletProvisioner($electrumCli, $electrumData, $walletDirectory),
            static fn (callable $callback): mixed => $database->transactional(
                static fn (\PDO $pdo): mixed => $callback()
            )
        );
        $clientIdentity = is_string($_SERVER['REMOTE_ADDR'] ?? null)
            ? $_SERVER['REMOTE_ADDR']
            : '';
        $service->register($email, $password, $passwordConfirm, $clientIdentity);

        $successMsg = 'Registrace proběhla úspěšně. Nyní se můžete přihlásit.';
        $email = '';
    } catch (AuthException $exception) {
        $error = $exception->getMessage();
        error_log('Client registration rejected: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()));
    } catch (Throwable $exception) {
        error_log(sprintf('Unexpected registration failure: %s (%s)', $exception->getMessage(), $exception::class));
        $error = 'Došlo k interní systémové chybě. Zkuste to prosím později.';
    }
}

$csrfToken = AuthManager::csrfToken();
require __DIR__ . '/views/registrace_view.php';
