<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;

final class RouterException extends RuntimeException
{
    private int $httpStatus;
    /** @var list<string> */
    private array $allowedMethods;

    /** @param list<string> $allowedMethods */
    public function __construct(string $message, int $httpStatus, array $allowedMethods = [])
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->allowedMethods = array_values($allowedMethods);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return list<string> */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
