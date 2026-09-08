<?php

declare(strict_types=1);
namespace BtcPayLite;

/** Public receive configuration; never contains seed material or private keys. */
final class ProvisionedWallet
{
    public string $walletPath;
    public string $xpub;
    public string $scriptType;
    public int $nextIndex;

    public function __construct(string $walletPath, string $xpub, int $nextIndex = 0)
    {
        $this->walletPath = WalletLockManager::canonicalWalletPath($walletPath);
        $this->xpub = trim($xpub);
        // Electrum encodes script type in its public-key version (SLIP-0132).
        // In particular, Electrum xpub/tpub is legacy p2pkh, not native SegWit.
        $script = match (substr($this->xpub, 0, 4)) {
            'xpub', 'tpub' => 'p2pkh',
            'ypub', 'upub' => 'p2sh-p2wpkh',
            'zpub', 'vpub' => 'p2wpkh',
            default => throw new ElectrumWalletException('Wallet has no supported single-signature BIP32 public key.', 'unsupported_xpub'),
        };
        $generator = new XpubAddressGenerator($this->xpub, new FileAddressIndexStore(), $script);
        $this->scriptType = $generator->getScriptType();
        if ($nextIndex < 0 || $nextIndex >= 2147483648) {
            throw new \InvalidArgumentException('Receive index is outside the non-hardened BIP32 range.');
        }
        $this->nextIndex = $nextIndex;
    }

    public function columns(): array
    {
        return ['wallet_path' => $this->walletPath, 'address_source' => 'xpub',
            'xpub' => $this->xpub, 'xpub_script_type' => $this->scriptType, 'xpub_last_index' => $this->nextIndex];
    }
}
