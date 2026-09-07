<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/** The only owner of allowed invoice status transitions. */
final class InvoiceStateMachine
{
    private const ALLOWED = [
        'New' => ['New', 'Processing', 'Expired', 'Settled'],
        'Processing' => ['Processing', 'Settled'],
        'Expired' => ['Expired', 'Processing', 'Settled'],
        'Settled' => ['Settled'],
    ];

    public static function next(string $current, int $expectedSats, AddressPaymentObservation $observation, int $expiresAt, int $now, bool $previousPaymentObserved = false): string
    {
        if (!isset(self::ALLOWED[$current]) || $expectedSats <= 0) {
            throw new InvalidArgumentException('Invalid invoice state or amount.');
        }
        if ($current === 'Settled') {
            return 'Settled';
        }
        if ($observation->getConfirmedBalanceSatoshis() >= $expectedSats) {
            return 'Settled';
        }
        // Preserve evidence of any earlier partial payment, even if it has since
        // left the mempool or been spent. Do not invent cumulative amounts.
        if ($current === 'Processing' || $previousPaymentObserved || $observation->getCurrentBalanceSatoshis() > 0) {
            return 'Processing';
        }
        return $current === 'Expired' || $now >= $expiresAt ? 'Expired' : 'New';
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw new InvalidArgumentException("Invoice transition {$from} -> {$to} is forbidden.");
        }
    }

    public static function eventFor(string $status): ?string
    {
        return match ($status) {
            'Processing' => 'InvoiceProcessing',
            'Settled' => 'InvoiceSettled',
            'Expired' => 'InvoiceExpired',
            default => null,
        };
    }
}
