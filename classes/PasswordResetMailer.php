<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;

final class PasswordResetMailer
{
    private Closure $sender;

    /** @param callable(string,string,string,string):bool|null $sender */
    public function __construct(?callable $sender = null)
    {
        $this->sender = $sender === null
            ? static fn (string $to, string $subject, string $message, string $headers): bool =>
                mail($to, $subject, $message, $headers)
            : Closure::fromCallable($sender);
    }

    public function send(string $email, string $resetUrl, string $from): bool
    {
        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || filter_var($from, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $email . $from)
            || !filter_var($resetUrl, FILTER_VALIDATE_URL)
        ) {
            return false;
        }

        $subject = 'BTCPay Lite Password Reset';
        $message = "A password reset was requested for your account.\n\n"
            . "This link is valid for 30 minutes and can only be used once:\n"
            . $resetUrl . "\n\n"
            . "If you did not request this, please ignore this message.\n";
        $headers = "From: BTCPay Lite <{$from}>\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "X-Auto-Response-Suppress: All";

        return (bool) ($this->sender)($email, $subject, $message, $headers);
    }
}
