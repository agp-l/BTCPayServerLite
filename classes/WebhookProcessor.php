<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use LogicException;
use Throwable;

/**
 * Claims and delivers existing webhook outbox records.
 *
 * All blockchain monitoring has been decoupled and migrated exclusively to PaymentWorker.
 * The processor is intentionally independent of HTTP and CLI entry points.
 * Every delivery is claimed persistently before network I/O, so a process
 * crash can be recovered by a later run without losing the event.
 */
class WebhookProcessor
{
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

    private WebhookDeliveryRepository $repository;
    private WebhookTransport $transport;
    private Closure $clock;

    public function __construct(
        WebhookDeliveryRepository|Database $first,
        WebhookTransport|ElectrumWallet $second,
        mixed $third = null,
        mixed $fourth = null,
        mixed $fifth = null,
        ?callable $sixth = null
    ) {
        if ($first instanceof WebhookDeliveryRepository && $second instanceof WebhookTransport) {
            $this->repository = $first;
            $this->transport = $second;
            $clockCallable = is_callable($third) ? $third : null;
        } else {
            // Legacy signature compatibility: ($database, $wallet, $invoiceManager, $repository, $transport, $clock)
            /** @var WebhookDeliveryRepository $repository */
            $repository = $fourth;
            /** @var WebhookTransport $transport */
            $transport = $fifth;
            $this->repository = $repository;
            $this->transport = $transport;
            $clockCallable = is_callable($sixth) ? $sixth : null;
        }

        $this->clock = $clockCallable === null
            ? static fn (): int => time()
            : Closure::fromCallable($clockCallable);
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
        $limit = ($deliveryLimit > 0) ? $deliveryLimit : $invoiceLimit;
        $report = $this->newReport();

        try {
            $deliveries = $this->repository->claimDueDeliveries($this->now(), $limit);
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
