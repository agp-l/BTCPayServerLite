<?php
declare(strict_types=1);
namespace BtcPayLite;

use Exception;

/**
 * VRSTVA 2: Univerzální bitcoinová peněženka.
 */
class ElectrumWallet
{
    private ElectrumRPC $rpc;
    private ?string $activeWalletPath = null;

    public function __construct(ElectrumRPC $rpc)
    {
        $this->rpc = $rpc;
    }

    public function loadWallet(string $walletPath): void
    {
        $loaded = $this->rpc->call('list_wallets');
        $loadedPaths = [];

        if (is_array($loaded)) {
            foreach ($loaded as $item) {
                $loadedPaths[] = is_array($item) ? ($item['path'] ?? '') : (string)$item;
            }
        }

        foreach ($loadedPaths as $path) {
            if ($path !== $walletPath && !empty($path)) {
                $this->rpc->call('close_wallet', ['wallet_path' => $path]);
            }
        }

        if (!in_array($walletPath, $loadedPaths)) {
            $this->rpc->call('load_wallet', ['wallet_path' => $walletPath]);
        }

        $this->activeWalletPath = $walletPath;
        
        // PŘIDÁNO: Předáme cestu RPC třídě, aby ji lepila do URL!
        $this->rpc->setWallet($walletPath);
    }

    public function getWalletBalance(): array
    {
        $this->requireWalletLoaded();
        $balance = $this->rpc->call('getbalance');
        return [
            'confirmed' => (float)($balance['confirmed'] ?? 0),
            'unconfirmed' => (float)($balance['unconfirmed'] ?? 0)
        ];
    }

    public function getAddressBalance(string $address): array
    {
        $this->requireWalletLoaded();
        $balance = $this->rpc->call('getaddressbalance', ['address' => $address]);
        return [
            'confirmed' => (float)($balance['confirmed'] ?? 0),
            'unconfirmed' => (float)($balance['unconfirmed'] ?? 0)
        ];
    }

    public function getNewAddress(): string
    {
        $this->requireWalletLoaded();
        return (string) $this->rpc->call('createnewaddress');
    }

    public function validateAddress(string $address): bool
    {
        $this->requireWalletLoaded();
        $res = $this->rpc->call('validateaddress', ['address' => $address]);
        return isset($res['isvalid']) && $res['isvalid'] === true;
    }

    public function listAddresses(bool $receiving = true, bool $change = false): array
    {
        $this->requireWalletLoaded();
        $params = [];
        if ($receiving) $params['receiving'] = true;
        if ($change) $params['change'] = true;

        $addresses = $this->rpc->call('listaddresses', $params);
        return is_array($addresses) ? $addresses : [];
    }

    public function listUnspent(): array
    {
        $this->requireWalletLoaded();
        $unspent = $this->rpc->call('listunspent');
        return is_array($unspent) ? $unspent : [];
    }

    public function listTransactions(): array
    {
        $this->requireWalletLoaded();
        
        try {
            $res = $this->rpc->call('onchain_history');
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Invalid Request') !== false || strpos($e->getMessage(), 'Method not found') !== false) {
                $res = $this->rpc->call('history');
            } else {
                throw $e;
            }
        }
        
        if (is_array($res) && isset($res['transactions'])) {
            return $res['transactions'];
        }
        
        return is_array($res) ? $res : [];
    }

    public function getTransaction(string $txid)
    {
        $this->requireWalletLoaded();
        return $this->rpc->call('gettransaction', ['txid' => $txid]);
    }

    public function deserializeTransaction(string $hex): array
    {
        $this->requireWalletLoaded();
        $res = $this->rpc->call('deserialize', ['tx' => $hex]);
        return is_array($res) ? $res : [];
    }

    public function createTransaction(string $destinationAddress, int|float|string $amount, ?string $password = null, ?int $feeRateSatVb = null): string
    {
        $this->requireWalletLoaded();
        $params = ['destination' => $destinationAddress, 'amount' => $amount];

        if ($password !== null && $password !== '') {
            $params['password'] = $password;
        }
        
        // ZÁSADNÍ OPRAVA: Použijeme parametr 'feerate' a vložíme ho jako čisté číslo.
        if ($feeRateSatVb !== null && $feeRateSatVb > 0) {
            $params['feerate'] = $feeRateSatVb;
        }

        $rawTx = $this->rpc->call('payto', $params);
        $hex = is_array($rawTx) ? ($rawTx['hex'] ?? '') : (is_string($rawTx) ? $rawTx : '');

        if (empty($hex)) throw new Exception("Selhalo vytvoření surové transakce.");
        return $hex;
    }

    public function broadcastTransaction(string $hex): string
    {
        $this->requireWalletLoaded();
        $txid = $this->rpc->call('broadcast', ['tx' => $hex]);
        if (empty($txid)) throw new Exception("Transakce byla odmítnuta sítí.");
        return (string) $txid;
    }

    public function sendPayment(string $destinationAddress, int|float|string $amount, ?string $password = null, ?int $feeRateSatVb = null): string
    {
        $hex = $this->createTransaction($destinationAddress, $amount, $password, $feeRateSatVb);
        return $this->broadcastTransaction($hex);
    }

    public function createPaymentRequest(float $amount, string $memo = '', ?int $expirationSeconds = null): array
    {
        $this->requireWalletLoaded();
        $params = ['amount' => $amount, 'memo' => $memo];
        if ($expirationSeconds !== null) $params['expiration'] = $expirationSeconds;
        
        $res = $this->rpc->call('add_request', $params);
        return is_array($res) ? $res : [];
    }

    public function getMasterPublicKey(): string
    {
        $this->requireWalletLoaded();
        $xpub = $this->rpc->call('getmpk');
        return is_string($xpub) ? $xpub : '';
    }

    public function getSeed(string $password = ''): string
    {
        $this->requireWalletLoaded();
        $params = $password !== '' ? ['password' => $password] : [];
        $seed = $this->rpc->call('getseed', $params);
        return is_string($seed) ? $seed : '';
    }

    public function getMasterPrivateKey(string $password = ''): string
    {
        $this->requireWalletLoaded();
        $params = $password !== '' ? ['password' => $password] : [];
        $xprv = $this->rpc->call('getmasterprivate', $params);
        return is_string($xprv) ? $xprv : '';
    }

    private function requireWalletLoaded(): void
    {
        if ($this->activeWalletPath === null) {
            throw new Exception("Kritická chyba: Před voláním metody peněženky musíš načíst loadWallet().");
        }
    }
}