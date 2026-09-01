<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

final class PayoutException extends RuntimeException
{
    private string $operation;
    private int $httpStatus;

    public function __construct(
        string $message,
        string $operation,
        int $httpStatus = 400,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->operation = $operation;
        $this->httpStatus = $httpStatus;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
