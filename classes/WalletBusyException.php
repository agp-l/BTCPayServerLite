<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;

class WalletBusyException extends RuntimeException
{
    private int $retryAfterSeconds;

    public function __construct(
        string $message = 'Wallet is busy. Please retry shortly.',
        int $retryAfterSeconds = 2,
        int $code = 503
    ) {
        parent::__construct($message, $code);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
