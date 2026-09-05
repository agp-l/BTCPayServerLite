<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

class AddressGenerationException extends RuntimeException
{
    private string $source;

    public function __construct(
        string $message,
        string $source = 'unknown',
        int $code = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->source = $source;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
