<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use Throwable;

/**
 * Authenticates a cron invocation before constructing or running services.
 */
class WebhookCronController
{
    private string $cronKey;
    private Closure $runner;

    public function __construct(string $cronKey, callable $runner)
    {
        $cronKey = trim($cronKey);
        if ($cronKey === '' || strlen($cronKey) < 16 || strlen($cronKey) > 1_024) {
            throw new WebhookDeliveryException(
                'Cron authentication configuration is invalid.',
                'configure_cron'
            );
        }

        $this->cronKey = $cronKey;
        $this->runner = Closure::fromCallable($runner);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function handleServerRequest(array $server, bool $isCli): array
    {
        if (!$isCli) {
            $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? ''));
            if ($method !== 'POST') {
                return [
                    'status_code' => 405,
                    'body' => ['message' => 'Method not allowed.'],
                ];
            }

            $authorization = $server['HTTP_AUTHORIZATION']
                ?? $server['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;
            if (!is_string($authorization) || !$this->isAuthorized($authorization)) {
                return [
                    'status_code' => 401,
                    'body' => ['message' => 'Unauthorized.'],
                ];
            }
        }

        try {
            $report = ($this->runner)();
            if (!is_array($report)) {
                throw new WebhookDeliveryException(
                    'Webhook runner returned an invalid report.',
                    'run_cron'
                );
            }

            return [
                'status_code' => 200,
                'body' => ['status' => 'success', 'report' => $report],
            ];
        } catch (Throwable $exception) {
            $logMessage = $exception instanceof WebhookDeliveryException
                ? $exception->getMessage()
                : 'Unexpected ' . get_class($exception);
            error_log('Webhook cron failed: ' . $logMessage);

            return [
                'status_code' => 500,
                'body' => ['message' => 'Webhook processing failed.'],
            ];
        }
    }

    private function isAuthorized(string $authorization): bool
    {
        if (!preg_match('/\ABearer[ \t]+([^\s]+)\z/iD', trim($authorization), $matches)) {
            return false;
        }

        return hash_equals(
            hash('sha256', $this->cronKey, true),
            hash('sha256', $matches[1], true)
        );
    }
}
