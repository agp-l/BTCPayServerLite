<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use LogicException;
use Throwable;

/**
 * Monitors database invoices and delivers their webhook outbox records.
 *
 * The processor is intentionally independent of HTTP and CLI entry points.
 * Every delivery is claimed persistently before network I/O, so a process
 * crash can be recovered by a later run without losing the event.
 */
class WebhookProcessor
{
    private const ELECTRUM_LOCK_NAME = 'electrum_rpc';
    private const ELECTRUM_LOCK_TIMEOUT_SECONDS = 10;
    private const MAX_DELIVERY_ATTEMPTS = 8;

    /** @var array<int, int> attempt number => retry delay in seconds */
    private const RETRY_DELAYS = [
        1 => 60,
        2 => 300,
        3 => 900,
        4 => 3_600,
        5 => 21_600,
        6 => 43_200,
        7 => 86_400,
    ];

    private Database $database;
    private ElectrumWallet $wallet;
    private BtcInvoiceManager $invoiceManager;
    private WebhookDeliveryRepository $repository;
    private WebhookTransport $transport;
    private Closure $clock;

    public function __construct(
        Database $database,
        ElectrumWallet $wallet,
        BtcInvoiceManager $invoiceManager,
        WebhookDeliveryRepository $repository,
        WebhookTransport $transport,
        ?callable $clock = null
    ) {
        $this->database = $database;
        $this->wallet = $wallet;
        $this->invoiceManager = $invoiceManager;
        $this->repository = $repository;
        $this->transport = $transport;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    /**
     * @return array{
     *   invoices_scanned: int,
     *   invoice_transitions: int,
     *   invoices_failed: int,
     *   deliveries_queued: int,
     *   deliveries_claimed: int,
     *   deliveries_delivered: int,
     *   retries_scheduled: int,
     *   deliveries_dead: int,
     *   errors: list<array{scope: string, id: string, message: string}>
     * }
     */
    public function run(int $invoiceLimit = 100, int $deliveryLimit = 100): array
    {
        $report = $this->newReport();

        try {
            $activeInvoices = $this->repository->findActiveInvoices($invoiceLimit);
            foreach ($activeInvoices as $invoice) {
                ++$report['invoices_scanned'];
                $this->monitorInvoice($invoice, $report);
            }
        } catch (Throwable $exception) {
            $this->recordError($report, 'invoice_scan', '-', $exception);
        }

        try {
            $missingInvoices = $this->repository->findTerminalInvoicesMissingDeliveries($invoiceLimit);
            foreach ($missingInvoices as $invoice) {
                try {
                    $eventType = $this->eventForStatus($invoice['status']);
                    if ($eventType !== null) {
                        $report['deliveries_queued'] += $this->repository->ensureDeliveries(
                            $invoice['id'],
                            $invoice['store_id'],
                            $eventType,
                            $this->now()
                        );
                    }
                } catch (Throwable $exception) {
                    ++$report['invoices_failed'];
                    $this->recordError($report, 'invoice_reconciliation', $invoice['id'], $exception);
                }
            }
        } catch (Throwable $exception) {
            $this->recordError($report, 'invoice_reconciliation', '-', $exception);
        }

        try {
            $deliveries = $this->repository->claimDueDeliveries($this->now(), $deliveryLimit);
            $report['deliveries_claimed'] = count($deliveries);
        } catch (Throwable $exception) {
            $this->recordError($report, 'delivery_claim', '-', $exception);

            return $report;
        }

        foreach ($deliveries as $delivery) {
            $this->deliver($delivery, $report);
        }

        return $report;
    }

    /**
     * @param array{id: string, store_id: string, status: string, wallet_path: string} $invoice
     * @param array<string, mixed> $report
     */
    private function monitorInvoice(array $invoice, array &$report): void
    {
        try {
            // Queue an already-observed Processing state before touching
            // Electrum. This repairs a previous crash after the status update.
            $currentEvent = $this->eventForStatus($invoice['status']);
            if ($currentEvent !== null) {
                $report['deliveries_queued'] += $this->repository->ensureDeliveries(
                    $invoice['id'],
                    $invoice['store_id'],
                    $currentEvent,
                    $this->now()
                );
            }

            $statusData = $this->database->withNamedLock(
                self::ELECTRUM_LOCK_NAME,
                self::ELECTRUM_LOCK_TIMEOUT_SECONDS,
                function ($_pdo) use ($invoice): array {
                    if ($invoice['wallet_path'] !== '') {
                        $this->wallet->loadWallet($invoice['wallet_path']);
                    }

                    return $this->invoiceManager->checkDatabasePaymentStatus($invoice['id']);
                }
            );
            $newStatus = $statusData['status'] ?? null;
            if (!is_string($newStatus) || !$this->isInvoiceStatus($newStatus)) {
                throw new WebhookDeliveryException(
                    'Invoice checker returned an invalid status.',
                    'monitor_invoice'
                );
            }

            if ($newStatus !== $invoice['status']) {
                ++$report['invoice_transitions'];
            }

            $eventType = $this->eventForStatus($newStatus);
            if ($eventType !== null) {
                $report['deliveries_queued'] += $this->repository->ensureDeliveries(
                    $invoice['id'],
                    $invoice['store_id'],
                    $eventType,
                    $this->now()
                );
            }
        } catch (Throwable $exception) {
            ++$report['invoices_failed'];
            $this->recordError($report, 'invoice_monitor', $invoice['id'], $exception);
        }
    }

    /**
     * @param array{
     *   id: string,
     *   webhook_id: string,
     *   invoice_id: string,
     *   event_type: string,
     *   payload: string,
     *   attempts: int,
     *   url: string,
     *   secret: string,
     *   lock_token: string
     * } $delivery
     * @param array<string, mixed> $report
     */
    private function deliver(array $delivery, array &$report): void
    {
        try {
            $signature = 'sha256=' . hash_hmac(
                'sha256',
                $delivery['payload'],
                $delivery['secret']
            );
            $result = $this->transport->deliver(
                $delivery['url'],
                $delivery['payload'],
                $signature
            );
            if (
                !is_int($result['http_status'] ?? null)
                || !is_string($result['primary_ip'] ?? null)
            ) {
                throw new WebhookDeliveryException(
                    'Webhook transport returned an invalid result.',
                    'deliver_webhook'
                );
            }
            $this->repository->markDelivered(
                $delivery['id'],
                $delivery['lock_token'],
                $this->now(),
                $result['http_status'],
                $result['primary_ip']
            );
            ++$report['deliveries_delivered'];
        } catch (Throwable $exception) {
            $retryable = !($exception instanceof WebhookDeliveryException)
                || $exception->isRetryable();
            $retry = $retryable && $delivery['attempts'] < self::MAX_DELIVERY_ATTEMPTS;
            $nextAttemptAt = $this->now();
            if ($retry) {
                $nextAttemptAt += self::RETRY_DELAYS[$delivery['attempts']] ?? 86_400;
            }
            $httpStatus = $exception instanceof WebhookDeliveryException
                ? $exception->getHttpStatus()
                : null;

            try {
                $this->repository->markFailed(
                    $delivery['id'],
                    $delivery['lock_token'],
                    $retry,
                    $nextAttemptAt,
                    $httpStatus,
                    $this->safeErrorMessage($exception)
                );
                if ($retry) {
                    ++$report['retries_scheduled'];
                } else {
                    ++$report['deliveries_dead'];
                }
            } catch (Throwable $storageFailure) {
                // The row remains Processing and will be reclaimed after its
                // stale timeout. Report both failures without losing the claim.
                $this->recordError(
                    $report,
                    'delivery_result',
                    $delivery['id'],
                    $storageFailure
                );
            }

            $this->recordError($report, 'delivery', $delivery['id'], $exception);
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

    private function isInvoiceStatus(string $status): bool
    {
        return in_array($status, ['New', 'Processing', 'Settled', 'Expired'], true);
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 1) {
            throw new LogicException('Webhook processor clock must return a positive Unix timestamp.');
        }

        return $now;
    }

    /** @return array<string, mixed> */
    private function newReport(): array
    {
        return [
            'invoices_scanned' => 0,
            'invoice_transitions' => 0,
            'invoices_failed' => 0,
            'deliveries_queued' => 0,
            'deliveries_claimed' => 0,
            'deliveries_delivered' => 0,
            'retries_scheduled' => 0,
            'deliveries_dead' => 0,
            'errors' => [],
        ];
    }

    /** @param array<string, mixed> $report */
    private function recordError(
        array &$report,
        string $scope,
        string $id,
        Throwable $exception
    ): void {
        $report['errors'][] = [
            'scope' => $scope,
            'id' => $id,
            'message' => $this->safeErrorMessage($exception),
        ];
    }

    private function safeErrorMessage(Throwable $exception): string
    {
        $message = $exception instanceof WebhookDeliveryException
            ? $exception->getMessage()
            : 'Unexpected webhook processing failure.';
        $message = preg_replace('/[^\x20-\x7E]/', '?', trim($message));

        return is_string($message) && $message !== ''
            ? substr($message, 0, 255)
            : 'Webhook processing failed.';
    }
}
