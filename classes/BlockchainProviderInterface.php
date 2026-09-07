<?php

declare(strict_types=1);

namespace BtcPayLite;

interface BlockchainProviderInterface
{
    /** Hard upper bound for a complete observeAddress operation, including waits. */
    public function maxObservationDurationSeconds(): int;

    /**
     * Observes current address balance on the blockchain without loading or locking any wallet.
     *
     * @param string $address Valid Bitcoin address
     * @param int $expectedSatoshis Expected amount in satoshis (must be >= 0)
     * @return AddressPaymentObservation
     * @throws BlockchainProviderException If observation fails or query is invalid
     */
    public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation;
}
