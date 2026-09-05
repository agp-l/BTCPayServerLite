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
        $claimedInvoices = $this->claimActiveInvoices($batchSize);

        $stats = [
            'scanned' => count($claimedInvoices),
            'transitioned' => 0,
            'expired' => 0,
            'failed' => 0,
            'deliveries_queued' => 0,
        ];

        foreach ($claimedInvoices as $invoice) {
            try {
                $transition = $this->processInvoice($invoice, $now);
                if ($transition['changed']) {
                    ++$stats['transitioned'];
                    if ($transition['status'] === 'Expired') {
                        ++$stats['expired'];
                    }
                    $stats['deliveries_queued'] += $transition['deliveries_queued'];
                }
            } catch (Throwable $exception) {
                ++$stats['failed'];
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
     * Claims active invoices atomically so multiple worker instances do not compete.
     *
     * @return list<array<string, mixed>>
     */
    private function claimActiveInvoices(int $limit): array
    {
        return $this->database->transactional(function (PDO $pdo) use ($limit): array {
            $sql = "SELECT id, store_id, btc_address, amount, status, created_at, expires_at
                      FROM invoices
                     WHERE status IN ('New', 'Processing')
                     ORDER BY expires_at ASC
                     LIMIT :limit
                     FOR UPDATE";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows) ? $rows : [];
        });
    }

    /**
     * Evaluates blockchain state for an invoice and performs CAS status update.
     *
     * @param array<string, mixed> $invoice
     * @return array{changed: bool, status: string, deliveries_queued: int}
     */
    private function processInvoice(array $invoice, int $now): array
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        $storeId = (string) ($invoice['store_id'] ?? '');
        $address = (string) ($invoice['btc_address'] ?? '');
        $currentStatus = (string) ($invoice['status'] ?? 'New');
        $expiresAt = (int) ($invoice['expires_at'] ?? 0);

        $expectedAmount = BitcoinAmount::fromBtc((string) ($invoice['amount'] ?? '0'));
        $expectedSats = $expectedAmount->toSatoshis();

        // Query blockchain via walletless provider
        $observation = $this->blockchain->observeAddress($address, $expectedSats);
        $confirmedSats = $observation->getConfirmedSatoshis();
        $receivedSats = $observation->getEffectiveReceivedSatoshis();

        $newStatus = $currentStatus;
        if ($confirmedSats >= $expectedSats) {
            $newStatus = 'Settled';
        } elseif ($receivedSats >= $expectedSats) {
            $newStatus = 'Processing';
        } elseif ($now >= $expiresAt) {
            // Only expire if no unconfirmed or partial payments exist
            if ($receivedSats === 0) {
                $newStatus = 'Expired';
            } else {
                $newStatus = 'Processing';
            }
        }

        // Monotonic transition check: Settled is terminal, cannot revert
        if ($currentStatus === 'Settled') {
            return ['changed' => false, 'status' => 'Settled', 'deliveries_queued' => 0];
        }

        if ($newStatus === $currentStatus) {
            return ['changed' => false, 'status' => $currentStatus, 'deliveries_queued' => 0];
        }

        // Compare-and-Swap (CAS) update
        $stmt = $this->database->getPdo()->prepare(
            'UPDATE invoices SET status = ? WHERE id = ? AND status = ?'
        );
        $stmt->execute([$newStatus, $invoiceId, $currentStatus]);

        if ($stmt->rowCount() !== 1) {
            // Concurrently updated by another worker/process
            return ['changed' => false, 'status' => $currentStatus, 'deliveries_queued' => 0];
        }

        // Enqueue webhook notification for status change
        $deliveriesQueued = 0;
        $eventType = $this->eventForStatus($newStatus);
        if ($eventType !== null) {
            $deliveriesQueued = $this->webhookRepository->ensureDeliveries(
                $invoiceId,
                $storeId,
                $eventType,
                $now
            );
        }

        return [
            'changed' => true,
            'status' => $newStatus,
            'deliveries_queued' => $deliveriesQueued,
        ];
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
