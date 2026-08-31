<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Read/write application service used by the wallet administration screen.
 *
 * Electrum data remains machine-readable here. HTML escaping, localized labels
 * and layout decisions belong to the controller/view boundary.
 */
final class BtcDashboard
{
    private const DEFAULT_FEES = ['economy' => 1, 'standard' => 1, 'priority' => 1];

    private ElectrumWallet $wallet;
    private string $walletsDirectory;
    private BitcoinMarketDataProvider $marketData;

    public function __construct(
        ElectrumWallet $wallet,
        string $walletsDirectory,
        ?BitcoinMarketDataProvider $marketData = null
    ) {
        $walletsDirectory = rtrim(trim($walletsDirectory), DIRECTORY_SEPARATOR);
        if ($walletsDirectory === '' || str_contains($walletsDirectory, "\0")) {
            throw new InvalidArgumentException('Wallet directory is invalid.');
        }

        $this->wallet = $wallet;
        $this->walletsDirectory = $walletsDirectory;
        $this->marketData = $marketData ?? new HttpBitcoinMarketDataProvider();
    }

    /** @return list<string> */
    public function listWallets(): array
    {
        $directory = realpath($this->walletsDirectory);
        if ($directory === false || !is_dir($directory) || !is_readable($directory)) {
            throw new RuntimeException('The Electrum wallet directory is unavailable.');
        }

        $entries = scandir($directory);
        if (!is_array($entries)) {
            throw new RuntimeException('The Electrum wallet directory could not be read.');
        }

        $wallets = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && !is_link($path)) {
                $wallets[] = $entry;
            }
        }

        natcasesort($wallets);

        return array_values($wallets);
    }

    /** @return array{confirmed_btc:string,unconfirmed_btc:string,confirmed_sats:int,unconfirmed_sats:int} */
    public function balance(): array
    {
        $balance = $this->wallet->getWalletBalance();
        $confirmed = BitcoinAmount::fromBtc($balance['confirmed']);
        $unconfirmed = BitcoinAmount::fromBtc($balance['unconfirmed']);

        return [
            'confirmed_btc' => $confirmed->toBtcString(),
            'unconfirmed_btc' => $unconfirmed->toBtcString(),
            'confirmed_sats' => $confirmed->satoshis(),
            'unconfirmed_sats' => $unconfirmed->satoshis(),
        ];
    }

    /**
     * @return array{
     *   items:list<array{address:string,balance_btc:string,balance_sats:int,has_funds:bool,type:string}>,
     *   recommended_receive:?string
     * }
     */
    public function addresses(bool $hideEmpty = false): array
    {
        $receiving = $this->uniqueAddresses($this->wallet->listAddresses(true, false));
        $change = $this->uniqueAddresses($this->wallet->listAddresses(false, true));
        $receivingSet = array_fill_keys($receiving, true);
        $changeSet = array_fill_keys($change, true);

        /** @var array<string, BitcoinAmount> $balances */
        $balances = [];
        foreach ($this->wallet->listUnspent() as $unspent) {
            $address = $unspent['address'] ?? null;
            if (!is_string($address) || $address === '') {
                continue;
            }

            $amount = $this->unspentAmount($unspent);
            $balances[$address] = isset($balances[$address])
                ? $balances[$address]->add($amount)
                : $amount;
        }

        $items = [];
        $recommended = null;
        foreach (array_values(array_unique(array_merge($receiving, $change))) as $address) {
            $amount = $balances[$address] ?? BitcoinAmount::fromSatoshis(0);
            $type = isset($changeSet[$address]) && !isset($receivingSet[$address]) ? 'change' : 'receiving';
            $hasFunds = $amount->isPositive();

            if ($recommended === null && $type === 'receiving' && !$hasFunds) {
                $recommended = $address;
            }
            if ($hideEmpty && !$hasFunds) {
                continue;
            }

            $items[] = [
                'address' => $address,
                'balance_btc' => $amount->toBtcString(),
                'balance_sats' => $amount->satoshis(),
                'has_funds' => $hasFunds,
                'type' => $type,
            ];
        }

        usort(
            $items,
            static fn (array $left, array $right): int =>
                ($right['balance_sats'] <=> $left['balance_sats']) ?: strcmp($left['address'], $right['address'])
        );

        return ['items' => $items, 'recommended_receive' => $recommended];
    }

    /**
     * @return list<array{
     *   txid:string,direction:string,amount_btc:string,amount_sats:int,
     *   confirmations:int,timestamp:?int,
     *   outputs:list<array{address:string,amount_btc:string,amount_sats:int,ownership:string}>
     * }>
     */
    public function transactions(): array
    {
        $receiving = array_fill_keys($this->uniqueAddresses($this->wallet->listAddresses(true, false)), true);
        $change = array_fill_keys($this->uniqueAddresses($this->wallet->listAddresses(false, true)), true);
        $transactions = [];

        foreach ($this->wallet->listTransactions() as $transaction) {
            $txid = $transaction['txid'] ?? $transaction['tx_hash'] ?? null;
            if (!is_string($txid) || !preg_match('/\A[0-9a-fA-F]{64}\z/D', $txid)) {
                continue;
            }
            $txid = strtolower($txid);

            $rawAmount = $transaction['bc_value'] ?? $transaction['value'] ?? 0;
            $signedAmount = BitcoinAmount::fromBtc($this->numericAmount($rawAmount));
            $incoming = isset($transaction['incoming'])
                ? (bool) $transaction['incoming']
                : $signedAmount->satoshis() > 0;
            $amount = BitcoinAmount::fromSatoshis(abs($signedAmount->satoshis()));

            $transactions[] = [
                'txid' => $txid,
                'direction' => $incoming ? 'incoming' : 'outgoing',
                'amount_btc' => $amount->toBtcString(),
                'amount_sats' => $amount->satoshis(),
                'confirmations' => $this->nonNegativeInt($transaction['confirmations'] ?? 0),
                'timestamp' => $this->timestamp($transaction['timestamp'] ?? null),
                'outputs' => $this->transactionOutputs($txid, $incoming, $receiving, $change),
            ];
        }

        usort($transactions, static function (array $left, array $right): int {
            $leftTime = $left['timestamp'] ?? 0;
            $rightTime = $right['timestamp'] ?? 0;

            return ($rightTime <=> $leftTime) ?: strcmp($right['txid'], $left['txid']);
        });

        return $transactions;
    }

    /** @return array{fees:array{economy:int,standard:int,priority:int},fiat_price:?float,fiat_currency:string} */
    public function marketSnapshot(string $currency = 'CZK'): array
    {
        $currency = strtoupper(trim($currency));
        $fees = self::DEFAULT_FEES;
        $price = null;

        try {
            $fees = $this->marketData->getRecommendedFees();
        } catch (Throwable $exception) {
            $this->logFailure('recommended fees', $exception);
        }

        try {
            $price = $this->marketData->getFiatPrice($currency);
        } catch (Throwable $exception) {
            $this->logFailure('fiat price', $exception);
        }

        return ['fees' => $fees, 'fiat_price' => $price, 'fiat_currency' => $currency];
    }

    public function sendPayment(string $destination, int|float|string $amount, ?string $password, ?int $feeRate): string
    {
        $destination = trim($destination);
        if ($destination === '' || !$this->wallet->validateAddress($destination)) {
            throw new InvalidArgumentException('Destination Bitcoin address is invalid.');
        }

        return $this->wallet->sendPayment($destination, $amount, $password, $feeRate);
    }

    public function newAddress(): string
    {
        return $this->wallet->getNewAddress();
    }

    /** @return array{seed:string,master_private_key:?string} */
    public function privateKeys(string $password): array
    {
        $seed = $this->wallet->getSeed($password);
        $masterPrivateKey = null;

        try {
            $masterPrivateKey = $this->wallet->getMasterPrivateKey($password);
        } catch (Throwable $exception) {
            $this->logFailure('master private key export', $exception);
        }

        return ['seed' => $seed, 'master_private_key' => $masterPrivateKey];
    }

    public function masterPublicKey(): string
    {
        return $this->wallet->getMasterPublicKey();
    }

    /** @param list<string> $addresses @return list<string> */
    private function uniqueAddresses(array $addresses): array
    {
        $unique = [];
        foreach ($addresses as $address) {
            $address = trim($address);
            if ($address !== '') {
                $unique[$address] = true;
            }
        }

        return array_keys($unique);
    }

    /** @param array<string,mixed> $unspent */
    private function unspentAmount(array $unspent): BitcoinAmount
    {
        if (array_key_exists('value_sats', $unspent)) {
            return BitcoinAmount::fromSatoshis($this->nonNegativeInt($unspent['value_sats']));
        }

        return BitcoinAmount::fromBtc($this->numericAmount($unspent['value'] ?? 0));
    }

    /**
     * @param array<string,bool> $receiving
     * @param array<string,bool> $change
     * @return list<array{address:string,amount_btc:string,amount_sats:int,ownership:string}>
     */
    private function transactionOutputs(string $txid, bool $incoming, array $receiving, array $change): array
    {
        try {
            $details = $this->wallet->getTransaction($txid);
            $hex = is_array($details) ? ($details['hex'] ?? null) : $details;
            if (!is_string($hex) || $hex === '') {
                return [];
            }

            $decoded = $this->wallet->deserializeTransaction($hex);
            $rawOutputs = $decoded['outputs'] ?? [];
            if (!is_array($rawOutputs)) {
                return [];
            }

            $outputs = [];
            foreach ($rawOutputs as $output) {
                if (!is_array($output)) {
                    continue;
                }
                $address = $output['address'] ?? null;
                if (!is_string($address) || $address === '') {
                    continue;
                }

                $amount = array_key_exists('value_sats', $output)
                    ? BitcoinAmount::fromSatoshis($this->nonNegativeInt($output['value_sats']))
                    : BitcoinAmount::fromBtc($this->numericAmount($output['value'] ?? $output['amount'] ?? 0));
                $ownership = isset($change[$address])
                    ? 'change'
                    : (isset($receiving[$address]) ? 'receiving' : ($incoming ? 'external' : 'recipient'));

                $outputs[] = [
                    'address' => $address,
                    'amount_btc' => $amount->toBtcString(),
                    'amount_sats' => $amount->satoshis(),
                    'ownership' => $ownership,
                ];
            }

            return $outputs;
        } catch (Throwable $exception) {
            $this->logFailure('transaction output decoding', $exception);

            return [];
        }
    }

    private function numericAmount(mixed $value): int|float|string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new RuntimeException('Electrum returned an invalid Bitcoin amount.');
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            throw new RuntimeException('Electrum returned an invalid integer value.');
        }

        if ($number < 0) {
            throw new RuntimeException('Electrum returned a negative integer value.');
        }

        return $number;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === 0 || $value === '0') {
            return null;
        }

        $timestamp = $this->nonNegativeInt($value);

        return $timestamp > 0 ? $timestamp : null;
    }

    private function logFailure(string $operation, Throwable $exception): void
    {
        error_log(sprintf(
            'BtcDashboard %s failed: %s (%s)',
            $operation,
            $exception->getMessage(),
            $exception::class
        ));
    }
}
