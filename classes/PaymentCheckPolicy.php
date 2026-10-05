<?php

declare(strict_types=1);

namespace BtcPayLite;

/** Shared conservative cadence for invoice observations, never browser refreshes. */
final class PaymentCheckPolicy
{
    public const MIN_INTERVAL = 600;
    public const MAX_INTERVAL = 3600;
    public const CLI_STALE_AFTER = 1800;

    public static function interval(int $createdAt, int $now, string $status = 'New'): int
    {
        $age = max(0, $now - $createdAt);
        if (in_array($status, ['Expired', 'expired'], true) || $age >= 21600) {
            return self::MAX_INTERVAL;
        }
        return $age >= 3600 ? 1800 : self::MIN_INTERVAL;
    }

    public static function nextCheck(int $createdAt, int $now, string $status): ?int
    {
        return $status === 'Settled' ? null : $now + self::interval($createdAt, $now, $status);
    }

    public static function customerNotice(): string
    {
        return 'Příchozí platby kontrolujeme každých 10 až 60 minut. Po odeslání se proto úhrada nemusí zobrazit ihned. '
            . 'Ověření může trvat přibližně hodinu, při pomalém potvrzování v bitcoinové síti i několik hodin. '
            . 'Stejnou platbu neposílejte znovu.';
    }
}
