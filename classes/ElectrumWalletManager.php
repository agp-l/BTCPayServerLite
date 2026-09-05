<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Manages multiple Electrum wallets concurrently without global state or cross-wallet eviction.
 */
class ElectrumWalletManager
{
    private ElectrumRPC $rpc;
    private WalletPathResolver $pathResolver;
    /** @var array<string, ElectrumWallet> */
    private array $wallets = [];

    public function __construct(ElectrumRPC $rpc, WalletPathResolver $pathResolver)
    {
        $this->rpc = $rpc;
        $this->pathResolver = $pathResolver;
    }

    public function getRpc(): ElectrumRPC
    {
        return $this->rpc;
    }

    public function getPathResolver(): WalletPathResolver
    {
        return $this->pathResolver;
    }

    /**
     * Resolves and ensures a wallet is loaded in Electrum daemon, returning its ElectrumWallet instance.
     * Leaves all other loaded wallets untouched.
     */
    public function getWallet(string $walletPathOrName, ?string $password = null): ElectrumWallet
    {
        $canonicalPath = $this->pathResolver->resolve($walletPathOrName);

        if (isset($this->wallets[$canonicalPath])) {
            return $this->wallets[$canonicalPath];
        }

        $wallet = new ElectrumWallet($this->rpc);
        $wallet->loadWallet($canonicalPath, $password);

        $this->wallets[$canonicalPath] = $wallet;

        return $wallet;
    }

    /**
     * Returns list of canonical paths of all wallets currently loaded in the daemon.
     *
     * @return list<string>
     */
    public function listLoadedWallets(): array
    {
        $rawList = $this->rpc->callDaemon('list_wallets');
        if (!is_array($rawList)) {
            return [];
        }

        $result = [];
        foreach ($rawList as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            } elseif (is_array($item) && isset($item['path']) && is_string($item['path'])) {
                $result[] = $item['path'];
            }
        }

        return $result;
    }

    /**
     * Closes a specific wallet in the Electrum daemon.
     */
    public function closeWallet(string $walletPathOrName): void
    {
        $canonicalPath = $this->pathResolver->resolve($walletPathOrName);
        $this->rpc->callDaemon('close_wallet', ['wallet_path' => $canonicalPath]);
        unset($this->wallets[$canonicalPath]);
    }
}
