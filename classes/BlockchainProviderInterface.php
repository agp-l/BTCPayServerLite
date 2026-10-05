<?php

declare(strict_types=1);

namespace BtcPayLite;

interface BlockchainProviderInterface
{
    /** Hard upper bound for a complete observeAddress operation, including waits. */
    public function maxObservationDurationSeconds(): int;

    /**
     * Observes address payments/balance without loading or locking any wallet.
     * Production receipt observers also expose amounts from transaction history.
     *
     * @param string $address Valid Bitcoin address
     * @param int $expectedSatoshis Expected amount in satoshis (must be >= 0)
     * @return AddressPaymentObservation
     * @throws BlockchainProviderException If observation fails or query is invalid
     */
    public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation;
}
