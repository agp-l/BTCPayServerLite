<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use PDO;
use Throwable;

/**
 * Background worker that monitors active invoices on the blockchain, performs
 * atomic status transitions, and enqueues webhook notifications.
 *
 * Implements:
 * - Atomic invoice claiming via row-level locking
 * - Decoupled daemon-level blockchain observation (zero wallet locks)
 * - Monotonic status transitions: New -> Processing -> Settled (terminal); Expired
 * - Idempotent webhook delivery queueing
 */
class PaymentWorker
{
    private const DEFAULT_BATCH_SIZE = 50;

    private Database $database;
    private BlockchainProviderInterface $blockchain;
    private WebhookDeliveryRepository $webhookRepository;
    private Closure $clock;

    public function __construct(
        Database $database,
        BlockchainProviderInterface $blockchain,
        WebhookDeliveryRepository $webhookRepository,
        ?callable $clock = null
    ) {
        $this->database = $database;
        $this->blockchain = $blockchain;
        $this->webhookRepository = $webhookRepository;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    /**
     * Executes one monitoring cycle over active invoices.
     *
     * @return array{
     *   scanned: int,
     *   transitioned: int,
     *   expired: int,
     *   failed: int,
     *   deliveries_queued: int
     * }
     */
    public function run(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $now = ($this->clock)();
        $lockToken = bin2hex(random_bytes(16));
        $claimedInvoices = $this->claimActiveInvoices($batchSize, $lockToken, $now);

        $stats = [
            'scanned' => count($claimedInvoices),
            'transitioned' => 0,
            'expired' => 0,
            'failed' => 0,
            'deliveries_queued' => 0,
        ];

        foreach ($claimedInvoices as $invoice) {
            try {
                $transition = $this->processClaimedInvoice($invoice, $lockToken, $now);
                if ($transition['changed']) {
                    ++$stats['transitioned'];
                    if ($transition['status'] === 'Expired') {
                        ++$stats['expired'];
                    }
                    $stats['deliveries_queued'] += $transition['deliveries_queued'];
                }
            } catch (Throwable $exception) {
                ++$stats['failed'];
                $this->releaseInvoiceLease((string) ($invoice['id'] ?? ''), $lockToken, $now);
                error_log(sprintf(
                    'PaymentWorker failed for invoice %s: %s',
                    $invoice['id'] ?? '',
                    $exception->getMessage()
                ));
            }
        }

        return $stats;
    }

    /**
     * Claims active and recently expired invoices atomically using a persistent lease token.
     *
     * @return list<array<string, mixed>>
     */
    private function claimActiveInvoices(int $limit, string $lockToken, int $now, int $leaseDuration = 60): array
    {
        $limit = max(1, min($limit, 500));
        $leaseUntil = $now + $leaseDuration;
        $recentExpiredThreshold = $now - 86400;
        $pdo = $this->database->getPdo();

        $update = $pdo->prepare(
            "UPDATE invoices
                SET payment_processing_token = ?,
                    payment_processing_until = ?
              WHERE (
                      status IN ('New', 'Processing')
                      OR (status = 'Expired' AND expires_at >= ?)
                    )
                AND (payment_processing_until IS NULL OR payment_processing_until <= ?)
                AND (next_check_at IS NULL OR next_check_at <= ?)
           ORDER BY expires_at ASC, id ASC
              LIMIT {$limit}"
        );
        $update->execute([$lockToken, $leaseUntil, $recentExpiredThreshold, $now, $now]);

        $select = $pdo->prepare(
            "SELECT id, store_id, btc_address, amount, status, created_at, expires_at,
                    confirmed_received_sats, unconfirmed_received_sats
               FROM invoices
              WHERE payment_processing_token = ?
           ORDER BY expires_at ASC, id ASC"
        );
        $select->execute([$lockToken]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Evaluates blockchain state for a claimed invoice, persists observed satoshis,
     * performs monotonic status transitions, and releases the lease token.
     *
     * @param array<string, mixed> $invoice
     * @return array{changed: bool, status: string, deliveries_queued: int}
     */
    private function processClaimedInvoice(array $invoice, string $lockToken, int $now): array
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        $storeId = (string) ($invoice['store_id'] ?? '');
        $address = (string) ($invoice['btc_address'] ?? '');
        $currentStatus = (string) ($invoice['status'] ?? 'New');
        $expiresAt = (int) ($invoice['expires_at'] ?? 0);
        $prevConfirmedSats = (int) ($invoice['confirmed_received_sats'] ?? 0);
        $prevUnconfirmedSats = (int) ($invoice['unconfirmed_received_sats'] ?? 0);

        // Settled status is terminal and monotonic; never degrade
        if ($currentStatus === 'Settled') {
            $this->releaseInvoiceLease($invoiceId, $lockToken, $now, null);
            return ['changed' => false, 'status' => 'Settled', 'deliveries_queued' => 0];
        }

        $expectedAmount = BitcoinAmount::fromBtc((string) ($invoice['amount'] ?? '0'));
        $expectedSats = $expectedAmount->toSatoshis();

        // Query blockchain via walletless provider
        $observation = $this->blockchain->observeAddress($address, $expectedSats);
        $confirmedSats = max($observation->getConfirmedSatoshis(), $prevConfirmedSats);
        $unconfirmedSats = $observation->getUnconfirmedSatoshis();
        $receivedSats = max($confirmedSats + $unconfirmedSats, $prevConfirmedSats + $prevUnconfirmedSats);

        $newStatus = $currentStatus;
        $nextCheckAt = null;

        if ($confirmedSats >= $expectedSats) {
            $newStatus = 'Settled';
            $nextCheckAt = null; // terminal
        } elseif ($receivedSats > 0) {
            // Any observed payment (unconfirmed, partial, or late arrival) transitions to Processing
            $newStatus = 'Processing';
            $nextCheckAt = $now + 15;
        } elseif ($now >= $expiresAt) {
            // Expire only if zero payment was observed
            $newStatus = 'Expired';
            if ($now < $expiresAt + 86400) {
                $nextCheckAt = $now + 300; // Check intermittently for late arrivals
            } else {
                $nextCheckAt = null;
            }
        } else {
            $newStatus = 'New';
            $nextCheckAt = $now + 15;
        }

        // Monotonic transition check: Settled cannot revert
        if ($currentStatus === 'Settled') {
            $newStatus = 'Settled';
            $nextCheckAt = null;
        }

        // Persist observation and release lease under token ownership
        $stmt = $this->database->getPdo()->prepare(
            'UPDATE invoices
                SET status = ?,
                    confirmed_received_sats = ?,
                    unconfirmed_received_sats = ?,
                    last_checked_at = ?,
                    next_check_at = ?,
                    payment_processing_token = NULL,
                    payment_processing_until = NULL
              WHERE id = ? AND payment_processing_token = ?'
        );
        $stmt->execute([
            $newStatus,
            $confirmedSats,
            $unconfirmedSats,
            $now,
            $nextCheckAt,
            $invoiceId,
            $lockToken,
        ]);

        if ($stmt->rowCount() !== 1) {
            // Lease expired or ownership was concurrently lost
            return ['changed' => false, 'status' => $currentStatus, 'deliveries_queued' => 0];
        }

        $deliveriesQueued = 0;
        $changed = ($newStatus !== $currentStatus);
        if ($changed) {
            $eventType = $this->eventForStatus($newStatus);
            if ($eventType !== null) {
                $deliveriesQueued = $this->webhookRepository->ensureDeliveries(
                    $invoiceId,
                    $storeId,
                    $eventType,
                    $now
                );
            }
        }

        return [
            'changed' => $changed,
            'status' => $newStatus,
            'deliveries_queued' => $deliveriesQueued,
        ];
    }

    private function releaseInvoiceLease(string $invoiceId, string $lockToken, int $now, ?int $nextCheckAt = null): void
    {
        try {
            $stmt = $this->database->getPdo()->prepare(
                'UPDATE invoices
                    SET payment_processing_token = NULL,
                        payment_processing_until = NULL,
                        last_checked_at = ?,
                        next_check_at = ?
                  WHERE id = ? AND payment_processing_token = ?'
            );
            $stmt->execute([
                $now,
                $nextCheckAt ?? ($now + 30),
                $invoiceId,
                $lockToken,
            ]);
        } catch (Throwable) {
        }
    }

    private function eventForStatus(string $status): ?string
    {
        return match ($status) {
            'Processing' => 'InvoiceProcessing',
            'Settled' => 'InvoiceSettled',
            'Expired' => 'InvoiceExpired',
            default => null,
        };
    }
}
