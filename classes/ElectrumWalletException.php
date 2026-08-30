<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

/**
 * Indicates invalid state or an invalid response at the wallet abstraction.
 */
class ElectrumWalletException extends RuntimeException
{
    private string $operation;

    public function __construct(
        string $message,
        string $operation,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->operation = $operation;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }
}
