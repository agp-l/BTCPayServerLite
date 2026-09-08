<?php

declare(strict_types=1);
namespace BtcPayLite;

/** Safe operational category, never an Electrum output or a private key. */
final class StoreCreationException extends \RuntimeException
{
    public function __construct(public string $reason, string $message, ?\Throwable $previous = null)
    { parent::__construct($message,0,$previous); }
}
