<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Interface for decoupled on-chain payment monitoring without wallet dependencies.
 */
interface BlockchainProviderInterface
{
    public function getAddressObservation(string $address): AddressPaymentObservation;

    public function getProviderName(): string;
}
