<?php

declare(strict_types=1);

namespace BtcPayLite;

use Throwable;

/**
 * Queries Electrum daemon for address balances and payment history using walletless commands.
 *
 * Never requires loading a wallet into the daemon or taking wallet-level locks.
 */
class ElectrumBlockchainProvider implements BlockchainProviderInterface
{
    private ElectrumRPC $rpc;

    public function __construct(ElectrumRPC $rpc)
    {
        $this->rpc = $rpc;
    }

    public function getProviderName(): string
    {
        return 'electrum-walletless';
    }

    public function getAddressObservation(string $address): AddressPaymentObservation
    {
        $address = trim($address);
        if ($address === '') {
            throw new ElectrumWalletException('Address cannot be empty for observation.', 'get_address_observation');
        }

        $now = time();

        // 1. Fetch balance via walletless network command
        $rawBalance = $this->rpc->callNetwork('getaddressbalance', ['address' => $address]);

        $confirmedBtc = is_array($rawBalance) ? (string) ($rawBalance['confirmed'] ?? '0') : '0';
        $unconfirmedBtc = is_array($rawBalance) ? (string) ($rawBalance['unconfirmed'] ?? '0') : '0';

        $confirmedSats = $this->btcStringToSats($confirmedBtc);
        $unconfirmedSats = $this->btcStringToSats($unconfirmedBtc);

        // 2. Fetch history if available for transaction-level tracking
        $transactions = [];
        try {
            $rawHistory = $this->rpc->callNetwork('getaddresshistory', ['address' => $address]);
            if (is_array($rawHistory)) {
                foreach ($rawHistory as $tx) {
                    if (is_array($tx)) {
                        $transactions[] = [
                            'tx_hash' => (string) ($tx['tx_hash'] ?? ''),
                            'height' => (int) ($tx['height'] ?? 0),
                            'fee' => (int) ($tx['fee'] ?? 0),
                        ];
                    }
                }
            }
        } catch (Throwable) {
            // getaddresshistory is optional, fall back to balance
        }

        return new AddressPaymentObservation(
            confirmedReceivedSats: $confirmedSats,
            unconfirmedReceivedSats: $unconfirmedSats,
            transactions: $transactions,
            observedAt: $now
        );
    }

    private function btcStringToSats(string $btc): int
    {
        $btc = trim($btc);
        if ($btc === '' || !is_numeric($btc)) {
            return 0;
        }

        // Parse decimal without floating-point error
        $parts = explode('.', $btc, 2);
        $whole = (int) ($parts[0] ?? 0);
        $fraction = str_pad(substr($parts[1] ?? '', 0, 8), 8, '0', STR_PAD_RIGHT);

        return ($whole * 100_000_000) + (int) $fraction;
    }
}
