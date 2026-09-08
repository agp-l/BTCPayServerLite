<?php

declare(strict_types=1);
namespace BtcPayLite;

/** Explicit provisioning/repair read; never called by invoice creation. */
final class WalletXpubReader
{
    public function __construct(private ElectrumWallet $wallet) {}

    public function read(string $walletPath): ProvisionedWallet
    {
        $walletPath = WalletLockManager::canonicalWalletPath($walletPath);
        $this->wallet->loadWallet($walletPath);
        $key = $this->wallet->getMasterPublicKey($walletPath);
        $addresses = $this->wallet->listAddresses(true, false, $walletPath);
        $receive = new ProvisionedWallet($walletPath, $key, count($addresses));
        self::verifyAddresses($receive, $addresses);
        return $receive;
    }

    public static function verifyAddresses(ProvisionedWallet $receive, array $addresses): void
    {
        if ($addresses === []) { throw new ElectrumWalletException('Wallet has no receive addresses to verify.', 'unsupported_xpub'); }
        foreach (array_unique([0, count($addresses) - 1]) as $index) {
            $indices = new class($index) implements AddressIndexStoreInterface {
                public function __construct(private int $index) {}
                public function reserveNextIndex(string $storeId): int { return $this->index; }
            };
            $generator = new XpubAddressGenerator($receive->xpub, $indices, $receive->scriptType);
            $derived = $generator->generateAddress(new AddressGenerationContext('verification'))->getAddress();
            if ($derived !== $addresses[$index]) {
                throw new ElectrumWalletException('Public key does not reproduce the wallet receive branch.', 'unsupported_xpub');
            }
        }
    }
}
