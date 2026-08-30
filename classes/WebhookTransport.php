<?php

declare(strict_types=1);

namespace BtcPayLite;

interface WebhookTransport
{
    /** @return array{http_status: int, primary_ip: string} */
    public function deliver(string $url, string $payload, string $signature): array;
}
