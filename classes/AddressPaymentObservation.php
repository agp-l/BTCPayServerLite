<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Current address balance, NOT cumulative outputs received over its history.
 * Confirmed balance is non-negative; mempool delta is signed (outgoing spends
 * can reduce the confirmed balance). All amounts are integer satoshis.
 */
final class AddressPaymentObservation
{
    public function __construct(
        private string $address,
        private int $confirmedBalanceSatoshis,
        private int $mempoolDeltaSatoshis,
        private int $currentBalanceSatoshis,
        private int $observedAt
    ) {
        if (trim($address) === '' || $confirmedBalanceSatoshis < 0 || $currentBalanceSatoshis < 0
            || $currentBalanceSatoshis !== $confirmedBalanceSatoshis + $mempoolDeltaSatoshis
            || $observedAt < 1) {
            throw new InvalidArgumentException('Invalid current address balance observation.');
        }
    }

    public function getAddress(): string { return $this->address; }
    public function getConfirmedBalanceSatoshis(): int { return $this->confirmedBalanceSatoshis; }
    public function getMempoolDeltaSatoshis(): int { return $this->mempoolDeltaSatoshis; }
    public function getCurrentBalanceSatoshis(): int { return $this->currentBalanceSatoshis; }
    public function getObservedAt(): int { return $this->observedAt; }

    /** @return array{confirmed: BitcoinAmount, received: BitcoinAmount} Legacy presentation aliases. */
    public function toAmountArray(): array
    {
        return [
            'confirmed' => BitcoinAmount::fromSatoshis($this->confirmedBalanceSatoshis),
            'received' => BitcoinAmount::fromSatoshis($this->currentBalanceSatoshis),
        ];
    }
}
