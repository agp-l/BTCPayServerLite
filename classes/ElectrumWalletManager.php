<?php

declare(strict_types=1);

namespace BtcPayLite;

use Throwable;

/**
 * Manages loaded wallets inside the Electrum daemon.
 *
 * Ensures wallets are loaded on demand without ever closing concurrently loaded wallets.
 */
class ElectrumWalletManager
{
    private ElectrumRPC $rpc;
    /** @var array<string, bool> In-process cache of loaded wallets */
    private array $loadedCache = [];

    public function __construct(ElectrumRPC $rpc)
    {
        $this->rpc = $rpc;
    }

    public function ensureLoaded(string $walletPath, ?string $password = null): void
    {
        $walletPath = trim($walletPath);
        if ($walletPath === '') {
            throw new ElectrumWalletException('Wallet path cannot be empty.', 'ensure_loaded');
        }

        if (isset($this->loadedCache[$walletPath])) {
            return;
        }

        if ($this->isLoaded($walletPath)) {
            $this->loadedCache[$walletPath] = true;
            return;
        }

        $params = ['wallet_path' => $walletPath];
        if ($password !== null && $password !== '') {
            $params['password'] = $password;
        }

        try {
            $result = $this->rpc->callDaemon('load_wallet', $params);
            if ($result === null || $result === false || $result === '') {
                throw new ElectrumWalletException(
                    "Electrum wallet could not be loaded: {$walletPath}",
                    'load_wallet'
                );
            }
            $this->loadedCache[$walletPath] = true;
        } catch (ElectrumRPCException $e) {
            // Check if already loaded or re-check list
            if (str_contains(strtolower($e->getMessage()), 'already loaded')) {
                $this->loadedCache[$walletPath] = true;
                return;
            }
            throw new ElectrumWalletException("Failed to load wallet '{$walletPath}': " . $e->getMessage(), 'load_wallet', previous: $e);
        }
    }

    public function isLoaded(string $walletPath): bool
    {
        $loadedWallets = $this->listLoadedWallets();
        foreach ($loadedWallets as $loaded) {
            if ($this->pathsEqual($loaded, $walletPath)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    public function listLoadedWallets(): array
    {
        try {
            $response = $this->rpc->callDaemon('list_wallets');
            $paths = [];
            if (is_array($response)) {
                foreach ($response as $item) {
                    if (is_string($item)) {
                        $paths[] = $item;
                    } elseif (is_array($item) && isset($item['path']) && is_string($item['path'])) {
                        $paths[] = $item['path'];
                    }
                }
            }
            return $paths;
        } catch (Throwable) {
            return [];
        }
    }

    public function close(string $walletPath): void
    {
        $walletPath = trim($walletPath);
        unset($this->loadedCache[$walletPath]);

        try {
            $this->rpc->callDaemon('close_wallet', ['wallet_path' => $walletPath]);
        } catch (Throwable) {
            // Ignore if already closed or not loaded
        }
    }

    private function pathsEqual(string $a, string $b): bool
    {
        $na = str_replace('\\', '/', trim($a));
        $nb = str_replace('\\', '/', trim($b));
        return strcasecmp($na, $nb) === 0 || basename($na) === basename($nb);
    }
}
