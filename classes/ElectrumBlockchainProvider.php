<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use Throwable;

/**
 * Monitors Bitcoin addresses using Electrum daemon RPC calls without loading
 * or locking any wallet.
 *
 * All network queries (getaddressbalance, getaddresshistory) are daemon-level
 * and safe to execute concurrently across multiple worker threads.
 */
class ElectrumBlockchainProvider implements BlockchainProviderInterface
{
    private ElectrumRPC $rpc;

    public function __construct(ElectrumRPC $rpc)
    {
        $this->rpc = $rpc;
    }

    public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation
    {
        $address = trim($address);
        if ($address === '') {
            throw new BlockchainProviderException('Address cannot be empty.', 'observe_address', 400);
        }

        try {
            /** @var array<string, mixed> $balance */
            $balance = $this->rpc->callDaemon('getaddressbalance', ['address' => $address]);
            if (!is_array($balance)) {
                throw new BlockchainProviderException('Electrum returned an invalid balance response.', 'observe_address');
            }

            $confirmedBtc = (string) ($balance['confirmed'] ?? '0');
            $unconfirmedBtc = (string) ($balance['unconfirmed'] ?? '0');

            $confirmedSats = BitcoinAmount::fromBtc($confirmedBtc)->toSatoshis();
            $unconfirmedSats = BitcoinAmount::fromBtc($unconfirmedBtc)->toSatoshis();
        } catch (BlockchainProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BlockchainProviderException(
                'Failed to query address balance: ' . $exception->getMessage(),
                'observe_address',
                500,
                $exception
            );
        }

        $historyCount = 0;
        try {
            $history = $this->rpc->callDaemon('getaddresshistory', ['address' => $address]);
            if (is_array($history)) {
                $historyCount = count($history);
            }
        } catch (Throwable) {
            // Address history query failure is non-fatal; we proceed with balance data
            $historyCount = 0;
        }

        return new AddressPaymentObservation(
            $address,
            $confirmedSats,
            $unconfirmedSats,
            max($confirmedSats + $unconfirmedSats, 0),
            $historyCount,
            time()
        );
    }
}
