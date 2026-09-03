<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Creates appropriate AddressGenerator instance (Xpub or Electrum) for a store.
 *
 * Honors store configuration ('address_source' => 'xpub' | 'electrum') with optional
 * authorized override. Strict: no silent fallback from XPUB failure to Electrum.
 */
final class AddressGeneratorFactory
{
    private ElectrumRPC $rpc;
    private ElectrumWalletManager $walletManager;
    private WalletLockManager $locks;
    private WalletPathResolver $pathResolver;
    private ?Database $database;

    public function __construct(
        ElectrumRPC $rpc,
        ElectrumWalletManager $walletManager,
        WalletLockManager $locks,
        WalletPathResolver $pathResolver,
        ?Database $database = null
    ) {
        $this->rpc = $rpc;
        $this->walletManager = $walletManager;
        $this->locks = $locks;
        $this->pathResolver = $pathResolver;
        $this->database = $database;
    }

    /**
     * @param array<string, mixed> $store
     */
    public function forStore(array $store, ?string $requestedSource = null): AddressGeneratorInterface
    {
        $storeId = (string) ($store['id'] ?? 'default');
        $configuredSource = strtolower(trim((string) ($store['address_source'] ?? 'xpub')));

        $source = $requestedSource !== null && trim($requestedSource) !== ''
            ? strtolower(trim($requestedSource))
            : $configuredSource;

        if ($source === 'xpub') {
            $xpub = (string) ($store['extended_public_key'] ?? $store['xpub'] ?? '');
            if (trim($xpub) === '') {
                // If store doesn't have an xpub configured yet, check if electrum configured
                if (!empty($store['wallet_path'])) {
                    $walletPath = $this->pathResolver->resolve((string) $store['wallet_path']);
                    return new ElectrumAddressGenerator(
                        $this->walletManager,
                        $this->rpc,
                        $this->locks,
                        $walletPath
                    );
                }
                throw new InvalidArgumentException("Store '{$storeId}' has no XPUB or wallet configured.");
            }

            $indexStore = $this->database !== null
                ? new DatabaseAddressIndexStore($this->database)
                : new FileAddressIndexStore();

            $scriptType = (string) ($store['script_type'] ?? 'p2wpkh');
            $network = (string) ($store['network'] ?? 'mainnet');

            return new XpubAddressGenerator(
                $xpub,
                $indexStore,
                $storeId,
                $scriptType,
                $network
            );
        }

        if ($source === 'electrum') {
            $rawWallet = (string) ($store['wallet_path'] ?? '');
            if (trim($rawWallet) === '') {
                throw new InvalidArgumentException("Store '{$storeId}' has no wallet_path configured.");
            }
            $walletPath = $this->pathResolver->resolve($rawWallet);

            return new ElectrumAddressGenerator(
                $this->walletManager,
                $this->rpc,
                $this->locks,
                $walletPath
            );
        }

        throw new InvalidArgumentException("Unsupported address source: {$source}");
    }
}
