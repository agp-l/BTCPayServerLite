<?php

declare(strict_types=1);

use BtcPayLite\AuthManager;
use BtcPayLite\Database;
use BtcPayLite\DatabaseException;
use BtcPayLite\DatabaseMigrationManager;
use BtcPayLite\StoreCreationDiagnostics;

// index.php owns authentication and checks active account, role and session_version.
// Direct access to a handler must never bypass those checks.
if (!isset($route, $database, $config, $urlManager)
    || $route->getHandler() !== 'admin/database_upgrade.php'
    || !$database instanceof Database
) {
    http_response_code(404);
    exit;
}
AuthManager::requireRole('admin', $urlManager->url('/login'));
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$error = null;
$notice = null;
$report = null;
$storeEnvironment = null;
try {
    $manager = new DatabaseMigrationManager($database->getPdo(), dirname(__DIR__));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        if (($_POST['backup'] ?? '') !== '1' || ($_POST['maintenance'] ?? '') !== '1') {
            throw new RuntimeException('Před změnou potvrďte zálohu a zastavení zápisů aplikace.');
        }
        $manager->apply(
            is_string($_POST['migration'] ?? null) ? $_POST['migration'] : '',
            is_string($_POST['plan_hash'] ?? null) ? $_POST['plan_hash'] : '',
            (int) $_SESSION['user_id']
        );
        $notice = 'Migrace dokončena. Níže je nová kontrola schématu.';
    }
    $report = $manager->inspect();
    $storeEnvironment = StoreCreationDiagnostics::environment($config);
} catch (Throwable $exception) {
    http_response_code(400);
    if ($exception instanceof PDOException || $exception instanceof DatabaseException) {
        $error = 'Databázová kontrola selhala. Ověřte DB konfiguraci a oprávnění. SQLSTATE: '
            . ($exception instanceof PDOException ? (string) $exception->getCode() : 'connection');
    } else {
        $error = $exception->getMessage();
    }
}

$html = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrfToken = AuthManager::csrfToken();
require __DIR__ . '/views/database_upgrade_view.php';
