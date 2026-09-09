<?php

declare(strict_types=1);

use BtcPayLite\{AuthManager, Database, PaymentWorkerMonitor, PaymentWorkerRunner};

if (!isset($route, $database, $config, $urlManager)
    || $route->getHandler() !== 'admin/payment_monitor.php' || !$database instanceof Database) {
    http_response_code(404); exit;
}
AuthManager::requireRole('admin', $urlManager->url('/login'));
header('Cache-Control: no-store');
$pageError = null;
$notice = null;
$snapshot = null;
$csrfToken = AuthManager::csrfToken();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        if (($_POST['action'] ?? '') !== 'run') { throw new RuntimeException('Neplatná akce.'); }
        // Release the session mutex so the administrator can still navigate during RPC.
        session_write_close();
        ignore_user_abort(true);
        $result = PaymentWorkerRunner::fromConfig($database, $config, true)->run('manual');
        if ($result['busy']) {
            $notice = $result['reason'] === 'cooldown'
                ? 'Další ruční kontrolu lze spustit po 15 sekundách.'
                : 'Kontrola právě běží v jiném procesu. Obnovte přehled za chvíli.';
        } else {
            $stats = $result['stats'];
            $notice = sprintf('Zkontrolováno: %d. Změny stavů: %d. Chyby: %d. Webhooky zařazené k doručení: %d.',
                $stats['scanned'], $stats['transitioned'], $stats['failed'], $stats['deliveries_queued']);
            if (!$result['success']) { $pageError = 'Některé faktury se nepodařilo ověřit. Zkontrolujte Electrum RPC a oprávnění ke cache.'; }
        }
    }
    $snapshot = (new PaymentWorkerMonitor($database->getPdo()))->snapshot();
    $automaticState = PaymentWorkerMonitor::automaticState($snapshot);
} catch (Throwable $exception) {
    http_response_code(400);
    if ($exception instanceof PDOException && in_array((string)$exception->getCode(), ['42S02','42S22'], true)) {
        $pageError = 'Chybí databázová migrace. V Aktualizaci systému dokončete migrace včetně 010_payment_worker_runtime.sql.';
    } elseif ($exception instanceof \BtcPayLite\AuthException) {
        $pageError = $exception->getMessage();
    } else {
        $pageError = 'Kontrolu nyní nelze dokončit. Ověřte DB, Electrum RPC a oprávnění k var/blockchain. Typ chyby: ' . $exception::class;
    }
}
$html = static fn (mixed $text): string => htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formatTime = static fn (mixed $time): string => $time === null ? 'Zatím nezaznamenáno' : gmdate('d. m. Y H:i:s', (int)$time) . ' UTC';
$stateLabels = ['Running'=>'Běží / nedokončeno', 'Succeeded'=>'Dokončeno', 'Failed'=>'Chyba'];
require __DIR__ . '/views/payment_monitor_view.php';
