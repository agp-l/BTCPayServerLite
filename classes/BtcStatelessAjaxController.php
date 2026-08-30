<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Maps stateless-invoice HTTP input to application-service calls and JSON data.
 */
class BtcStatelessAjaxController
{
    private BtcStatelessService $service;
    private string $defaultWallet;
    private string $baseUri;

    public function __construct(BtcStatelessService $service, string $defaultWallet, string $baseUri)
    {
        $this->service = $service;
        $this->defaultWallet = trim($defaultWallet);
        $this->baseUri = rtrim($baseUri, '/\\');
    }

    /**
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    public function handleRequest(array $postData): array
    {
        $action = $postData['api_action'] ?? '';
        if (!is_string($action)) {
            throw new BtcStatelessServiceException('API action is invalid.', 'handle_request', 400);
        }

        return match ($action) {
            'create' => $this->handleCreate($postData),
            'check_status' => $this->handleCheckStatus($postData),
            default => throw new BtcStatelessServiceException('Unknown API action.', 'handle_request', 400),
        };
    }

    /**
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    private function handleCreate(array $postData): array
    {
        $selectedWallet = $postData['wallet'] ?? $this->defaultWallet;
        if (!is_string($selectedWallet)) {
            throw new BtcStatelessServiceException('Wallet name is invalid.', 'create_invoice', 400);
        }

        $result = $this->service->createInvoiceAsAdmin($postData, $selectedWallet);

        return [
            'status' => 'ok',
            'url' => $this->paymentUrl($result['token']),
            'token' => $result['token'],
            'amount' => $result['amount'],
            'desc' => $result['description'],
            'order_id' => $result['order_id'],
            'wallet' => $result['wallet'],
            'time' => time(),
            'expires_in_minutes' => $result['expires_in_minutes'],
        ];
    }

    /**
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    private function handleCheckStatus(array $postData): array
    {
        $token = $postData['token'] ?? null;
        if (!is_string($token) || trim($token) === '') {
            throw new BtcStatelessServiceException('Invoice token is required.', 'check_status', 400);
        }

        $statusData = $this->service->checkStatus($token);
        $invoice = $statusData['invoice'] ?? null;
        $payment = $statusData['payment'] ?? null;
        if (!is_array($invoice) || !is_array($payment)) {
            throw new BtcStatelessServiceException('Payment status response is invalid.', 'check_status');
        }

        $customData = $invoice['p'] ?? [];
        if (!is_array($customData)) {
            $customData = [];
        }

        return [
            'status' => 'ok',
            'payment_status' => $this->requiredResponseString($statusData['status'] ?? null, 'payment status'),
            'missing_amount' => $this->requiredResponseString($payment['missing_amount'] ?? null, 'missing amount'),
            'amount' => $this->requiredResponseString($invoice['v'] ?? null, 'invoice amount'),
            'desc' => is_string($invoice['d'] ?? null) ? $invoice['d'] : '',
            'order_id' => is_string($customData['order_id'] ?? null) ? $customData['order_id'] : '',
            'wallet' => is_string($customData['wallet'] ?? null) ? $customData['wallet'] : $this->defaultWallet,
            'time' => is_int($invoice['t'] ?? null) ? $invoice['t'] : time(),
            'url' => $this->paymentUrl($token),
            'token' => $token,
        ];
    }

    private function paymentUrl(string $token): string
    {
        return $this->baseUri . '/url_pay.php?inv=' . rawurlencode($token);
    }

    private function requiredResponseString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new BtcStatelessServiceException("Payment status {$field} is invalid.", 'check_status');
        }

        return $value;
    }
}
