<?php

declare(strict_types=1);

namespace BtcPayLite;

interface StoreWalletProvisioner
{
    public function provision(string $storeId): ProvisionedWallet;

    public function discard(string $walletPath): void;
}
