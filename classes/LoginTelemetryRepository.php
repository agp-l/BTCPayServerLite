<?php

declare(strict_types=1);

namespace BtcPayLite;

interface LoginTelemetryRepository
{
    public function recordSuccessfulLogin(int $userId, ?string $ipAddress, int $loggedInAt): void;
}
