<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Immutable value object representing a blockchain observation of an address.
 *
 * All monetary values are represented strictly as non-negative integer satoshis.
 * History inspection ensures that spent funds do not cause an already settled
 * invoice to revert to an unpaid status.
 */
class AddressPaymentObservation
{
    private string $address;
    private int $confirmedSatoshis;
    private int $unconfirmedSatoshis;
    private int $totalReceivedSatoshis;
    private int $historyCount;
    private int $observedAt;

    public function __construct(
        string $address,
        int $confirmedSatoshis,
        int $unconfirmedSatoshis,
        int $totalReceivedSatoshis = 0,
        int $historyCount = 0,
        ?int $observedAt = null
    ) {
        $address = trim($address);
        if ($address === '') {
            throw new InvalidArgumentException('Address cannot be empty.');
        }
        if ($confirmedSatoshis < 0) {
            throw new InvalidArgumentException('Confirmed satoshis cannot be negative.');
        }
        if ($unconfirmedSatoshis < 0) {
            throw new InvalidArgumentException('Unconfirmed satoshis cannot be negative.');
        }
        if ($totalReceivedSatoshis < 0) {
            throw new InvalidArgumentException('Total received satoshis cannot be negative.');
        }
        if ($historyCount < 0) {
            throw new InvalidArgumentException('History count cannot be negative.');
        }

        $this->address = $address;
        $this->confirmedSatoshis = $confirmedSatoshis;
        $this->unconfirmedSatoshis = $unconfirmedSatoshis;
        // Total received is at least the current balance (confirmed + unconfirmed)
        $this->totalReceivedSatoshis = max($totalReceivedSatoshis, $confirmedSatoshis + $unconfirmedSatoshis);
        $this->historyCount = $historyCount;
        $this->observedAt = $observedAt ?? time();
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getConfirmedSatoshis(): int
    {
        return $this->confirmedSatoshis;
    }

    public function getUnconfirmedSatoshis(): int
    {
        return $this->unconfirmedSatoshis;
    }

    /**
     * Total satoshis confirmed + unconfirmed currently visible.
     */
    public function getCurrentReceivedSatoshis(): int
    {
        return $this->confirmedSatoshis + $this->unconfirmedSatoshis;
    }

    /**
     * Cumulative satoshis received by this address across its entire history.
     */
    public function getTotalReceivedSatoshis(): int
    {
        return $this->totalReceivedSatoshis;
    }

    /**
     * Effective received satoshis for payment evaluation.
     * Takes the maximum of total historical received and currently observed balance.
     */
    public function getEffectiveReceivedSatoshis(): int
    {
        return max($this->totalReceivedSatoshis, $this->getCurrentReceivedSatoshis());
    }

    public function getHistoryCount(): int
    {
        return $this->historyCount;
    }

    public function hasTransactions(): bool
    {
        return $this->historyCount > 0 || $this->getCurrentReceivedSatoshis() > 0;
    }

    public function getObservedAt(): int
    {
        return $this->observedAt;
    }

    public function isConfirmed(int $expectedSatoshis): bool
    {
        if ($expectedSatoshis <= 0) {
            return false;
        }
        return $this->confirmedSatoshis >= $expectedSatoshis
            || $this->totalReceivedSatoshis >= $expectedSatoshis;
    }

    public function isUnconfirmedPaid(int $expectedSatoshis): bool
    {
        if ($expectedSatoshis <= 0) {
            return false;
        }
        return $this->getEffectiveReceivedSatoshis() >= $expectedSatoshis;
    }

    public function isPartiallyPaid(int $expectedSatoshis): bool
    {
        $received = $this->getEffectiveReceivedSatoshis();
        return $received > 0 && $received < $expectedSatoshis;
    }

    /**
     * Backwards-compatibility helper for legacy BitcoinAmount consumers.
     *
     * @return array{confirmed: BitcoinAmount, received: BitcoinAmount}
     */
    public function toAmountArray(): array
    {
        return [
            'confirmed' => BitcoinAmount::fromSatoshis($this->confirmedSatoshis),
            'received' => BitcoinAmount::fromSatoshis($this->getEffectiveReceivedSatoshis()),
        ];
    }
}
