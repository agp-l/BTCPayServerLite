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

        $subject = 'Obnova hesla BTCPay Lite';
        $message = "Byla vyžádána obnova hesla k vašemu účtu.\n\n"
            . "Odkaz je platný 30 minut a lze jej použít pouze jednou:\n"
            . $resetUrl . "\n\n"
            . "Pokud jste požadavek nevytvořili, zprávu ignorujte.\n";
        $headers = "From: BTCPay Lite <{$from}>\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "X-Auto-Response-Suppress: All";

        return (bool) ($this->sender)($email, $subject, $message, $headers);
    }
}
