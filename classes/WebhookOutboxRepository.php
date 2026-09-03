<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Outbox repository for asynchronous, transactional webhook event queuing.
 *
 * Prevents payment monitoring workers from being stalled by slow or unresponsive
 * third-party merchant webhook endpoints.
 */
class WebhookOutboxRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Enqueues a webhook delivery event inside the current database transaction.
     *
     * Idempotency is enforced by unique event key (invoice_id + event_type).
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $invoiceId, string $eventType, array $payload): void
    {
        $pdo = $this->database->getPdo();

        // Find all active webhooks for this invoice's store
        $findWebhooks = $pdo->prepare(
            'SELECT w.id, w.url, w.secret
             FROM webhooks w
             JOIN invoices i ON i.store_id = w.store_id
             WHERE i.id = ?'
        );
        $findWebhooks->execute([$invoiceId]);
        $webhooks = $findWebhooks->fetchAll(PDO::FETCH_ASSOC);

        if (empty($webhooks)) {
            return;
        }

        $now = time();
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ($webhooks as $webhook) {
            $deliveryId = 'del_' . bin2hex(random_bytes(16));
            $webhookId = (string) $webhook['id'];

            try {
                // Insert into webhook_deliveries with status 'Pending'
                $statement = $pdo->prepare(
                    "INSERT INTO webhook_deliveries
                        (id, webhook_id, invoice_id, event_type, payload, status, attempts, next_attempt_at, created_at)
                     VALUES (?, ?, ?, ?, ?, 'Pending', 0, ?, ?)
                     ON DUPLICATE KEY UPDATE id = id"
                );
                $statement->execute([
                    $deliveryId,
                    $webhookId,
                    $invoiceId,
                    $eventType,
                    $jsonPayload,
                    $now,
                    $now,
                ]);
            } catch (Throwable) {
                // Ignore duplicates on unique constraint
            }
        }
    }
}
