<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Immutable value object representing a generated Bitcoin payment destination.
 */
final class GeneratedAddress
{
    public function __construct(
        public readonly string $address,
        public readonly string $source,
        public readonly ?int $index = null,
        public readonly ?string $derivationPath = null
    ) {}

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getIndex(): ?int
    {
        return $this->index;
    }

    public function getDerivationPath(): ?string
    {
        return $this->derivationPath;
    }
}
