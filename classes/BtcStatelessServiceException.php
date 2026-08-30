<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

/**
 * Domain exception for stateless invoice service and controller boundaries.
 */
class BtcStatelessServiceException extends RuntimeException
{
    private string $operation;

    public function __construct(
        string $message,
        string $operation,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->operation = $operation;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }
}
