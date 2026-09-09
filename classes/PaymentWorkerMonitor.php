<?php

declare(strict_types=1);
namespace BtcPayLite;

use PDO;

/** Persistent evidence of runs, not a claim that an OS scheduler is installed. */
final class PaymentWorkerMonitor
{
    public function __construct(private PDO $pdo) {}

    public function lockName(): string
    {
        return 'payment-run:' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
    }

    public function start(string $source, string $token): void
    {
        // The runner holds the instance lock: any previous Running row is abandoned.
        $this->pdo->exec("UPDATE payment_worker_runtime SET state='Failed',finished_at=UNIX_TIMESTAMP(),
            last_failed_at=UNIX_TIMESTAMP(),error_type='interrupted' WHERE state='Running'");
        $stmt = $this->pdo->prepare("INSERT INTO payment_worker_runtime (source,run_token,state,started_at)
            VALUES (?,?,'Running',UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE
            run_token=VALUES(run_token),state='Running',started_at=UNIX_TIMESTAMP(),finished_at=NULL,scanned=0,transitioned=0,failed=0,deliveries_queued=0");
        $stmt->execute([$source, $token]);
    }

    public function finish(string $source, string $token, array $stats, ?string $error): void
    {
        $stmt = $this->pdo->prepare("UPDATE payment_worker_runtime SET state=?,finished_at=UNIX_TIMESTAMP(),
            last_success_at=IF(? IS NULL,UNIX_TIMESTAMP(),last_success_at),
            last_failed_at=IF(? IS NOT NULL,UNIX_TIMESTAMP(),last_failed_at),
            scanned=?,transitioned=?,failed=?,deliveries_queued=?,error_type=IF(?=0 AND ? IS NULL,error_type,?) WHERE source=? AND run_token=?");
        $stmt->execute([$error === null ? 'Succeeded' : 'Failed', $error, $error,
            $stats['scanned'] ?? 0, $stats['transitioned'] ?? 0, $stats['failed'] ?? 0,
            $stats['deliveries_queued'] ?? 0, $stats['scanned'] ?? 0, $error, $error, $source, $token]);
    }

    public function snapshot(): array
    {
        $now = (int) $this->pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        $rows = $this->pdo->query('SELECT * FROM payment_worker_runtime')->fetchAll(PDO::FETCH_ASSOC);
        $runs = [];
        foreach ($rows as $row) { unset($row['run_token']); $runs[$row['source']] = $row; }
        $stmt = $this->pdo->prepare('SELECT IS_FREE_LOCK(?)');
        $stmt->execute([$this->lockName()]);
        $running = (int) $stmt->fetchColumn() === 0;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS due, MIN(next_check_at) AS oldest_due_at FROM invoices WHERE '
            . PaymentWorker::ELIGIBLE_SQL . ' AND (next_check_at IS NULL OR next_check_at <= ?)
            AND (payment_processing_until IS NULL OR payment_processing_until <= UNIX_TIMESTAMP())');
        $stmt->execute([$now - 86400, $now]);
        $queue = $stmt->fetch(PDO::FETCH_ASSOC);
        $stale = (int) $this->pdo->query('SELECT COUNT(*) FROM invoices WHERE payment_processing_token IS NOT NULL AND payment_processing_until <= UNIX_TIMESTAMP()')->fetchColumn();
        return ['now'=>$now, 'running'=>$running, 'runs'=>$runs, 'due'=>(int)$queue['due'],
            'oldest_due_at'=>$queue['oldest_due_at'], 'stale_leases'=>$stale];
    }

    public static function automaticState(array $snapshot): string
    {
        $cli = $snapshot['runs']['cli'] ?? null;
        if ($cli === null) { return 'Nezaznamenán žádný CLI běh'; }
        if ($cli['state'] === 'Failed') { return 'Poslední CLI běh skončil chybou'; }
        if ($cli['state'] === 'Running' && !$snapshot['running']) { return 'CLI běh byl přerušen'; }
        $age = $snapshot['now'] - (int) $cli['started_at'];
        if ($age > 120) { return 'CLI kontrola je opožděná'; }
        if ($cli['error_type'] !== null && $cli['state'] !== 'Running') {
            return 'CLI se spouští; předchozí chyba kontroly zatím není ověřeně vyřešena';
        }
        return $cli['state'] === 'Running' ? 'CLI kontrola právě běží' : 'Nedávný CLI běh ověřen';
    }
}
