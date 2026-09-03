<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Carries request and store context into the AddressGenerator.
 */
final class AddressGenerationContext
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $storeId,
        public readonly ?string $invoiceId = null,
        public readonly ?int $amountSats = null,
        public readonly ?int $expirationSeconds = null,
        public readonly ?string $preferredSource = null,
        public readonly ?string $walletPath = null,
        public readonly array $metadata = []
    ) {}

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function getInvoiceId(): ?string
    {
        return $this->invoiceId;
    }

    public function getAmountSats(): ?int
    {
        return $this->amountSats;
    }

    public function getExpirationSeconds(): ?int
    {
        return $this->expirationSeconds;
    }

    public function getPreferredSource(): ?string
    {
        return $this->preferredSource;
    }

    public function getWalletPath(): ?string
    {
        return $this->walletPath;
    }
}
