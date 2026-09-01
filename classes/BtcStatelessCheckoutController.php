<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Read-only boundary for the public stateless payment page.
 */
final class BtcStatelessCheckoutController
{
    private const MAX_TOKEN_BYTES = 4_096;

    private BtcStatelessService $service;
    private CheckoutQrCodeGenerator $qrCodeGenerator;

    public function __construct(
        BtcStatelessService $service,
        ?CheckoutQrCodeGenerator $qrCodeGenerator = null
    ) {
        $this->service = $service;
        $this->qrCodeGenerator = $qrCodeGenerator ?? new CheckoutQrCodeGenerator();
    }

    /** @return array<string, mixed> */
    public function paymentPage(string $token): array
    {
        $checkout = $this->resolve($token);
        $checkout['qr_code_data_uri'] = $this->qrCodeGenerator->generateDataUri(
            $checkout['bip21_uri']
        );

        return $checkout;
    }

    /** @return array<string, mixed> */
    public function paymentStatus(string $token): array
    {
        $checkout = $this->resolve($token);

        return [
            'status' => $checkout['status'],
            'received_amount' => $checkout['received_amount'],
            'missing_amount' => $checkout['missing_amount'],
            'seconds_remaining' => $checkout['seconds_remaining'],
            'is_expired' => $checkout['is_expired'],
        ];
    }

    /** @return array<string, mixed> */
    private function resolve(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new BtcStatelessServiceException(
                'Invoice token is invalid.',
                'load_payment_page',
                400
            );
        }

        $result = $this->service->getPaymentPageData($token);
        $invoice = $result['invoice'] ?? null;
        $payment = $result['payment'] ?? null;
        if (!is_array($invoice) || !is_array($payment)) {
            throw new BtcStatelessServiceException(
                'Invoice status response is invalid.',
                'load_payment_page'
            );
        }

        $customData = is_array($invoice['p'] ?? null) ? $invoice['p'] : [];
        $status = $this->requiredString($result['status'] ?? null, 'status');
        if (!in_array($status, ['unpaid', 'underpaid', 'pending_mempool', 'paid', 'expired'], true)) {
            throw new BtcStatelessServiceException(
                'Invoice payment status is invalid.',
                'load_payment_page'
            );
        }

        return [
            'token' => $token,
            'status' => $status,
            'is_expired' => (bool) ($result['is_expired'] ?? false),
            'seconds_remaining' => max(0, (int) ($result['seconds_remaining'] ?? 0)),
            'address' => $this->requiredString($invoice['a'] ?? null, 'address'),
            'amount' => $this->requiredString($invoice['v'] ?? null, 'amount'),
            'description' => is_string($invoice['d'] ?? null) && trim($invoice['d']) !== ''
                ? trim($invoice['d'])
                : 'Bitcoin invoice',
            'order_id' => is_string($customData['order_id'] ?? null)
                ? trim($customData['order_id'])
                : '',
            'created_at' => is_int($invoice['t'] ?? null) ? $invoice['t'] : 0,
            'expires_at' => is_int($invoice['e'] ?? null) ? $invoice['e'] : 0,
            'received_amount' => $this->requiredString(
                $payment['received_total'] ?? null,
                'received amount'
            ),
            'missing_amount' => $this->requiredString(
                $payment['missing_amount'] ?? null,
                'missing amount'
            ),
            'bip21_uri' => $this->requiredString($result['bip21_uri'] ?? null, 'BIP21 URI'),
        ];
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new BtcStatelessServiceException(
                "Invoice {$field} is invalid.",
                'load_payment_page'
            );
        }

        return trim($value);
    }
}
