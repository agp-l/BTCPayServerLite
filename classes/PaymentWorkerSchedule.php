<?php

declare(strict_types=1);
namespace BtcPayLite;

use InvalidArgumentException;

/** Emits reviewable units only. Never installs or starts a scheduler. */
final class PaymentWorkerSchedule
{
    public static function render(string $kind, string $root, string $php, string $user, int $tickSeconds = 600): string
    {
        if ($tickSeconds < 60 || $tickSeconds > 600 || $tickSeconds % 60 !== 0) {
            throw new InvalidArgumentException('Payment tick must be 60–600 seconds in whole minutes. Invoice observations remain at least 600 seconds apart.');
        }
        if ($kind === 'timer') {
            $minutes = intdiv($tickSeconds, 60);
            return "[Unit]\nDescription=BTCPay Lite payment monitoring timer\n\n[Timer]\nOnBootSec={$minutes}min\nOnUnitActiveSec={$minutes}min\nAccuracySec=1s\nUnit=btcpay-lite-payment-worker.service\n\n[Install]\nWantedBy=timers.target\n";
        }
        if ($kind !== 'service' || !preg_match('/\A[a-z_][a-z0-9_-]*\z/i', $user) || $user === 'root') {
            throw new InvalidArgumentException('Use --payment-systemd=service|timer and a non-root --worker-user for the service.');
        }
        foreach ([$root, $php] as $path) {
            // Reject systemd specifiers, environment expansions and directive injection.
            if (!preg_match('~\A/[a-z0-9_./-]+\z~i', $path) || in_array('..', explode('/', $path), true)) {
                throw new InvalidArgumentException('Systemd generator requires absolute paths without spaces or special characters.');
            }
        }
        return "[Unit]\nDescription=BTCPay Lite payment monitoring\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nUser=$user\nWorkingDirectory=$root\nExecStart=$php $root/payment_worker.php\nTimeoutStartSec=90\nNoNewPrivileges=true\n";
    }
}
