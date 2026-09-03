<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Generates Bitcoin addresses via Electrum daemon JSON-RPC.
 *
 * Employs fine-grained per-wallet locking held strictly for the duration of the
 * 'createnewaddress' RPC call. Locks are released immediately before database insertion
 * or response formatting.
 */
final class ElectrumAddressGenerator implements AddressGeneratorInterface
{
    private ElectrumWalletManager $walletManager;
    private ElectrumRPC $rpc;
    private WalletLockManager $locks;
    private string $walletPath;

    public function __construct(
        ElectrumWalletManager $walletManager,
        ElectrumRPC $rpc,
        WalletLockManager $locks,
        string $walletPath
    ) {
        $walletPath = trim($walletPath);
        if ($walletPath === '') {
            throw new InvalidArgumentException('Wallet path cannot be empty for ElectrumAddressGenerator.');
        }

        $this->walletManager = $walletManager;
        $this->rpc = $rpc;
        $this->locks = $locks;
        $this->walletPath = $walletPath;
    }

    public function getSource(): string
    {
        return 'electrum';
    }

    public function getWalletPath(): string
    {
        return $this->walletPath;
    }

    public function generate(AddressGenerationContext $context): GeneratedAddress
    {
        return $this->locks->withWalletLock(
            $this->walletPath,
            function (): GeneratedAddress {
                $this->walletManager->ensureLoaded($this->walletPath);

                try {
                    $address = $this->rpc->callWallet(
                        $this->walletPath,
                        'createnewaddress'
                    );
                } catch (ElectrumRPCException $e) {
                    $address = $this->rpc->callWallet(
                        $this->walletPath,
                        'getunusedaddress'
                    );
                }

                if (!is_string($address) || trim($address) === '') {
                    throw new ElectrumWalletException(
                        'Electrum address generation did not return a valid address.',
                        'createnewaddress'
                    );
                }

                return new GeneratedAddress(
                    address: trim($address),
                    source: 'electrum'
                );
            }
        );
    }
}
