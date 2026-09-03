<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Application-facing wrapper around Electrum wallet commands.
 *
 * Daemon-wide commands are sent with ElectrumRPC::callDaemon(). Commands that
 * operate on wallet state are sent with ElectrumRPC::callWallet() with an explicit
 * wallet_path so multiple wallets safely remain loaded concurrently in the same daemon.
 */
class ElectrumWallet
{
    private const METHOD_NOT_FOUND = -32601;

    private ElectrumRPC $rpc;
    private ElectrumWalletManager $walletManager;
    private ?string $activeWalletPath = null;

    public function __construct(ElectrumRPC $rpc, ?ElectrumWalletManager $walletManager = null)
    {
        $this->rpc = $rpc;
        $this->walletManager = $walletManager ?? new ElectrumWalletManager($rpc);
    }

    public function getWalletManager(): ElectrumWalletManager
    {
        return $this->walletManager;
    }

    /**
     * Loads a wallet when necessary and selects it for subsequent operations.
     *
     * Other wallets are deliberately left open in the Electrum daemon.
     */
    public function loadWallet(string $walletPath, ?string $password = null): void
    {
        $walletPath = $this->validateWalletPath($walletPath);
        $this->walletManager->ensureLoaded($walletPath, $password);
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
        $balance = $this->getAddressBalanceExact($address);

        return [
            'confirmed' => (float) $balance['confirmed'],
            'unconfirmed' => (float) $balance['unconfirmed'],
        ];
    }

    /**
     * Network/walletless query for address balance. Does not require loaded wallet.
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

    public function getNewAddress(): string
    {
        return $this->requireNonEmptyStringResult(
            $this->callForActiveWallet('createnewaddress'),
            'createnewaddress'
        );
    }

    /**
     * Alias for getNewAddress() matching the Electrum RPC method name 'createnewaddress'.
     */
    public function createNewAddress(): string
    {
        return $this->getNewAddress();
    }

    /**
     * Gets an unused receiving address from the wallet, or generates one if all are used.
     */
    public function getUnusedAddress(): string
    {
        try {
            return $this->requireNonEmptyStringResult(
                $this->callForActiveWallet('getunusedaddress'),
                'getunusedaddress'
            );
        } catch (ElectrumRPCException $exception) {
            // Fallback to createnewaddress if getunusedaddress is unsupported
            return $this->getNewAddress();
        }
    }

    /**
     * Checks if a Bitcoin address belongs to the currently loaded wallet.
     */
    public function isMine(string $address): bool
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');
        $result = $this->callForActiveWallet('ismine', ['address' => $address]);
        if (is_bool($result)) {
            return $result;
        }
        return false;
    }

    /**
     * Retrieves transaction history for a specific address.
     *
     * @return list<array<string, mixed>>
     */
    public function getAddressHistory(string $address): array
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');
        $result = $this->rpc->callNetwork('getaddresshistory', ['address' => $address]);
        if (!$this->isListOfArrays($result)) {
            return [];
        }
        return $result;
    }

    /**
     * Retrieves unspent transaction outputs (UTXOs) for a specific address.
     *
     * @return list<array<string, mixed>>
     */
    public function getAddressUnspent(string $address): array
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');
        $result = $this->rpc->callNetwork('getaddressunspent', ['address' => $address]);
        if (!$this->isListOfArrays($result)) {
            return [];
        }
        return $result;
    }

    /**
     * Creates an on-chain payment request with optional amount, description, and expiration.
     *
     * @return array<string, mixed>
     */
    public function createInvoiceRequest(
        int|float|string $amount,
        ?string $memo = null,
        ?int $expirationSeconds = null
    ): array {
        $amountStr = $this->normalizeBitcoinAmount($amount);
        $params = ['amount' => $amountStr];
        if ($memo !== null && trim($memo) !== '') {
            $params['memo'] = trim($memo);
        }
        if ($expirationSeconds !== null && $expirationSeconds > 0) {
            $params['expiration'] = $expirationSeconds;
        }

        $result = $this->callForActiveWallet('addrequest', $params);
        if (!is_array($result)) {
            throw $this->invalidResponse('addrequest');
        }
        return $result;
    }

    /**
     * Retrieves a payment request by address or identifier.
     *
     * @return array<string, mixed>|null
     */
    public function getRequest(string $key): ?array
    {
        $key = $this->validateNonEmptyString($key, 'Request key or address');
        try {
            $result = $this->callForActiveWallet('getrequest', ['key' => $key]);
            return is_array($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Deletes a payment request by key or address.
     */
    public function deleteRequest(string $key): bool
    {
        $key = $this->validateNonEmptyString($key, 'Request key or address');
        try {
            $result = $this->callForActiveWallet('rmrequest', ['key' => $key]);
            return $result === true || $result === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Lists all payment requests currently stored in the wallet.
     *
     * @return list<array<string, mixed>>
     */
    public function listRequests(): array
    {
        $result = $this->callForActiveWallet('listrequests');
        if (!$this->isListOfArrays($result)) {
            return [];
        }
        return $result;
    }

    /**
     * Dumps the private key for an address (WIF format). Requires wallet password if encrypted.
     */
    public function dumpPrivateKey(string $address, string $password = ''): string
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');
        $params = ['address' => $address];
        if ($password !== '') {
            $params['password'] = $password;
        }
        return $this->requireNonEmptyStringResult(
            $this->callForActiveWallet('dumpprivkey', $params),
            'dumpprivkey'
        );
    }

    /**
     * Retrieves public keys associated with an address in the wallet.
     *
     * @return list<string>
     */
    public function getPublicKeys(string $address): array
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');
        $result = $this->callForActiveWallet('getpubkeys', ['address' => $address]);
        if (!is_array($result)) {
            throw $this->invalidResponse('getpubkeys');
        }
        /** @var list<string> */
        return array_values(array_filter($result, 'is_string'));
    }

    public function validateAddress(string $address): bool
    {
        $address = $this->validateNonEmptyString($address, 'Bitcoin address');
        $result = $this->rpc->callNetwork('validateaddress', ['address' => $address]);

        if (is_bool($result)) {
            return $result;
        }

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

        return $this->rpc->callNetwork('gettransaction', ['txid' => $txid]);
    }

    public function createPayment(string $destination, int|float|string $amount, ?int $feerate = null): array|string
    {
        $destination = $this->validateNonEmptyString($destination, 'Destination address');
        $amount = $this->normalizeBitcoinAmount($amount);

        $params = [
            'destination' => $destination,
            'amount' => $amount,
        ];
        if ($feerate !== null) {
            if ($feerate < 1) {
                throw new InvalidArgumentException('Feerate must be at least 1 sat/vB.');
            }
            $params['feerate'] = $feerate;
        }

        return $this->callForActiveWallet('payto', $params);
    }

    public function signTransaction(string $transaction, string $password = ''): array|string
    {
        $transaction = $this->validateSerializedTransaction($transaction);
        $params = ['tx' => $transaction];
        if ($password !== '') {
            $params['password'] = $password;
        }

        return $this->callForActiveWallet('signtransaction', $params);
    }

    public function broadcast(string $transaction): string
    {
        $transaction = $this->validateSerializedTransaction($transaction);

        return $this->requireNonEmptyStringResult(
            $this->rpc->callNetwork('broadcast', ['tx' => $transaction]),
            'broadcast'
        );
    }

    public function isSynchronized(): bool
    {
        $result = $this->callForActiveWallet('is_synchronized');
        if (!is_bool($result)) {
            throw $this->invalidResponse('is_synchronized');
        }

        return $result;
    }

    public function getVersion(): string
    {
        return $this->requireNonEmptyStringResult(
            $this->rpc->callDaemon('version'),
            'version'
        );
    }

    public function getFeeRate(int $blocks = 6): int
    {
        $feerate = $this->rpc->callNetwork('getfeerate', ['blocks' => max(1, $blocks)]);
        if (is_numeric($feerate)) {
            return (int) round((float) $feerate);
        }

        return 1; // 1 sat/vB default fallback
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

    /**
     * Retrieves the master public key (XPUB/ZPUB) for the loaded wallet.
     */
    public function getMasterPublicKey(string $password = ''): string
    {
        $params = $password !== '' ? ['password' => $password] : [];

        return $this->requireNonEmptyStringResult(
            $this->callForActiveWallet('getmasterpublic', $params),
            'getmasterpublic'
        );
    }

    /**
     * Explicitly unloads / closes the currently active wallet in the daemon.
     */
    public function closeWallet(): void
    {
        if ($this->activeWalletPath !== null) {
            $this->walletManager->close($this->activeWalletPath);
            $this->activeWalletPath = null;
        }
    }

    private function callForActiveWallet(string $method, array $params = []): mixed
    {
        return $this->rpc->callWallet($this->requireWalletLoaded(), $method, $params);
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
