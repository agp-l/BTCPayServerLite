<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

/**
 * Structured exception raised by the Electrum JSON-RPC transport.
 *
 * The public message intentionally excludes raw response bodies and RPC error
 * data. Callers may inspect the structured fields for internal logging, but
 * should not expose them directly in HTTP responses.
 */
class ElectrumRPCException extends RuntimeException
{
    public const TYPE_TRANSPORT = 'transport';
    public const TYPE_AUTHENTICATION = 'authentication';
    public const TYPE_HTTP = 'http';
    public const TYPE_PROTOCOL = 'protocol';
    public const TYPE_REMOTE = 'remote';

    private string $type;
    private string $rpcMethod;
    private ?int $requestId;
    private ?int $httpStatus;
    private ?int $rpcCode;
    private mixed $rpcData;
    private ?int $curlCode;

    public function __construct(
        string $message,
        string $type,
        string $rpcMethod,
        ?int $requestId = null,
        ?int $httpStatus = null,
        ?int $rpcCode = null,
        mixed $rpcData = null,
        ?int $curlCode = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        $this->type = $type;
        $this->rpcMethod = $rpcMethod;
        $this->requestId = $requestId;
        $this->httpStatus = $httpStatus;
        $this->rpcCode = $rpcCode;
        $this->rpcData = $rpcData;
        $this->curlCode = $curlCode;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRpcMethod(): string
    {
        return $this->rpcMethod;
    }

    public function getRequestId(): ?int
    {
        return $this->requestId;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getRpcCode(): ?int
    {
        return $this->rpcCode;
    }

    public function getRpcData(): mixed
    {
        return $this->rpcData;
    }

    public function getCurlCode(): ?int
    {
        return $this->curlCode;
    }
}
