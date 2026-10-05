<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use PDO;
use Throwable;

/** Owns invoice monitoring. RPC is outside transactions; state + outbox commit together. */
class PaymentWorker
{
    public const ELIGIBLE_SQL = "(status IN ('New', 'Processing') OR (status = 'Expired'
        AND (expires_at >= ? OR confirmed_balance_sats > 0 OR mempool_delta_sats > 0
             OR confirmed_output_sats > 0 OR unconfirmed_output_sats > 0)))";
    private array $failureCodes = [];
    public function getFailureCodes(): array { return $this->failureCodes; }

    private Closure $clock;
    private int $leaseSeconds;

    public function __construct(
        private Database $database,
        private BlockchainProviderInterface $blockchain,
        private WebhookDeliveryRepository $webhookRepository,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
        $bound = $blockchain->maxObservationDurationSeconds();
        if ($bound < 1 || $bound > 3600) {
            throw new \InvalidArgumentException('Blockchain provider must declare a bounded operation duration.');
        }
        $this->leaseSeconds = max(60, $bound + 30);
    }

    /** @return array{scanned:int,transitioned:int,expired:int,failed:int,deliveries_queued:int} */
    public function run(int $batchSize = 50, ?int $maxSeconds = null): array
    {
        $this->failureCodes = [];
        $deadline = $maxSeconds === null ? null : hrtime(true) / 1e9 + max(1, $maxSeconds);
        $stats = ['scanned' => 0, 'transitioned' => 0, 'expired' => 0, 'failed' => 0, 'deliveries_queued' => 0];
        for ($i = 0; $i < max(1, min($batchSize, 500)); ++$i) {
            // Do not claim work unless a whole bounded observation fits the remaining budget.
            if ($deadline !== null && hrtime(true) / 1e9 + $this->blockchain->maxObservationDurationSeconds() > $deadline) { break; }
            $token = bin2hex(random_bytes(16));
            // Claim just before observation. A queued batch must not consume its
            // lease while waiting for all earlier RPCs to finish.
            $invoice = $this->claimInvoice($token);
            if ($invoice === null) {
                break;
            }
            ++$stats['scanned'];
            try {
                $interval = PaymentCheckPolicy::interval((int) $invoice['created_at'], ($this->clock)(), (string) $invoice['status']);
                $observation = $this->blockchain instanceof ElectrumBlockchainProvider
                    ? $this->blockchain->observeAddressAtInterval((string) $invoice['btc_address'],
                        BitcoinAmount::fromBtc((string) $invoice['amount'])->satoshis(), $interval)
                    : $this->blockchain->observeAddress(
                    (string) $invoice['btc_address'],
                    BitcoinAmount::fromBtc((string) $invoice['amount'])->satoshis()
                );
                $result = $this->commitObservation((string) $invoice['id'], $token, $observation);
                $stats['transitioned'] += (int) $result['changed'];
                $stats['expired'] += (int) ($result['changed'] && $result['status'] === 'Expired');
                $stats['deliveries_queued'] += $result['deliveries_queued'];
            } catch (Throwable $exception) {
                ++$stats['failed'];
                $code = PaymentFailureDiagnostics::code($exception);
                $deferred = in_array($code, ['observation_budget', 'observation_concurrency', 'upstream_circuit_open'], true);
                $this->releaseFailedLease((string) $invoice['id'], $token, $interval, $deferred);
                $this->failureCodes[$code] = ($this->failureCodes[$code] ?? 0) + 1;
                error_log('PaymentWorker failed for ' . $invoice['id'] . ': ' . json_encode(PaymentFailureDiagnostics::details($exception)));
                // Do not spin through the rest of the queue while the whole endpoint
                // is limited. Local admission did not observe this invoice.
                if ($deferred) { break; }
            }
        }
        return $stats;
    }

    private function claimInvoice(string $token): ?array
    {
        $pdo = $this->database->getPdo();
        // Database time keeps independent hosts on the same lease clock.
        $update = $pdo->prepare(
            "UPDATE invoices
                SET payment_processing_token = ?, payment_processing_until = UNIX_TIMESTAMP() + ?
              WHERE " . self::ELIGIBLE_SQL . "
                AND (payment_processing_until IS NULL OR payment_processing_until <= UNIX_TIMESTAMP())
                AND (next_check_at IS NULL OR next_check_at <= ?)
                AND (last_checked_at IS NULL OR last_checked_at <= ?)
           ORDER BY next_check_at ASC, expires_at ASC, id ASC LIMIT 1"
        );
        $now = ($this->clock)();
        $update->execute([$token, $this->leaseSeconds, $now - PaymentCheckPolicy::EXPIRED_MONITORING_SECONDS, $now, $now - PaymentCheckPolicy::MIN_INTERVAL]);
        if ($update->rowCount() !== 1) {
            return null;
        }
        $select = $pdo->prepare('SELECT id, btc_address, amount, created_at, status FROM invoices WHERE payment_processing_token = ?');
        $select->execute([$token]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function commitObservation(string $invoiceId, string $token, AddressPaymentObservation $observation): array
    {
        return $this->database->transactional(function (PDO $pdo) use ($invoiceId, $token, $observation): array {
            $select = $pdo->prepare(
                'SELECT *, payment_processing_until > UNIX_TIMESTAMP() AS lease_valid
                   FROM invoices WHERE id = ? FOR UPDATE'
            );
            $select->execute([$invoiceId]);
            $invoice = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($invoice) || $invoice['payment_processing_token'] !== $token || !(bool) $invoice['lease_valid']) {
                throw new \RuntimeException('Payment worker no longer owns the invoice lease.');
            }
            if ($invoice['btc_address'] !== $observation->getAddress()) {
                throw new \RuntimeException('Observation address does not match the invoice.');
            }
            $now = ($this->clock)();
            $current = (string) $invoice['status'];
            $status = InvoiceStateMachine::next($current,
                BitcoinAmount::fromBtc((string) $invoice['amount'])->satoshis(),
                $observation, (int) $invoice['expires_at'], $now,
                (int) $invoice['confirmed_balance_sats'] > 0 || (int) $invoice['mempool_delta_sats'] > 0
                    || (int) $invoice['confirmed_output_sats'] > 0 || (int) $invoice['unconfirmed_output_sats'] > 0);
            InvoiceStateMachine::assertTransition($current, $status);
            $next = PaymentCheckPolicy::nextCheck((int) $invoice['created_at'], $now, $status);
            $update = $pdo->prepare(
                'UPDATE invoices SET status = ?, confirmed_balance_sats = ?, mempool_delta_sats = ?,
                    payment_observed_at = ?, last_checked_at = ?, next_check_at = ?,
                    confirmed_output_sats = ?, unconfirmed_output_sats = ? WHERE id = ?'
            );
            $update->execute([$status, $observation->getConfirmedBalanceSatoshis(),
                $observation->getMempoolDeltaSatoshis(), $observation->getObservedAt(), $now, $next,
                $observation->getConfirmedReceivedSatoshis(), $observation->getUnconfirmedReceivedSatoshis(), $invoiceId]);
            $changed = $status !== $current;
            $event = $changed ? InvoiceStateMachine::eventFor($status) : null;
            $queued = $event === null ? 0 : $this->webhookRepository->enqueueInTransaction(
                $pdo, $invoiceId, (string) $invoice['store_id'], $event, $now
            );
            $release = $pdo->prepare(
                'UPDATE invoices SET payment_processing_token = NULL, payment_processing_until = NULL WHERE id = ?'
            );
            $release->execute([$invoiceId]);
            return ['changed' => $changed, 'status' => $status, 'deliveries_queued' => $queued];
        });
    }

    private function releaseFailedLease(string $invoiceId, string $token, int $interval, bool $deferred = false): void
    {
        try {
            $stmt = $this->database->getPdo()->prepare($deferred
                ? 'UPDATE invoices SET payment_processing_token = NULL, payment_processing_until = NULL,
                    next_check_at = ? WHERE id = ? AND payment_processing_token = ?'
                :
                'UPDATE invoices SET payment_processing_token = NULL, payment_processing_until = NULL,
                    last_checked_at = ?, next_check_at = ? WHERE id = ? AND payment_processing_token = ?'
            );
            $now = ($this->clock)();
            $stmt->execute($deferred ? [$now + 60, $invoiceId, $token] : [$now, $now + $interval, $invoiceId, $token]);
        } catch (Throwable) {
            // A dead connection/process leaves a bounded persistent lease.
        }
    }
}
