<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

class BlockchainProviderException extends RuntimeException
{
    private string $action;
    private ?string $reason;

    public function __construct(
        string $message,
        string $action = 'observe_address',
        int $code = 500,
        ?Throwable $previous = null,
        ?string $reason = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->action = $action;
        $this->reason = in_array($reason, ['cache_directory','cache_lock_open','cache_lock_timeout',
            'cache_write','upstream_backoff','invalid_balance'], true) ? $reason : null;
    }

    public function getReason(): ?string { return $this->reason; }

    public function getAction(): string
    {
        return $this->action;
    }
}
