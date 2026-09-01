<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/PasswordResetMailer.php';

use BtcPayLite\PasswordResetMailer;

$sent = [];
$mailer = new PasswordResetMailer(
    static function (string $to, string $subject, string $message, string $headers) use (&$sent): bool {
        $sent = compact('to', 'subject', 'message', 'headers');
        return true;
    }
);

if (!$mailer->send(
    'client@example.test',
    'https://pay.example.test/reset-password?token=abc',
    'no-reply@example.test'
)) {
    throw new RuntimeException('Valid reset message was rejected.');
}
if (($sent['to'] ?? null) !== 'client@example.test') {
    throw new RuntimeException('Reset message recipient mismatch.');
}
if (!str_contains((string) ($sent['message'] ?? ''), 'https://pay.example.test/reset-password')) {
    throw new RuntimeException('Reset URL is missing from message.');
}
if ($mailer->send(
    "victim@example.test\r\nBcc: attacker@example.test",
    'https://pay.example.test/reset-password?token=abc',
    'no-reply@example.test'
)) {
    throw new RuntimeException('Header injection address was accepted.');
}
if ($mailer->send(
    'client@example.test',
    'not-a-url',
    'no-reply@example.test'
)) {
    throw new RuntimeException('Invalid reset URL was accepted.');
}

echo '[PASS] sends a plain-text reset link' . PHP_EOL;
echo '[PASS] rejects invalid mail inputs' . PHP_EOL;
echo '2 PasswordResetMailer tests passed.' . PHP_EOL;
