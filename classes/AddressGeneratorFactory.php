<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Factory creating the appropriate AddressGenerator according to store configuration.
 * Enforces strict non-fallback rules: if XPUB is configured, it must never fall back to Electrum.
 */
class AddressGeneratorFactory
{
    private ElectrumWallet $wallet;
    private ?Database $database;
    private ?WalletLockManager $lockManager;
    private ?AddressIndexStoreInterface $customIndexStore;

    public function __construct(
        ElectrumWallet $wallet,
        ?Database $database = null,
        ?WalletLockManager $lockManager = null,
        ?AddressIndexStoreInterface $customIndexStore = null
    ) {
        $this->wallet = $wallet;
        $this->database = $database;
        $this->lockManager = $lockManager ?? new WalletLockManager($database);
        $this->customIndexStore = $customIndexStore;
    }

    /**
     * Instantiates an AddressGenerator based on store configuration.
     *
     * @param array<string, mixed> $store
     * @throws AddressGenerationException
     */
    public function forStore(array $store): AddressGeneratorInterface
    {
        $source = strtolower(trim((string) ($store['address_source'] ?? '')));
        if ($source === '') {
            $source = !empty($store['xpub']) ? GeneratedAddress::SOURCE_XPUB : GeneratedAddress::SOURCE_ELECTRUM;
        }

        if ($source === GeneratedAddress::SOURCE_XPUB) {
            $xpub = trim((string) ($store['xpub'] ?? ''));
            if ($xpub === '') {
                throw new AddressGenerationException(
                    "Store is configured with address_source='xpub', but no XPUB is configured.",
                    GeneratedAddress::SOURCE_XPUB,
                    422
                );
            }

            $indexStore = $this->customIndexStore;
            if ($indexStore === null) {
                if ($this->database === null) {
                    $indexStore = new FileAddressIndexStore();
                } else {
                    $indexStore = new DbAddressIndexStore($this->database);
                }
            }

            $scriptType = !empty($store['xpub_script_type']) ? (string) $store['xpub_script_type'] : null;

            return new XpubAddressGenerator($xpub, $indexStore, $scriptType);
        }

        if ($source === GeneratedAddress::SOURCE_ELECTRUM) {
            return new ElectrumAddressGenerator($this->wallet, $this->lockManager);
        }

        throw new InvalidArgumentException("Unsupported address source '{$source}'.");
    }

    public function createElectrumGenerator(): ElectrumAddressGenerator
    {
        return new ElectrumAddressGenerator($this->wallet, $this->lockManager);
    }
}
