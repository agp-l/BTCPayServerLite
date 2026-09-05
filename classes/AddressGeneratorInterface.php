<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Common interface for Bitcoin address generation strategies.
 */
interface AddressGeneratorInterface
{
    /**
     * Generates a new, unused Bitcoin address according to the generator strategy.
     *
     * @throws AddressGenerationException on failure
     */
    public function generateAddress(AddressGenerationContext $context): GeneratedAddress;
}
