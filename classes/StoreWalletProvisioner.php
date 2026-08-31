<?php

declare(strict_types=1);

namespace BtcPayLite;

interface StoreWalletProvisioner
{
    public function provision(string $storeId): string;

    public function discard(string $walletPath): void;
}
