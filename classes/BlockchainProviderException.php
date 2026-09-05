<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

class BlockchainProviderException extends RuntimeException
{
    private string $action;

    public function __construct(
        string $message,
        string $action = 'observe_address',
        int $code = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->action = $action;
    }

    public function getAction(): string
    {
        return $this->action;
    }
}
