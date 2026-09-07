<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use LogicException;

/**
 * Application-facing wrapper around Electrum wallet commands.
 *
 * Network and daemon commands use their explicit transport scopes. Commands that
 * operate on wallet state are sent with an explicit wallet path so
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
     *
     * @deprecated Admin compatibility only; mutation services own ensureLoaded + RPC under one lock.
     */
    public function loadWallet(string $walletPath, ?string $password = null): void
    {
        (new WalletLockManager())->withWalletLock($walletPath, fn () => $this->ensureWalletLoaded($walletPath, $password));
        $this->activeWalletPath = $this->validateWalletPath($walletPath);
    }

    /**
     * Ensures a wallet is loaded into the Electrum daemon without altering
     * the currently selected active wallet.
     *
     * The caller must own the shared wallet mutation lock.
     *
     * @throws ElectrumWalletException
     */
    public function ensureWalletLoaded(string $walletPath, ?string $password = null): void
    {
        $walletPath = $this->validateWalletPath($walletPath);
        $loadedWallets = $this->rpc->callDaemon('list_wallets');
        $loadedPaths = $this->extractLoadedWalletPaths($loadedWallets);

        if (!$this->containsWalletPath($loadedPaths, $walletPath)) {
            $params = ['wallet_path' => $walletPath];
            if ($password !== null && $password !== '') {
                $params['password'] = $password;
            }

            $result = $this->rpc->callDaemon('load_wallet', $params);
            if ($result === null || $result === false || $result === '') {
                throw new ElectrumWalletException(
                    'Electrum wallet could not be loaded.',
                    'load_wallet'
                );
            }
        }
    }

    /**
     * Returns a list of all wallet paths currently loaded in the Electrum daemon.
     *
     * @return list<string>
     */
    public function getLoadedWallets(): array
    {
        $loadedWallets = $this->rpc->callDaemon('list_wallets');
        return $this->extractLoadedWalletPaths($loadedWallets);
    }

    public function getActiveWalletPath(): ?string
    {
        return $this->activeWalletPath;
    }

    /**
     * @return array{confirmed: float, unconfirmed: float}
     */
    public function getWalletBalance(?string $walletPath = null): array
    {
        return $this->normalizeBalance(
            $this->walletCommand('getbalance', [], $walletPath),
            'getbalance'
        );
    }

    /**
     * @return array{confirmed: float, unconfirmed: float}
     */
    public function getAddressBalance(string $address): array
    {
        $balance = $this->getAddressBalanceExact($address);

        return [
            'confirmed' => (float) $balance['confirmed'],
            'unconfirmed' => (float) $balance['unconfirmed'],
        ];
    }

    /**
     * Returns canonical decimal strings so payment code never has to recover
     * satoshis from a floating-point value.
     *
     * @return array{confirmed: string, unconfirmed: string}
     */
    public function getAddressBalanceExact(string $address): array
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');

        return $this->normalizeExactBalance(
            $this->rpc->callNetwork('getaddressbalance', ['address' => $address]),
            'getaddressbalance'
        );
    }

    public function getNewAddress(?string $walletPath = null): string
    {
        return $this->requireNonEmptyStringResult(
            $this->walletCommand('createnewaddress', [], $walletPath),
            'createnewaddress'
        );
    }

    public function validateAddress(string $address): bool
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');
        $result = $this->rpc->callNetwork('validateaddress', ['address' => $address]);

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
    public function listAddresses(bool $receiving = true, bool $change = false, ?string $walletPath = null): array
    {
        $params = [];
        if ($receiving) {
            $params['receiving'] = true;
        }
        if ($change) {
            $params['change'] = true;
        }

        $addresses = $this->walletCommand('listaddresses', $params, $walletPath);
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
    public function listUnspent(?string $walletPath = null): array
    {
        $unspent = $this->walletCommand('listunspent', [], $walletPath);
        if (!$this->isListOfArrays($unspent)) {
            throw $this->invalidResponse('listunspent');
        }

        return $unspent;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTransactions(?string $walletPath = null): array
    {
        try {
            $result = $this->walletCommand('onchain_history', [], $walletPath);
        } catch (ElectrumRPCException $exception) {
            if ($exception->getRpcCode() !== self::METHOD_NOT_FOUND) {
                throw $exception;
            }

            // Electrum releases before onchain_history exposed history instead.
            $result = $this->walletCommand('history', [], $walletPath);
        }

        if (is_array($result) && array_key_exists('transactions', $result)) {
            $result = $result['transactions'];
        }

        if (!$this->isListOfArrays($result)) {
            throw $this->invalidResponse('onchain_history');
        }

        return $result;
    }

    public function getTransaction(string $txid, ?string $walletPath = null): array|string
    {
        $txid = strtolower(trim($txid));
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $txid)) {
            throw new InvalidArgumentException('Transaction ID must be 64 hexadecimal characters.');
        }

        $result = $walletPath === null
            ? $this->rpc->callNetwork('gettransaction', ['txid' => $txid])
            : $this->rpc->callWallet('gettransaction', $walletPath, ['txid' => $txid]);
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
        $result = $this->rpc->callNetwork('deserialize', ['tx' => $hex]);
        if (!is_array($result)) {
            throw $this->invalidResponse('deserialize');
        }

        return $result;
    }

    public function createTransaction(
        string $destinationAddress,
        int|float|string $amount,
        ?string $password = null,
        ?int $feeRateSatVb = null,
        ?string $walletPath = null
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

        $result = $this->walletCommand('payto', $params, $walletPath);
        $serializedTransaction = is_array($result) ? ($result['hex'] ?? null) : $result;

        return $this->requireNonEmptyStringResult($serializedTransaction, 'payto');
    }

    public function broadcastTransaction(string $hex): string
    {
        $hex = $this->validateSerializedTransaction($hex);
        $txid = $this->rpc->callNetwork('broadcast', ['tx' => $hex]);

        return $this->requireNonEmptyStringResult($txid, 'broadcast');
    }

    public function sendPayment(
        string $destinationAddress,
        int|float|string $amount,
        ?string $password = null,
        ?int $feeRateSatVb = null,
        ?string $walletPath = null
    ): string {
        $hex = $this->createTransaction($destinationAddress, $amount, $password, $feeRateSatVb, $walletPath);

        return $this->broadcastTransaction($hex);
    }

    /**
     * @return array<string, mixed>
     */
    public function createPaymentRequest(
        int|float|string $amount,
        string $memo = '',
        ?int $expirationSeconds = null,
        ?string $walletPath = null
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

        $result = $this->walletCommand('add_request', $params, $walletPath);
        if (!is_array($result)) {
            throw $this->invalidResponse('add_request');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentRequest(string $requestId, ?string $walletPath = null): array
    {
        $requestId = $this->validateNonEmptyString($requestId, 'Payment request ID');
        $result = $this->walletCommand('get_request', ['request_id' => $requestId], $walletPath);
        if (!is_array($result)) {
            throw $this->invalidResponse('get_request');
        }

        return $result;
    }

    public function deletePaymentRequest(string $requestId, ?string $walletPath = null): void
    {
        $requestId = $this->validateNonEmptyString($requestId, 'Payment request ID');
        $this->walletCommand('delete_request', ['request_id' => $requestId], $walletPath);
    }

    public function getMasterPublicKey(?string $walletPath = null): string
    {
        return $this->requireNonEmptyStringResult(
            $this->walletCommand('getmpk', [], $walletPath),
            'getmpk'
        );
    }

    public function getSeed(string $password = '', ?string $walletPath = null): string
    {
        $params = $password !== '' ? ['password' => $password] : [];

        return $this->requireNonEmptyStringResult(
            $this->walletCommand('getseed', $params, $walletPath),
            'getseed'
        );
    }

    public function getMasterPrivateKey(string $password = '', ?string $walletPath = null): string
    {
        $params = $password !== '' ? ['password' => $password] : [];

        return $this->requireNonEmptyStringResult(
            $this->walletCommand('getmasterprivate', $params, $walletPath),
            'getmasterprivate'
        );
    }

    /** Explicit routing for all upstream wallet commands, including future payout commands.
     * Mutating callers must own WalletLockManager around ensureLoaded + command.
     */
    public function callWallet(string $method, string $walletPath, array $params = []): mixed
    {
        return $this->rpc->callWallet($method, $this->validateWalletPath($walletPath), $params);
    }

    private function walletCommand(string $method, array $params, ?string $walletPath): mixed
    {
        if ($walletPath !== null) {
            return $this->callWallet($method, $walletPath, $params);
        }
        return $this->callForActiveWallet($method, $params);
    }

    /** @deprecated Admin compatibility only. New callers must pass an explicit path. */
    public function callForActiveWallet(string $method, array $params = [], ?string $walletPath = null): mixed
    {
        $targetWallet = $walletPath !== null
            ? $this->validateWalletPath($walletPath)
            : $this->requireWalletLoaded();

        if (in_array($method, ['createnewaddress', 'add_request', 'delete_request', 'clear_requests',
            'payto', 'paytomany', 'signtransaction', 'freeze_utxo', 'unfreeze_utxo', 'addtransaction'], true)) {
            return (new WalletLockManager())->withWalletLock($targetWallet, function () use ($method, $targetWallet, $params): mixed {
                $this->ensureWalletLoaded($targetWallet);
                return $this->rpc->callWallet($method, $targetWallet, $params);
            });
        }
        return $this->rpc->callWallet($method, $targetWallet, $params);
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

            $formattedAmount = number_format($amount, 8, '.', '');
            if ((float) $formattedAmount !== $amount) {
                throw new InvalidArgumentException('Bitcoin amount must not contain sub-satoshi precision.');
            }

            $amount = rtrim(rtrim($formattedAmount, '0'), '.');
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
        $balance = $this->normalizeExactBalance($balance, $operation);

        return [
            'confirmed' => (float) $balance['confirmed'],
            'unconfirmed' => (float) $balance['unconfirmed'],
        ];
    }

    /**
     * @return array{confirmed: string, unconfirmed: string}
     */
    private function normalizeExactBalance(mixed $balance, string $operation): array
    {
        if (!is_array($balance)) {
            throw $this->invalidResponse($operation);
        }

        $confirmed = $balance['confirmed'] ?? 0;
        $unconfirmed = $balance['unconfirmed'] ?? 0;
        if (
            (!is_int($confirmed) && !is_float($confirmed) && !is_string($confirmed))
            || (!is_int($unconfirmed) && !is_float($unconfirmed) && !is_string($unconfirmed))
        ) {
            throw $this->invalidResponse($operation);
        }

        try {
            return [
                'confirmed' => BitcoinAmount::fromBtc($confirmed)->toBtcString(),
                'unconfirmed' => BitcoinAmount::fromBtc($unconfirmed)->toBtcString(),
            ];
        } catch (InvalidArgumentException $exception) {
            throw new ElectrumWalletException(
                "Electrum returned an invalid balance for '{$operation}'.",
                $operation,
                $exception
            );
        }
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
