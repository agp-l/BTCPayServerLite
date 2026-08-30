<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Immutable Bitcoin amount represented as integer satoshis.
 *
 * Floating-point values are accepted only for backwards compatibility and
 * only when converting them to eight decimal places does not change them.
 */
final class BitcoinAmount
{
    public const SATOSHIS_PER_BTC = 100_000_000;
    public const MAX_SATOSHIS = 2_100_000_000_000_000;

    private int $satoshis;

    private function __construct(int $satoshis)
    {
        if ($satoshis < -self::MAX_SATOSHIS || $satoshis > self::MAX_SATOSHIS) {
            throw new InvalidArgumentException('Bitcoin amount is outside the valid monetary range.');
        }

        $this->satoshis = $satoshis;
    }

    public static function fromBtc(int|float|string $amount): self
    {
        if (is_int($amount)) {
            if ($amount < -21_000_000 || $amount > 21_000_000) {
                throw new InvalidArgumentException('Bitcoin amount is outside the valid monetary range.');
            }

            return new self($amount * self::SATOSHIS_PER_BTC);
        }

        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new InvalidArgumentException('Bitcoin amount must be finite.');
            }

            $formatted = number_format($amount, 8, '.', '');
            if ((float) $formatted !== $amount) {
                throw new InvalidArgumentException('Bitcoin amount must not contain sub-satoshi precision.');
            }
            $amount = $formatted;
        } else {
            $amount = trim($amount);
        }

        if (!preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,8}))?\z/D', $amount, $matches)) {
            throw new InvalidArgumentException('Bitcoin amount must be a plain decimal with up to 8 decimal places.');
        }

        $negative = $matches[1] === '-';
        $whole = $matches[2];
        $fraction = str_pad($matches[3] ?? '', 8, '0');

        if (
            strlen($whole) > 8
            || (strlen($whole) === 8 && strcmp($whole, '21000000') > 0)
            || ($whole === '21000000' && (int) $fraction !== 0)
        ) {
            throw new InvalidArgumentException('Bitcoin amount is outside the valid monetary range.');
        }

        $satoshis = ((int) $whole * self::SATOSHIS_PER_BTC) + (int) $fraction;
        if ($negative) {
            $satoshis *= -1;
        }

        return new self($satoshis);
    }

    public static function fromSatoshis(int $satoshis): self
    {
        return new self($satoshis);
    }

    public function satoshis(): int
    {
        return $this->satoshis;
    }

    public function toBtcString(): string
    {
        $negative = $this->satoshis < 0;
        $absolute = abs($this->satoshis);
        $whole = intdiv($absolute, self::SATOSHIS_PER_BTC);
        $fraction = $absolute % self::SATOSHIS_PER_BTC;

        return sprintf('%s%d.%08d', $negative ? '-' : '', $whole, $fraction);
    }

    public function add(self $other): self
    {
        return new self($this->satoshis + $other->satoshis);
    }

    public function subtract(self $other): self
    {
        return new self($this->satoshis - $other->satoshis);
    }

    public function compare(self $other): int
    {
        return $this->satoshis <=> $other->satoshis;
    }

    public function isPositive(): bool
    {
        return $this->satoshis > 0;
    }

    public function isZero(): bool
    {
        return $this->satoshis === 0;
    }

    public static function max(self $first, self $second): self
    {
        return $first->compare($second) >= 0 ? $first : $second;
    }
}
