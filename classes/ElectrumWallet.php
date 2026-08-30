<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use LogicException;

/**
 * Application-facing wrapper around Electrum wallet commands.
 *
 * Daemon-wide commands are sent with ElectrumRPC::call(). Commands that
 * operate on wallet state are always sent with an explicit wallet_path so
 * multiple wallets can safely remain loaded in the same daemon.
 */
class ElectrumWallet
{
    private const METHOD_NOT_FOUND = -32601;

    private ElectrumRPC $rpc;
    private ?string $activeWalletPath = null;

    public function __construct(ElectrumRPC $rpc)
    {
        $this->rpc = $rpc;
    }

    /**
     * Loads a wallet when necessary and selects it for subsequent operations.
     *
     * Other wallets are deliberately left open. Closing them here would make
     * concurrent API, checkout and cron requests interfere with each other.
     */
    public function loadWallet(string $walletPath, ?string $password = null): void
    {
        $walletPath = $this->validateWalletPath($walletPath);
        $loadedWallets = $this->rpc->call('list_wallets');
        $loadedPaths = $this->extractLoadedWalletPaths($loadedWallets);

        if (!$this->containsWalletPath($loadedPaths, $walletPath)) {
            $params = ['wallet_path' => $walletPath];
            if ($password !== null && $password !== '') {
                $params['password'] = $password;
            }

            $result = $this->rpc->call('load_wallet', $params);
            if ($result === null || $result === false || $result === '') {
                throw new ElectrumWalletException(
                    'Electrum wallet could not be loaded.',
                    'load_wallet'
                );
            }
        }

        $this->activeWalletPath = $walletPath;
    }

    public function getActiveWalletPath(): ?string
    {
        return $this->activeWalletPath;
    }

    /**
     * @return array{confirmed: float, unconfirmed: float}
     */
    public function getWalletBalance(): array
    {
        return $this->normalizeBalance(
            $this->callForActiveWallet('getbalance'),
            'getbalance'
        );
    }

    /**
     * @return array{confirmed: float, unconfirmed: float}
     */
    public function getAddressBalance(string $address): array
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');

        return $this->normalizeBalance(
            $this->rpc->call('getaddressbalance', ['address' => $address]),
            'getaddressbalance'
        );
    }

    public function getNewAddress(): string
    {
        return $this->requireNonEmptyStringResult(
            $this->callForActiveWallet('createnewaddress'),
            'createnewaddress'
        );
    }

    public function validateAddress(string $address): bool
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');
        $result = $this->rpc->call('validateaddress', ['address' => $address]);

        if (is_bool($result)) {
            return $result;
        }

        // Compatibility with older/custom RPC wrappers.
        if (is_array($result) && isset($result['isvalid']) && is_bool($result['isvalid'])) {
            return $result['isvalid'];
        }

        throw $this->invalidResponse('validateaddress');
    }

    /**
     * @return list<string>
     */
    public function listAddresses(bool $receiving = true, bool $change = false): array
    {
        $params = [];
        if ($receiving) {
            $params['receiving'] = true;
        }
        if ($change) {
            $params['change'] = true;
        }

        $addresses = $this->callForActiveWallet('listaddresses', $params);
        if (!$this->isList($addresses)) {
            throw $this->invalidResponse('listaddresses');
        }

        foreach ($addresses as $address) {
            if (!is_string($address) || $address === '') {
                throw $this->invalidResponse('listaddresses');
            }
        }

        return $addresses;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUnspent(): array
    {
        $unspent = $this->callForActiveWallet('listunspent');
        if (!$this->isListOfArrays($unspent)) {
            throw $this->invalidResponse('listunspent');
        }

        return $unspent;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTransactions(): array
    {
        try {
            $result = $this->callForActiveWallet('onchain_history');
        } catch (ElectrumRPCException $exception) {
            if ($exception->getRpcCode() !== self::METHOD_NOT_FOUND) {
                throw $exception;
            }

            // Electrum releases before onchain_history exposed history instead.
            $result = $this->callForActiveWallet('history');
        }

        if (is_array($result) && array_key_exists('transactions', $result)) {
            $result = $result['transactions'];
        }

        if (!$this->isListOfArrays($result)) {
            throw $this->invalidResponse('onchain_history');
        }

        return $result;
    }

    public function getTransaction(string $txid): array|string
    {
        $txid = strtolower(trim($txid));
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $txid)) {
            throw new InvalidArgumentException('Transaction ID must be 64 hexadecimal characters.');
        }

        $result = $this->callForActiveWallet('gettransaction', ['txid' => $txid]);
        if (!is_array($result) && !is_string($result)) {
            throw $this->invalidResponse('gettransaction');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function deserializeTransaction(string $hex): array
    {
        $hex = $this->validateSerializedTransaction($hex);
        $result = $this->rpc->call('deserialize', ['tx' => $hex]);
        if (!is_array($result)) {
            throw $this->invalidResponse('deserialize');
        }

        return $result;
    }

    public function createTransaction(
        string $destinationAddress,
        int|float|string $amount,
        ?string $password = null,
        ?int $feeRateSatVb = null
    ): string {
        $destinationAddress = $this->validateNonEmptyString($destinationAddress, 'Destination');
        $params = [
            'destination' => $destinationAddress,
            'amount' => $this->normalizeBitcoinAmount($amount),
        ];

        if ($password !== null && $password !== '') {
            $params['password'] = $password;
        }

        if ($feeRateSatVb !== null) {
            if ($feeRateSatVb < 1) {
                throw new InvalidArgumentException('Fee rate must be at least 1 sat/vbyte.');
            }
            $params['feerate'] = $feeRateSatVb;
        }

        $result = $this->callForActiveWallet('payto', $params);
        $serializedTransaction = is_array($result) ? ($result['hex'] ?? null) : $result;

        return $this->requireNonEmptyStringResult($serializedTransaction, 'payto');
    }

    public function broadcastTransaction(string $hex): string
    {
        $this->requireWalletLoaded();
        $hex = $this->validateSerializedTransaction($hex);
        $txid = $this->rpc->call('broadcast', ['tx' => $hex]);

        return $this->requireNonEmptyStringResult($txid, 'broadcast');
    }

    public function sendPayment(
        string $destinationAddress,
        int|float|string $amount,
        ?string $password = null,
        ?int $feeRateSatVb = null
    ): string {
        $hex = $this->createTransaction($destinationAddress, $amount, $password, $feeRateSatVb);

        return $this->broadcastTransaction($hex);
    }

    /**
     * @return array<string, mixed>
     */
    public function createPaymentRequest(
        int|float|string $amount,
        string $memo = '',
        ?int $expirationSeconds = null
    ): array {
        $params = [
            'amount' => $this->normalizeBitcoinAmount($amount),
            'memo' => $memo,
        ];

        if ($expirationSeconds !== null) {
            if ($expirationSeconds < 1) {
                throw new InvalidArgumentException('Payment request expiry must be positive.');
            }
            $params['expiry'] = $expirationSeconds;
        }

        $result = $this->callForActiveWallet('add_request', $params);
        if (!is_array($result)) {
            throw $this->invalidResponse('add_request');
        }

        return $result;
    }

    public function getMasterPublicKey(): string
    {
        return $this->requireNonEmptyStringResult(
            $this->callForActiveWallet('getmpk'),
            'getmpk'
        );
    }

    public function getSeed(string $password = ''): string
    {
        $params = $password !== '' ? ['password' => $password] : [];

        return $this->requireNonEmptyStringResult(
            $this->callForActiveWallet('getseed', $params),
            'getseed'
        );
    }

    public function getMasterPrivateKey(string $password = ''): string
    {
        $params = $password !== '' ? ['password' => $password] : [];

        return $this->requireNonEmptyStringResult(
            $this->callForActiveWallet('getmasterprivate', $params),
            'getmasterprivate'
        );
    }

    private function callForActiveWallet(string $method, array $params = []): mixed
    {
        return $this->rpc->callForWallet($method, $this->requireWalletLoaded(), $params);
    }

    private function requireWalletLoaded(): string
    {
        if ($this->activeWalletPath === null) {
            throw new LogicException('A wallet must be selected with loadWallet() before this operation.');
        }

        return $this->activeWalletPath;
    }

    private function validateWalletPath(string $walletPath): string
    {
        $walletPath = trim($walletPath);
        if ($walletPath === '' || str_contains($walletPath, "\0")) {
            throw new InvalidArgumentException('Invalid Electrum wallet path.');
        }

        return $walletPath;
    }

    private function validateNonEmptyString(string $value, string $fieldName): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException("{$fieldName} must not be empty.");
        }

        return $value;
    }

    private function validateSerializedTransaction(string $transaction): string
    {
        $transaction = trim($transaction);
        if ($transaction === '' || str_contains($transaction, "\0")) {
            throw new InvalidArgumentException('Serialized transaction must not be empty.');
        }

        return $transaction;
    }

    private function normalizeBitcoinAmount(int|float|string $amount): string
    {
        if (is_int($amount)) {
            if ($amount <= 0) {
                throw new InvalidArgumentException('Bitcoin amount must be greater than zero.');
            }

            return (string) $amount;
        }

        if (is_float($amount)) {
            if (!is_finite($amount) || $amount <= 0) {
                throw new InvalidArgumentException('Bitcoin amount must be a finite positive number.');
            }

            $amount = rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');
        } else {
            $amount = trim($amount);
        }

        if ($amount === '!') {
            return $amount;
        }

        if (!preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?\z/D', $amount)) {
            throw new InvalidArgumentException('Bitcoin amount must use up to 8 decimal places.');
        }

        if (str_replace(['0', '.'], '', $amount) === '') {
            throw new InvalidArgumentException('Bitcoin amount must be greater than zero.');
        }

        return $amount;
    }

    /**
     * @return list<string>
     */
    private function extractLoadedWalletPaths(mixed $loadedWallets): array
    {
        if (!$this->isList($loadedWallets)) {
            throw $this->invalidResponse('list_wallets');
        }

        $paths = [];
        foreach ($loadedWallets as $wallet) {
            $path = is_array($wallet) ? ($wallet['path'] ?? null) : $wallet;
            if (!is_string($path) || trim($path) === '') {
                throw $this->invalidResponse('list_wallets');
            }
            $paths[] = trim($path);
        }

        return $paths;
    }

    /**
     * @param list<string> $loadedPaths
     */
    private function containsWalletPath(array $loadedPaths, string $walletPath): bool
    {
        foreach ($loadedPaths as $loadedPath) {
            if ($loadedPath === $walletPath) {
                return true;
            }

            $loadedRealPath = realpath($loadedPath);
            $requestedRealPath = realpath($walletPath);
            if ($loadedRealPath !== false && $requestedRealPath !== false && $loadedRealPath === $requestedRealPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{confirmed: float, unconfirmed: float}
     */
    private function normalizeBalance(mixed $balance, string $operation): array
    {
        if (!is_array($balance)) {
            throw $this->invalidResponse($operation);
        }

        $confirmed = $balance['confirmed'] ?? 0;
        $unconfirmed = $balance['unconfirmed'] ?? 0;
        if (!is_numeric($confirmed) || !is_numeric($unconfirmed)) {
            throw $this->invalidResponse($operation);
        }

        return [
            'confirmed' => (float) $confirmed,
            'unconfirmed' => (float) $unconfirmed,
        ];
    }

    private function requireNonEmptyStringResult(mixed $result, string $operation): string
    {
        if (!is_string($result) || trim($result) === '') {
            throw $this->invalidResponse($operation);
        }

        return $result;
    }

    private function invalidResponse(string $operation): ElectrumWalletException
    {
        return new ElectrumWalletException(
            "Electrum returned an invalid response for '{$operation}'.",
            $operation
        );
    }

    private function isList(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $expectedKey = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expectedKey) {
                return false;
            }
            ++$expectedKey;
        }

        return true;
    }

    private function isListOfArrays(mixed $value): bool
    {
        if (!$this->isList($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }
}
