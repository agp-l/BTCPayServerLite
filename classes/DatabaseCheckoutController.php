<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Validates the public checkout request and returns a transport-neutral response.
 */
final class DatabaseCheckoutController
{
    private DatabaseCheckoutService $service;

    public function __construct(DatabaseCheckoutService $service)
    {
        $this->service = $service;
    }

    /**
     * @param array<string,mixed> $query
     * @return array{
     *   status_code:int,
     *   mode:string,
     *   data:array<string,mixed>,
     *   error:?string,
     *   allowed_methods:list<string>
     * }
     */
    public function handle(string $method, array $query): array
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $this->error(405, 'This method is not allowed.', false, ['GET', 'HEAD']);
        }

        $action = $query['action'] ?? '';
        $wantsJson = $action === 'check';
        if (!is_string($action) || !in_array($action, ['', 'check'], true)) {
            return $this->error(400, 'Requested checkout operation is invalid.', false);
        }

        $invoiceId = $query['id'] ?? '';
        if (!is_string($invoiceId)) {
            return $this->error(400, 'Invoice ID is invalid.', $wantsJson);
        }

        try {
            $model = $this->service->load($invoiceId);
        } catch (CheckoutException $exception) {
            return $this->error(
                $exception->getHttpStatus(),
                $exception->getMessage(),
                $wantsJson
            );
        }

        if (!$wantsJson) {
            return [
                'status_code' => 200,
                'mode' => 'html',
                'data' => $model,
                'error' => null,
                'allowed_methods' => [],
            ];
        }

        return [
            'status_code' => 200,
            'mode' => 'json',
            'data' => [
                'id' => $model['id'],
                'status' => $model['status'],
                'additional_status' => $model['additional_status'],
                'seconds_remaining' => $model['seconds_remaining'],
                'total_received' => $model['total_received'],
                'missing_amount' => $model['missing_amount'],
            ],
            'error' => null,
            'allowed_methods' => [],
        ];
    }

    /**
     * @param list<string> $allowedMethods
     * @return array{
     *   status_code:int,
     *   mode:string,
     *   data:array<string,mixed>,
     *   error:string,
     *   allowed_methods:list<string>
     * }
     */
    private function error(
        int $status,
        string $message,
        bool $json,
        array $allowedMethods = []
    ): array {
        return [
            'status_code' => $status,
            'mode' => $json ? 'json' : 'html',
            'data' => $json ? ['message' => $message] : [],
            'error' => $message,
            'allowed_methods' => $allowedMethods,
        ];
    }
}
