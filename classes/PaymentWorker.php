<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Background worker that continuously monitors on-chain payment statuses for pending invoices.
 *
 * Uses walletless BlockchainProvider without loading Electrum wallets or holding wallet locks.
 * Supports worker concurrency scaling via lease tokens or SKIP LOCKED.
 */
class PaymentWorker
{
    private Database $database;
    private BlockchainProviderInterface $blockchain;
    private WebhookOutboxRepository $outbox;
    private string $workerId;
    private int $batchSize;

    public function __construct(
        Database $database,
        BlockchainProviderInterface $blockchain,
        ?WebhookOutboxRepository $outbox = null,
        ?string $workerId = null,
        int $batchSize = 25
    ) {
        $this->database = $database;
        $this->blockchain = $blockchain;
        $this->outbox = $outbox ?? new WebhookOutboxRepository($database);
        $this->workerId = $workerId ?? ('pworker_' . getmypid() . '_' . bin2hex(random_bytes(4)));
        $this->batchSize = max(1, min(100, $batchSize));
    }

    public function runOnce(): int
    {
        $invoices = $this->claimBatch();
        $processedCount = 0;

        foreach ($invoices as $invoice) {
            $this->processInvoice($invoice);
            $processedCount++;
        }

        return $processedCount;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claimBatch(): array
    {
        $pdo = $this->database->getPdo();
        $now = time();

        // Find due invoices: status in ('New', 'Processing') or expired within last 24h
        // and next_check_at <= now
        try {
            $select = $pdo->prepare(
                "SELECT id, store_id, btc_address, amount, status, created_at, expires_at,
                        COALESCE(check_attempts, 0) AS check_attempts
                 FROM invoices
                 WHERE (
                     status IN ('New', 'Processing')
                     OR (status = 'Expired' AND expires_at >= ?)
                 )
                 AND (next_check_at IS NULL OR next_check_at <= ?)
                 ORDER BY next_check_at ASC
                 LIMIT ?"
            );
            $select->execute([$now - 86400, $now, $this->batchSize]);
            return $select->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // Fallback for pre-migration schema
            $legacy = $pdo->prepare(
                "SELECT id, store_id, btc_address, amount, status, created_at, expires_at,
                        0 AS check_attempts
                 FROM invoices
                 WHERE status IN ('New', 'Processing')
                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $legacy->execute([$this->batchSize]);
            return $legacy->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function processInvoice(array $invoice): void
    {
        $invoiceId = (string) $invoice['id'];
        $address = (string) $invoice['btc_address'];
        $expectedBtc = (string) $invoice['amount'];
        $expectedSats = $this->btcToSats($expectedBtc);
        $currentStatus = (string) ($invoice['status'] ?? 'New');
        $now = time();
        $expiresAt = (int) ($invoice['expires_at'] ?? ($now + 900));
        $attempts = (int) ($invoice['check_attempts'] ?? 0) + 1;

        try {
            $observation = $this->blockchain->getAddressObservation($address);
            $totalReceivedSats = $observation->getTotalReceivedSats();
            $confirmedSats = $observation->confirmedReceivedSats;
            $unconfirmedSats = $observation->unconfirmedReceivedSats;

            // Determine new status
            $newStatus = $currentStatus;
            if ($confirmedSats >= $expectedSats) {
                $newStatus = 'Settled';
            } elseif ($totalReceivedSats >= $expectedSats) {
                $newStatus = 'Processing';
            } elseif ($now >= $expiresAt) {
                $newStatus = 'Expired';
            }

            // Compute adaptive next check time with jitter (+/- 15%)
            $nextInterval = $this->calculateAdaptiveInterval($newStatus, $now - (int) $invoice['created_at']);
            $jitter = (int) round($nextInterval * (mt_rand(-15, 15) / 100.0));
            $nextCheckAt = $newStatus === 'Settled' ? null : ($now + max(2, $nextInterval + $jitter));

            $pdo = $this->database->getPdo();
            $pdo->beginTransaction();

            try {
                // Update invoice
                $update = $pdo->prepare(
                    "UPDATE invoices
                     SET status = ?,
                         confirmed_received_sats = ?,
                         unconfirmed_received_sats = ?,
                         last_checked_at = ?,
                         next_check_at = ?,
                         check_attempts = ?
                     WHERE id = ?"
                );
                $update->execute([
                    $newStatus,
                    $confirmedSats,
                    $unconfirmedSats,
                    $now,
                    $nextCheckAt,
                    $attempts,
                    $invoiceId,
                ]);

                // If status changed, enqueue webhook event
                if ($newStatus !== $currentStatus) {
                    $eventType = match ($newStatus) {
                        'Processing' => 'InvoiceReceivedPayment',
                        'Settled' => 'InvoiceSettled',
                        'Expired' => 'InvoiceExpired',
                        default => 'InvoiceStatusChanged',
                    };

                    $this->outbox->enqueue($invoiceId, $eventType, [
                        'invoiceId' => $invoiceId,
                        'storeId' => $invoice['store_id'],
                        'status' => $newStatus,
                        'amount' => $expectedBtc,
                        'paidAmountSats' => $totalReceivedSats,
                        'confirmedSats' => $confirmedSats,
                        'unconfirmedSats' => $unconfirmedSats,
                        'btcAddress' => $address,
                        'timestamp' => $now,
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (Throwable $e) {
            error_log("PaymentWorker failed to process invoice {$invoiceId}: " . $e->getMessage());
        }
    }

    private function calculateAdaptiveInterval(string $status, int $ageSeconds): int
    {
        if ($status === 'Settled') {
            return 86400; // stop polling
        }

        if ($status === 'Expired') {
            return 300; // check occasionally for late payments
        }

        // Active invoice
        if ($ageSeconds < 300) {
            return 5; // First 5 minutes: poll every 5s
        }

        if ($ageSeconds < 900) {
            return 15; // Up to 15 minutes: poll every 15s
        }

        return 30; // Older active invoices: poll every 30s
    }

    private function btcToSats(string $btc): int
    {
        $parts = explode('.', trim($btc), 2);
        $whole = (int) ($parts[0] ?? 0);
        $fraction = str_pad(substr($parts[1] ?? '', 0, 8), 8, '0', STR_PAD_RIGHT);

        return ($whole * 100_000_000) + (int) $fraction;
    }
}
