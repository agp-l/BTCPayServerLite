<?php

declare(strict_types=1);

namespace BtcPayLite;

use JsonException;
use PDO;
use Throwable;

/**
 * Persistence boundary for invoice monitoring and webhook outbox deliveries.
 */
class WebhookDeliveryRepository
{
    private const EVENT_TYPES = [
        'InvoiceProcessing',
        'InvoiceSettled',
        'InvoiceExpired',
    ];

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * @return list<array{id: string, store_id: string, status: string, wallet_path: string}>
     */
    public function findActiveInvoices(int $limit): array
    {
        $limit = $this->validateLimit($limit);

        try {
            $statement = $this->database->getPdo()->prepare(
                "SELECT invoice.id, invoice.store_id, invoice.status, store.wallet_path
                   FROM invoices AS invoice
                   JOIN stores AS store ON store.id = invoice.store_id
                  WHERE invoice.status IN ('New', 'Processing')
               ORDER BY invoice.expires_at, invoice.id
                  LIMIT :batch_limit"
            );
            $statement->bindValue(':batch_limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            return $this->normalizeInvoices($statement->fetchAll(PDO::FETCH_ASSOC));
        } catch (WebhookDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WebhookDeliveryException(
                'Active invoices could not be loaded.',
                'find_active_invoices',
                true,
                null,
                $exception
            );
        }
    }

    /**
     * Finds terminal invoices whose current event is absent from at least one
     * registered webhook outbox. This repairs the crash window between an
     * invoice status update and outbox insertion.
     *
     * @return list<array{id: string, store_id: string, status: string, wallet_path: string}>
     */
    public function findTerminalInvoicesMissingDeliveries(int $limit): array
    {
        $limit = $this->validateLimit($limit);

        try {
            $statement = $this->database->getPdo()->prepare(
                "SELECT DISTINCT invoice.id, invoice.store_id, invoice.status, store.wallet_path
                   FROM invoices AS invoice
                   JOIN stores AS store ON store.id = invoice.store_id
                   JOIN webhooks AS webhook ON webhook.store_id = invoice.store_id
              LEFT JOIN webhook_deliveries AS delivery
                     ON delivery.webhook_id = webhook.id
                    AND delivery.invoice_id = invoice.id
                    AND delivery.event_type = CASE invoice.status
                        WHEN 'Settled' THEN 'InvoiceSettled'
                        WHEN 'Expired' THEN 'InvoiceExpired'
                    END
                  WHERE invoice.status IN ('Settled', 'Expired')
                    AND delivery.id IS NULL
               ORDER BY invoice.id
                  LIMIT :batch_limit"
            );
            $statement->bindValue(':batch_limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            return $this->normalizeInvoices($statement->fetchAll(PDO::FETCH_ASSOC));
        } catch (WebhookDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WebhookDeliveryException(
                'Terminal invoice deliveries could not be reconciled.',
                'find_missing_deliveries',
                true,
                null,
                $exception
            );
        }
    }

    public function ensureDeliveries(
        string $invoiceId,
        string $storeId,
        string $eventType,
        int $timestamp
    ): int {
        $invoiceId = $this->validateIdentifier($invoiceId, 'Invoice ID');
        $storeId = $this->validateIdentifier($storeId, 'Store ID');
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new WebhookDeliveryException('Webhook event type is invalid.', 'enqueue_delivery');
        }
        if ($timestamp < 1) {
            throw new WebhookDeliveryException('Webhook event timestamp is invalid.', 'enqueue_delivery');
        }

        try {
            return $this->database->transactional(
                function (PDO $pdo) use ($invoiceId, $storeId, $eventType, $timestamp): int {
                    $statement = $pdo->prepare(
                        'SELECT id FROM webhooks WHERE store_id = ? ORDER BY id'
                    );
                    $statement->execute([$storeId]);
                    $webhookIds = $statement->fetchAll(PDO::FETCH_COLUMN);
                    $created = 0;

                    foreach ($webhookIds as $webhookId) {
                        if (!is_string($webhookId) || $webhookId === '') {
                            throw new WebhookDeliveryException(
                                'Stored webhook ID is invalid.',
                                'enqueue_delivery'
                            );
                        }

                        $deliveryId = 'wd_' . bin2hex(random_bytes(16));
                        $payload = $this->encodePayload([
                            'deliveryId' => $deliveryId,
                            'storeId' => $storeId,
                            'invoiceId' => $invoiceId,
                            'type' => $eventType,
                            'timestamp' => $timestamp,
                        ]);
                        $insert = $pdo->prepare(
                            "INSERT INTO webhook_deliveries
                                (id, webhook_id, invoice_id, event_type, payload,
                                 status, attempts, next_attempt_at, created_at)
                             VALUES (?, ?, ?, ?, ?, 'Pending', 0, ?, ?)
                             ON DUPLICATE KEY UPDATE id = id"
                        );
                        $insert->execute([
                            $deliveryId,
                            $webhookId,
                            $invoiceId,
                            $eventType,
                            $payload,
                            $timestamp,
                            $timestamp,
                        ]);
                        if ($insert->rowCount() === 1) {
                            ++$created;
                        }
                    }

                    return $created;
                }
            );
        } catch (WebhookDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WebhookDeliveryException(
                'Webhook deliveries could not be queued.',
                'enqueue_delivery',
                true,
                null,
                $exception
            );
        }
    }

    /**
     * Atomically claims due rows using a random token. This works on MariaDB
     * 10.4 without relying on SKIP LOCKED.
     *
     * @return list<array{
     *   id: string,
     *   webhook_id: string,
     *   invoice_id: string,
     *   event_type: string,
     *   payload: string,
     *   attempts: int,
     *   url: string,
     *   secret: string,
     *   lock_token: string
     * }>
     */
    public function claimDueDeliveries(int $now, int $limit, int $staleAfterSeconds = 300): array
    {
        if ($now < 1 || $staleAfterSeconds < 30 || $staleAfterSeconds > 3_600) {
            throw new WebhookDeliveryException('Delivery claim timing is invalid.', 'claim_deliveries');
        }
        $limit = $this->validateLimit($limit);
        $staleBefore = max(1, $now - $staleAfterSeconds);
        $lockToken = bin2hex(random_bytes(16));

        try {
            $pdo = $this->database->getPdo();
            $statement = $pdo->prepare(
                "UPDATE webhook_deliveries
                    SET status = 'Processing',
                        lock_token = ?,
                        locked_at = ?,
                        attempts = attempts + 1
                  WHERE (
                         (status IN ('Pending', 'Retry') AND next_attempt_at <= ?)
                      OR (status = 'Processing' AND locked_at <= ?)
                        )
               ORDER BY next_attempt_at, id
                  LIMIT {$limit}"
            );
            $statement->execute([$lockToken, $now, $now, $staleBefore]);

            $statement = $pdo->prepare(
                "SELECT delivery.id, delivery.webhook_id, delivery.invoice_id,
                        delivery.event_type, delivery.payload, delivery.attempts,
                        webhook.url, webhook.secret, delivery.lock_token
                   FROM webhook_deliveries AS delivery
                   JOIN webhooks AS webhook ON webhook.id = delivery.webhook_id
                  WHERE delivery.status = 'Processing'
                    AND delivery.lock_token = ?
               ORDER BY delivery.next_attempt_at, delivery.id"
            );
            $statement->execute([$lockToken]);

            return $this->normalizeDeliveries($statement->fetchAll(PDO::FETCH_ASSOC));
        } catch (WebhookDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WebhookDeliveryException(
                'Due webhook deliveries could not be claimed.',
                'claim_deliveries',
                true,
                null,
                $exception
            );
        }
    }

    public function markDelivered(
        string $deliveryId,
        string $lockToken,
        int $deliveredAt,
        int $httpStatus,
        string $primaryIp
    ): void {
        if ($deliveredAt < 1 || $httpStatus < 200 || $httpStatus >= 300) {
            throw new WebhookDeliveryException('Successful delivery result is invalid.', 'complete_delivery');
        }
        if (filter_var($primaryIp, FILTER_VALIDATE_IP) === false) {
            throw new WebhookDeliveryException('Webhook destination IP is invalid.', 'complete_delivery');
        }

        $this->finishDelivery(
            $deliveryId,
            $lockToken,
            "status = 'Delivered', next_attempt_at = ?, delivered_at = ?,
             last_http_status = ?, last_primary_ip = ?, last_error = NULL",
            [$deliveredAt, $deliveredAt, $httpStatus, $primaryIp]
        );
    }

    public function markFailed(
        string $deliveryId,
        string $lockToken,
        bool $retry,
        int $nextAttemptAt,
        ?int $httpStatus,
        string $error
    ): void {
        if ($nextAttemptAt < 1 || ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599))) {
            throw new WebhookDeliveryException('Failed delivery result is invalid.', 'complete_delivery');
        }

        $status = $retry ? 'Retry' : 'Dead';
        $this->finishDelivery(
            $deliveryId,
            $lockToken,
            'status = ?, next_attempt_at = ?, delivered_at = NULL,
             last_http_status = ?, last_primary_ip = NULL, last_error = ?',
            [$status, $nextAttemptAt, $httpStatus, $this->sanitizeError($error)]
        );
    }

    /**
     * @param list<mixed> $values
     */
    private function finishDelivery(
        string $deliveryId,
        string $lockToken,
        string $assignments,
        array $values
    ): void {
        $deliveryId = $this->validateIdentifier($deliveryId, 'Delivery ID');
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $lockToken)) {
            throw new WebhookDeliveryException('Delivery lock token is invalid.', 'complete_delivery');
        }

        try {
            $statement = $this->database->getPdo()->prepare(
                "UPDATE webhook_deliveries
                    SET {$assignments}, lock_token = NULL, locked_at = NULL
                  WHERE id = ? AND status = 'Processing' AND lock_token = ?"
            );
            $values[] = $deliveryId;
            $values[] = $lockToken;
            $statement->execute($values);
            if ($statement->rowCount() !== 1) {
                throw new WebhookDeliveryException(
                    'Webhook delivery claim is no longer owned by this worker.',
                    'complete_delivery',
                    true
                );
            }
        } catch (WebhookDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WebhookDeliveryException(
                'Webhook delivery result could not be stored.',
                'complete_delivery',
                true,
                null,
                $exception
            );
        }
    }

    /**
     * @param mixed $rows
     * @return list<array{id: string, store_id: string, status: string, wallet_path: string}>
     */
    private function normalizeInvoices(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new WebhookDeliveryException('Stored invoice list is invalid.', 'normalize_invoices');
        }

        $invoices = [];
        foreach ($rows as $row) {
            if (
                !is_array($row)
                || !is_string($row['id'] ?? null)
                || !is_string($row['store_id'] ?? null)
                || !is_string($row['status'] ?? null)
                || !is_string($row['wallet_path'] ?? null)
            ) {
                throw new WebhookDeliveryException('Stored invoice data is invalid.', 'normalize_invoices');
            }
            $invoices[] = [
                'id' => $row['id'],
                'store_id' => $row['store_id'],
                'status' => $row['status'],
                'wallet_path' => $row['wallet_path'],
            ];
        }

        return $invoices;
    }

    /**
     * @param mixed $rows
     * @return list<array{
     *   id: string,
     *   webhook_id: string,
     *   invoice_id: string,
     *   event_type: string,
     *   payload: string,
     *   attempts: int,
     *   url: string,
     *   secret: string,
     *   lock_token: string
     * }>
     */
    private function normalizeDeliveries(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new WebhookDeliveryException('Stored delivery list is invalid.', 'normalize_deliveries');
        }

        $deliveries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new WebhookDeliveryException('Stored delivery data is invalid.', 'normalize_deliveries');
            }

            $attempts = $row['attempts'] ?? null;
            if (is_string($attempts) && ctype_digit($attempts)) {
                $attempts = (int) $attempts;
            }
            if (
                !is_string($row['id'] ?? null)
                || !is_string($row['webhook_id'] ?? null)
                || !is_string($row['invoice_id'] ?? null)
                || !is_string($row['event_type'] ?? null)
                || !is_string($row['payload'] ?? null)
                || !is_int($attempts)
                || $attempts < 1
                || !is_string($row['url'] ?? null)
                || !is_string($row['secret'] ?? null)
                || !is_string($row['lock_token'] ?? null)
            ) {
                throw new WebhookDeliveryException('Stored delivery data is invalid.', 'normalize_deliveries');
            }
            if (
                !preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $row['id'])
                || !preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $row['webhook_id'])
                || !preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $row['invoice_id'])
                || !in_array($row['event_type'], self::EVENT_TYPES, true)
                || $row['payload'] === ''
                || strlen($row['payload']) > 65_536
                || $row['url'] === ''
                || strlen($row['url']) > 2_048
                || $row['secret'] === ''
                || strlen($row['secret']) > 255
                || !preg_match('/\A[a-f0-9]{32}\z/D', $row['lock_token'])
            ) {
                throw new WebhookDeliveryException('Stored delivery data is invalid.', 'normalize_deliveries');
            }
            $deliveries[] = [
                'id' => $row['id'],
                'webhook_id' => $row['webhook_id'],
                'invoice_id' => $row['invoice_id'],
                'event_type' => $row['event_type'],
                'payload' => $row['payload'],
                'attempts' => $attempts,
                'url' => $row['url'],
                'secret' => $row['secret'],
                'lock_token' => $row['lock_token'],
            ];
        }

        return $deliveries;
    }

    /** @param array<string, mixed> $payload */
    private function encodePayload(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new WebhookDeliveryException(
                'Webhook payload could not be encoded.',
                'enqueue_delivery',
                false,
                null,
                $exception
            );
        }
    }

    private function validateIdentifier(string $value, string $field): string
    {
        $value = trim($value);
        if (!preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $value)) {
            throw new WebhookDeliveryException("{$field} is invalid.", 'validate_delivery');
        }

        return $value;
    }

    private function validateLimit(int $limit): int
    {
        if ($limit < 1 || $limit > 500) {
            throw new WebhookDeliveryException('Webhook batch limit is invalid.', 'validate_delivery');
        }

        return $limit;
    }

    private function sanitizeError(string $error): string
    {
        $error = preg_replace('/[^\x20-\x7E]/', '?', trim($error));
        if (!is_string($error) || $error === '') {
            return 'Webhook delivery failed.';
        }

        return substr($error, 0, 255);
    }
}
