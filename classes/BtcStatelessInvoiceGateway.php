<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Minimal contract required by the portable stateless-invoice application layer.
 *
 * Implementations must not require an invoice database. The legacy
 * BtcInvoiceManager implements this contract as a backwards-compatible facade.
 */
interface BtcStatelessInvoiceGateway
{
    /**
     * @param array<string, mixed> $customData
     * @return array{token: string, bip21_uri: string}
     */
    public function createStatelessInvoice(
        int|float|string $amountBtc,
        string $description,
        array $customData = [],
        int $expirationMinutes = 15
    ): array;

    /** @return array<string, mixed> */
    public function decodeStatelessToken(string $token): array;

    /** @return array<string, mixed> */
    public function checkStatelessPaymentStatus(string $token): array;
}
