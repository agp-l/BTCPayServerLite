<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

/**
 * Safe failure raised while preparing or delivering a webhook.
 */
class WebhookDeliveryException extends RuntimeException
{
    private string $operation;
    private bool $retryable;
    private ?int $httpStatus;

    public function __construct(
        string $message,
        string $operation,
        bool $retryable = false,
        ?int $httpStatus = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->operation = $operation;
        $this->retryable = $retryable;
        $this->httpStatus = $httpStatus;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
