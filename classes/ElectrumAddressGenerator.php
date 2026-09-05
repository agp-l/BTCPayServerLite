<?php

declare(strict_types=1);

namespace BtcPayLite;

use Throwable;

/**
 * Generates payment addresses via Electrum RPC with fine-grained per-wallet locking.
 */
class ElectrumAddressGenerator implements AddressGeneratorInterface
{
    private ElectrumWallet $wallet;
    private WalletLockManager $lockManager;

    public function __construct(ElectrumWallet $wallet, ?WalletLockManager $lockManager = null)
    {
        $this->wallet = $wallet;
        $this->lockManager = $lockManager ?? new WalletLockManager();
    }

    public function generateAddress(AddressGenerationContext $context): GeneratedAddress
    {
        $walletPath = $context->getWalletPath() ?? $this->wallet->getActiveWalletPath();
        if ($walletPath === null || $walletPath === '') {
            throw new AddressGenerationException(
                'Wallet path is required for Electrum address generation.',
                GeneratedAddress::SOURCE_ELECTRUM,
                500
            );
        }

        try {
            $address = $this->lockManager->withWalletLock(
                $walletPath,
                function () use ($walletPath): string {
                    $this->wallet->ensureWalletLoaded($walletPath);
                    return $this->wallet->getNewAddress($walletPath);
                },
                5
            );

            return new GeneratedAddress($address, GeneratedAddress::SOURCE_ELECTRUM);
        } catch (WalletBusyException $e) {
            throw new AddressGenerationException(
                'Electrum wallet is currently busy. Please retry shortly.',
                GeneratedAddress::SOURCE_ELECTRUM,
                503,
                $e
            );
        } catch (Throwable $e) {
            throw new AddressGenerationException(
                'Failed to generate address via Electrum: ' . $e->getMessage(),
                GeneratedAddress::SOURCE_ELECTRUM,
                500,
                $e
            );
        }
    }
}
