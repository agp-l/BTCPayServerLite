<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Encapsulates verified on-chain payment observations for an address.
 *
 * Uses integer satoshis throughout to eliminate binary floating-point rounding inaccuracies.
 */
final class AddressPaymentObservation
{
    /**
     * @param list<array<string, mixed>> $transactions
     */
    public function __construct(
        public readonly int $confirmedReceivedSats,
        public readonly int $unconfirmedReceivedSats,
        public readonly array $transactions = [],
        public readonly int $observedAt = 0
    ) {}

    public function getTotalReceivedSats(): int
    {
        return $this->confirmedReceivedSats + $this->unconfirmedReceivedSats;
    }

    public function hasPaid(int $expectedSats): bool
    {
        return $this->getTotalReceivedSats() >= $expectedSats;
    }

    public function isConfirmed(int $expectedSats): bool
    {
        return $this->confirmedReceivedSats >= $expectedSats;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'confirmed_received_sats' => $this->confirmedReceivedSats,
            'unconfirmed_received_sats' => $this->unconfirmedReceivedSats,
            'total_received_sats' => $this->getTotalReceivedSats(),
            'transactions' => $this->transactions,
            'observed_at' => $this->observedAt ?: time(),
        ];
    }
}
