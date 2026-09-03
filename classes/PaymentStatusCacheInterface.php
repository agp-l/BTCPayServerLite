<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Short-lived cache for on-chain address observations to prevent hammering blockchain providers.
 */
interface PaymentStatusCacheInterface
{
    public function get(string $key): ?AddressPaymentObservation;

    public function put(string $key, AddressPaymentObservation $value, int $ttlSeconds = 5): void;
}
