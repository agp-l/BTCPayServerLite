<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Worker for reliably delivering webhooks with exponential backoff and timeout protections.
 */
class WebhookWorker
{
    private Database $database;
    private int $batchSize;
    private int $timeoutSeconds;

    public function __construct(Database $database, int $batchSize = 20, int $timeoutSeconds = 5)
    {
        $this->database = $database;
        $this->batchSize = max(1, min(50, $batchSize));
        $this->timeoutSeconds = max(1, min(30, $timeoutSeconds));
    }

    public function runOnce(): int
    {
        $deliveries = $this->fetchPendingDeliveries();
        $delivered = 0;

        foreach ($deliveries as $delivery) {
            $this->processDelivery($delivery);
            $delivered++;
        }

        return $delivered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPendingDeliveries(): array
    {
        $pdo = $this->database->getPdo();
        $now = time();

        $select = $pdo->prepare(
            "SELECT d.id, d.webhook_id, d.invoice_id, d.event_type, d.payload, d.attempts,
                    w.url, w.secret
             FROM webhook_deliveries d
             JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.status IN ('Pending', 'Failed')
               AND d.attempts < 10
               AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= ?)
             ORDER BY d.created_at ASC
             LIMIT ?"
        );
        $select->execute([$now, $this->batchSize]);
        return $select->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $delivery
     */
    private function processDelivery(array $delivery): void
    {
        $deliveryId = (string) $delivery['id'];
        $url = (string) $delivery['url'];
        $secret = (string) ($delivery['secret'] ?? '');
        $payload = (string) $delivery['payload'];
        $attempts = (int) ($delivery['attempts'] ?? 0) + 1;
        $now = time();

        // Calculate HMAC signature
        $signature = hash_hmac('sha256', $payload, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'BTCPay-Sig: sha256=' . $signature,
                'User-Agent: BTCPayServerLite-Webhook/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $pdo = $this->database->getPdo();
        $isSuccess = ($httpCode >= 200 && $httpCode < 300);

        if ($isSuccess) {
            $update = $pdo->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'Success',
                     http_code = ?,
                     response_body = ?,
                     attempts = ?,
                     delivered_at = ?
                 WHERE id = ?"
            );
            $update->execute([
                $httpCode,
                substr((string) $response, 0, 1000),
                $attempts,
                $now,
                $deliveryId,
            ]);
        } else {
            // Exponential backoff: 60s, 300s, 900s, 3600s, 14400s...
            $backoffSchedule = [60, 300, 900, 3600, 14400, 43200, 86400];
            $delay = $backoffSchedule[min($attempts - 1, count($backoffSchedule) - 1)];
            $nextAttempt = $now + $delay;
            $errorMsg = $curlError !== '' ? $curlError : "HTTP {$httpCode}";

            $update = $pdo->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'Failed',
                     http_code = ?,
                     response_body = ?,
                     attempts = ?,
                     next_attempt_at = ?,
                     last_error = ?
                 WHERE id = ?"
            );
            $update->execute([
                $httpCode ?: null,
                substr((string) $response, 0, 1000),
                $attempts,
                $nextAttempt,
                substr($errorMsg, 0, 255),
                $deliveryId,
            ]);
        }
    }
}
