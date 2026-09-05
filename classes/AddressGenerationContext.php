<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Contextual parameters provided to address generators.
 */
class AddressGenerationContext
{
    private string $storeId;
    private ?string $walletPath;
    private string $memo;
    private string $network;
    /** @var array<string, mixed> */
    private array $extra;

    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        string $storeId,
        ?string $walletPath = null,
        string $memo = '',
        string $network = 'mainnet',
        array $extra = []
    ) {
        $this->storeId = $storeId;
        $this->walletPath = $walletPath;
        $this->memo = $memo;
        $this->network = strtolower(trim($network));
        $this->extra = $extra;
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function getWalletPath(): ?string
    {
        return $this->walletPath;
    }

    public function getMemo(): string
    {
        return $this->memo;
    }

    public function getNetwork(): string
    {
        return $this->network;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
