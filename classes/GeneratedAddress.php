<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Value object representing a newly generated payment address.
 */
class GeneratedAddress
{
    public const SOURCE_XPUB = 'xpub';
    public const SOURCE_ELECTRUM = 'electrum';

    private string $address;
    private string $source;
    private ?int $index;
    private ?string $derivationPath;

    public function __construct(
        string $address,
        string $source,
        ?int $index = null,
        ?string $derivationPath = null
    ) {
        $address = trim($address);
        if ($address === '' || str_contains($address, "\0")) {
            throw new InvalidArgumentException('Address must be a non-empty string without null bytes.');
        }

        $source = strtolower(trim($source));
        if ($source !== self::SOURCE_XPUB && $source !== self::SOURCE_ELECTRUM) {
            throw new InvalidArgumentException("Invalid address source '{$source}'.");
        }

        $this->address = $address;
        $this->source = $source;
        $this->index = $index;
        $this->derivationPath = $derivationPath;
    }

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

    /**
     * @return array{address: string, source: string, index: int|null, derivation_path: string|null}
     */
    public function toArray(): array
    {
        return [
            'address' => $this->address,
            'source' => $this->source,
            'index' => $this->index,
            'derivation_path' => $this->derivationPath,
        ];
    }
}
