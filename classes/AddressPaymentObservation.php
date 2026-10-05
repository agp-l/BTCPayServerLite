<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Current address balance, optionally accompanied by verified transaction output amounts.
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
        private int $observedAt,
        private ?int $confirmedReceivedSatoshis = null,
        private ?int $unconfirmedReceivedSatoshis = null
    ) {
        if (trim($address) === '' || $confirmedBalanceSatoshis < 0 || $currentBalanceSatoshis < 0
            || $currentBalanceSatoshis !== $confirmedBalanceSatoshis + $mempoolDeltaSatoshis
            || $observedAt < 1
            || ($confirmedReceivedSatoshis === null) !== ($unconfirmedReceivedSatoshis === null)
            || ($confirmedReceivedSatoshis !== null && ($confirmedReceivedSatoshis < 0 || $unconfirmedReceivedSatoshis < 0
                || $confirmedReceivedSatoshis > 2_100_000_000_000_000 - $unconfirmedReceivedSatoshis))) {
            throw new InvalidArgumentException('Invalid current address balance observation.');
        }
    }

    public function getAddress(): string { return $this->address; }
    public function getConfirmedBalanceSatoshis(): int { return $this->confirmedBalanceSatoshis; }
    public function getMempoolDeltaSatoshis(): int { return $this->mempoolDeltaSatoshis; }
    public function getCurrentBalanceSatoshis(): int { return $this->currentBalanceSatoshis; }
    public function getObservedAt(): int { return $this->observedAt; }
    public function getConfirmedReceivedSatoshis(): ?int { return $this->confirmedReceivedSatoshis; }
    public function getUnconfirmedReceivedSatoshis(): ?int { return $this->unconfirmedReceivedSatoshis; }
    public function getConfirmedPaymentSatoshis(): int { return $this->confirmedReceivedSatoshis ?? $this->confirmedBalanceSatoshis; }
    public function getReceivedPaymentSatoshis(): int
    {
        return $this->confirmedReceivedSatoshis === null ? $this->currentBalanceSatoshis
            : $this->confirmedReceivedSatoshis + $this->unconfirmedReceivedSatoshis;
    }

    /** @return array{confirmed: BitcoinAmount, received: BitcoinAmount} Legacy presentation aliases. */
    public function toAmountArray(): array
    {
        return [
            'confirmed' => BitcoinAmount::fromSatoshis($this->getConfirmedPaymentSatoshis()),
            'received' => BitcoinAmount::fromSatoshis($this->getReceivedPaymentSatoshis()),
        ];
    }
}
