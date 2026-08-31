<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

final class AdminOperationsException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
