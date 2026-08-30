<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

/**
 * Safe, client-facing failure at the Greenfield API boundary.
 */
class GreenfieldApiException extends RuntimeException
{
    private string $operation;

    public function __construct(
        string $message,
        string $operation,
        int $httpStatus = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->operation = $operation;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getHttpStatus(): int
    {
        $status = $this->getCode();

        return $status >= 400 && $status < 600 ? $status : 500;
    }
}
