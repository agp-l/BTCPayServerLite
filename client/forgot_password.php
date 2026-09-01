<?php

declare(strict_types=1);

use BtcPayLite\AuthException;
use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\PasswordResetMailer;
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
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $database = new Database(
            $config['db_host'],
            $config['db_name'],
            $config['db_user'],
            $config['db_pass'],
            (int) ($config['db_port'] ?? 3306)
        );
        $token = (new UserAccountService(new PdoUserAccountRepository($database)))
            ->requestPasswordReset(
                $email,
                is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null
            );

        if ($token !== null) {
            $resetUrl = $urlManager->url('/reset-password', ['token' => $token]);
            $from = is_string($config['password_reset_from'] ?? null)
                ? trim($config['password_reset_from'])
                : '';
            if (!(new PasswordResetMailer())->send($email, $resetUrl, $from)) {
                error_log('Password reset email delivery failed.');
            }
        }
        $success = true;
        $email = '';
    } catch (AuthException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Password reset request failed: ' . $exception::class);
        $error = 'Požadavek nyní nelze dokončit. Zkuste to prosím později.';
    }
}

$csrfToken = AuthManager::csrfToken();
require __DIR__ . '/views/forgot_password_view.php';
