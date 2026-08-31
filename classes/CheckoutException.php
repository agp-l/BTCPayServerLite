<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Public-safe checkout failure with an HTTP status and stable operation name.
 */
final class CheckoutException extends RuntimeException
{
    private int $httpStatus;
    private string $operation;

    public function __construct(
        string $message,
        int $httpStatus = 400,
        string $operation = 'checkout',
        ?Throwable $previous = null
    ) {
        if ($httpStatus < 400 || $httpStatus > 599) {
            throw new InvalidArgumentException('Checkout HTTP status must describe an error.');
        }

        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->operation = $operation;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }
}
