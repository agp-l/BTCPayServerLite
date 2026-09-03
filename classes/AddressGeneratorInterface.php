<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Strategy interface for creating Bitcoin payment addresses.
 */
interface AddressGeneratorInterface
{
    public function generate(AddressGenerationContext $context): GeneratedAddress;

    public function getSource(): string;
}
