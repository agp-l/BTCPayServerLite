<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * High-risk outgoing payment boundary.
 *
 * Invoice API keys are never accepted here. Every store needs a separate
 * payout key and the feature is disabled unless explicitly enabled.
 */
final class PayoutService
{
    private GreenfieldApiRepository $stores;
    private PayoutRepository $payouts;
    private Database $database;
    private ElectrumWallet $wallet;
    private ExchangeQuoteService $quotes;
    /** @var array<string,string> */
    private array $apiKeys;
    /** @var array<string,string> */
    private array $walletPasswords;
    private BitcoinAmount $maxPerPayout;
    private BitcoinAmount $dailyLimit;
    private bool $enabled;

    /** @param array<string,string> $apiKeys @param array<string,string> $walletPasswords */
    public function __construct(
        GreenfieldApiRepository $stores,
        PayoutRepository $payouts,
        Database $database,
        ElectrumWallet $wallet,
        ExchangeQuoteService $quotes,
        array $apiKeys,
        array $walletPasswords,
        string $maxPerPayout,
        string $dailyLimit,
        bool $enabled
    ) {
        $this->stores = $stores;
        $this->payouts = $payouts;
        $this->database = $database;
        $this->wallet = $wallet;
        $this->quotes = $quotes;
        $this->apiKeys = $this->stringMap($apiKeys, 'payout API key');
        $this->walletPasswords = $this->stringMap($walletPasswords, 'wallet password', true);
        $this->maxPerPayout = BitcoinAmount::fromBtc($maxPerPayout);
        $this->dailyLimit = BitcoinAmount::fromBtc($dailyLimit);
        if (!$this->maxPerPayout->isPositive() || !$this->dailyLimit->isPositive()
            || $this->maxPerPayout->compare($this->dailyLimit) > 0
        ) {
            throw new InvalidArgumentException('Payout limits are invalid.');
        }
        $this->enabled = $enabled;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(
        string $storeId,
        array $input,
        string $apiKey,
        string $idempotencyKey
    ): array {
        $store = $this->authenticate($storeId, $apiKey);
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);
        $idempotencyHash = hash('sha256', $idempotencyKey, true);

        $destination = $this->destination($input['destination'] ?? null);
        $amount = $this->decimal($input['amount'] ?? null);
        $currency = $this->currency($input['currency'] ?? 'BTC');
        $method = strtoupper(trim((string) ($input['payoutMethodId'] ?? 'BTC-CHAIN')));
        if (!in_array($method, ['BTC', 'BTC-CHAIN'], true)) {
            throw new PayoutException('Only BTC-CHAIN payouts are supported.', 'create_payout', 400);
        }
        $feeRate = $this->feeRate($input['feeRate'] ?? null);
        $approved = ($input['approved'] ?? false) === true;
        $metadata = $this->metadata($input['metadata'] ?? []);

        $requestHash = $this->requestHash([
            'destination' => $destination,
            'amount' => $amount,
            'currency' => $currency,
            'payoutMethodId' => 'BTC-CHAIN',
            'feeRate' => $feeRate,
            'approved' => $approved,
            'metadata' => $metadata,
        ]);

        $existing = $this->payouts->findByIdempotency($storeId, $idempotencyHash);
        if ($existing !== null) {
            if (!is_string($existing['request_hash'] ?? null)
                || !hash_equals($existing['request_hash'], $requestHash)
            ) {
                throw new PayoutException(
                    'Idempotency key was already used for a different payout.',
                    'create_payout',
                    409
                );
            }
            if ($approved && $existing['state'] === 'AwaitingApproval') {
                return $this->approveAndSend($existing, $store, (int) $existing['revision']);
            }
            return $this->response($existing);
        }

        if ($currency === 'BTC') {
            try {
                $payoutAmount = BitcoinAmount::fromBtc($amount);
            } catch (InvalidArgumentException $exception) {
                throw new PayoutException($exception->getMessage(), 'create_payout', 400, $exception);
            }
            $exchangeFee = BitcoinAmount::fromSatoshis(0);
        } else {
            try {
                $quote = $this->quotes->quote($amount, $currency);
                $payoutAmount = BitcoinAmount::fromBtc((string) $quote['payoutAmount']);
                $exchangeFee = BitcoinAmount::fromBtc((string) $quote['feeAmount']);
                $metadata['_btcpaylite_exchange_quote'] = [
                    'rate' => $quote['rate'],
                    'feeBasisPoints' => $quote['feeBasisPoints'],
                    'createdAt' => $quote['createdAt'],
                ];
            } catch (InvalidArgumentException $exception) {
                throw new PayoutException($exception->getMessage(), 'create_payout', 400, $exception);
            } catch (Throwable $exception) {
                throw new PayoutException('Exchange rate is temporarily unavailable.', 'create_payout', 503, $exception);
            }
        }

        $this->enforcePerPayoutLimit($payoutAmount);
        $now = time();
        $walletPath = $this->walletPath($store['wallet_path']);

        try {
            return $this->database->withNamedLock(
                'payout_' . substr(hash('sha256', $storeId), 0, 40),
                10,
                function () use (
                    $store,
                    $walletPath,
                    $destination,
                    $amount,
                    $currency,
                    $payoutAmount,
                    $exchangeFee,
                    $feeRate,
                    $approved,
                    $metadata,
                    $idempotencyHash,
                    $requestHash,
                    $now
                ): array {
                    $this->wallet->loadWallet($walletPath, $this->walletPasswords[$store['id']] ?? null);
                    if (!$this->wallet->validateAddress($destination)) {
                        throw new PayoutException('Destination is not a valid Bitcoin address.', 'create_payout', 400);
                    }

                    $reserved = $this->payouts->reservedAmountSince($store['id'], $now - 86_400);
                    if ($reserved->add($payoutAmount)->compare($this->dailyLimit) > 0) {
                        throw new PayoutException('Daily payout limit would be exceeded.', 'create_payout', 409);
                    }

                    $payout = $this->payouts->reserve([
                        'id' => 'po_' . bin2hex(random_bytes(16)),
                        'store_id' => $store['id'],
                        'idempotency_hash' => $idempotencyHash,
                        'request_hash' => $requestHash,
                        'destination' => $destination,
                        'original_currency' => $currency,
                        'original_amount' => $amount,
                        'payout_amount' => $payoutAmount->toBtcString(),
                        'exchange_fee' => $exchangeFee->toBtcString(),
                        'fee_rate_sat_vb' => $feeRate,
                        'state' => $approved ? 'AwaitingPayment' : 'AwaitingApproval',
                        'metadata' => $metadata,
                        'created_at' => $now,
                    ]);

                    return $approved
                        ? $this->prepareAndBroadcast($payout, $store)
                        : $this->response($payout);
                }
            );
        } catch (PayoutException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PayoutException('Payout processing is temporarily unavailable.', 'create_payout', 503, $exception);
        }
    }

    /** @return array<string,mixed> */
    public function get(string $payoutId, string $apiKey): array
    {
        $payout = $this->requirePayout($payoutId);
        $this->authenticate((string) $payout['store_id'], $apiKey);
        return $this->response($payout);
    }

    /** @return list<array<string,mixed>> */
    public function list(string $storeId, string $apiKey): array
    {
        $this->authenticate($storeId, $apiKey);
        return array_map(fn (array $payout): array => $this->response($payout), $this->payouts->listForStore($storeId));
    }

    /** @return array<string,mixed> */
    public function approve(string $payoutId, int $revision, string $apiKey): array
    {
        $payout = $this->requirePayout($payoutId);
        $store = $this->authenticate((string) $payout['store_id'], $apiKey);
        return $this->approveAndSend($payout, $store, $revision);
    }

    /** @param array<string,mixed> $payout @param array<string,mixed> $store */
    private function approveAndSend(array $payout, array $store, int $revision): array
    {
        $walletPath = $this->walletPath((string) $store['wallet_path']);
        try {
            return $this->database->withNamedLock(
                'payout_' . substr(hash('sha256', (string) $store['id']), 0, 40),
                10,
                function () use ($payout, $store, $revision, $walletPath): array {
                    $current = $this->requirePayout((string) $payout['id']);
                    if ($current['state'] === 'InProgress') {
                        return $this->response($current);
                    }
                    if ($current['state'] === 'AwaitingApproval') {
                        $current = $this->payouts->approve((string) $current['id'], $revision, time());
                    }
                    $this->wallet->loadWallet($walletPath, $this->walletPasswords[$store['id']] ?? null);
                    if (!$this->wallet->validateAddress((string) $current['destination'])) {
                        throw new PayoutException('Destination is not a valid Bitcoin address.', 'approve_payout', 400);
                    }
                    return $this->prepareAndBroadcast($current, $store);
                }
            );
        } catch (PayoutException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PayoutException('Payout approval is temporarily unavailable.', 'approve_payout', 503, $exception);
        }
    }

    /** @param array<string,mixed> $payout @param array<string,mixed> $store */
    private function prepareAndBroadcast(array $payout, array $store): array
    {
        if ($payout['state'] === 'AwaitingPayment') {
            try {
                $raw = $this->wallet->createTransaction(
                    (string) $payout['destination'],
                    (string) $payout['payout_amount'],
                    $this->walletPasswords[$store['id']] ?? null,
                    $payout['fee_rate_sat_vb']
                );
                $payout = $this->payouts->markPrepared((string) $payout['id'], $raw, time());
            } catch (PayoutException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new PayoutException('Bitcoin transaction could not be prepared.', 'prepare_payout', 503, $exception);
            }
        }

        if ($payout['state'] !== 'Prepared' || !is_string($payout['raw_transaction']) || $payout['raw_transaction'] === '') {
            if ($payout['state'] === 'InProgress') {
                return $this->response($payout);
            }
            throw new PayoutException('Payout is not ready for broadcast.', 'broadcast_payout', 409);
        }

        try {
            $txid = $this->wallet->broadcastTransaction($payout['raw_transaction']);
            $payout = $this->payouts->markBroadcast((string) $payout['id'], $txid, time());
        } catch (Throwable $exception) {
            $this->payouts->rememberBroadcastFailure((string) $payout['id'], 'Broadcast failed.', time());
            throw new PayoutException(
                'Prepared transaction could not be broadcast. Retry with the same idempotency key.',
                'broadcast_payout',
                503,
                $exception
            );
        }

        return $this->response($payout);
    }

    /** @return array<string,mixed> */
    private function authenticate(string $storeId, string $apiKey): array
    {
        if (!$this->enabled) {
            throw new PayoutException('Payout API is disabled.', 'authenticate_payout', 503);
        }
        $store = $this->stores->findStore($storeId);
        $expected = $this->apiKeys[$storeId] ?? null;
        if ($store === null || !is_string($expected) || $expected === ''
            || !hash_equals($expected, trim($apiKey))
        ) {
            throw new PayoutException('Payout API key is invalid.', 'authenticate_payout', 401);
        }
        return $store;
    }

    /** @return array<string,mixed> */
    private function requirePayout(string $payoutId): array
    {
        if (!preg_match('/\Apo_[0-9a-f]{32}\z/D', $payoutId)) {
            throw new PayoutException('Payout ID is invalid.', 'find_payout', 400);
        }
        $payout = $this->payouts->find($payoutId);
        if ($payout === null) {
            throw new PayoutException('Payout was not found.', 'find_payout', 404);
        }
        return $payout;
    }

    /** @param array<string,mixed> $payout @return array<string,mixed> */
    private function response(array $payout): array
    {
        $state = $payout['state'] === 'Prepared' ? 'AwaitingPayment' : $payout['state'];
        $proof = null;
        if (is_string($payout['txid']) && preg_match('/\A[0-9a-f]{64}\z/D', $payout['txid'])) {
            $proof = ['proofType' => 'PayoutTransactionOnChainBlob', 'id' => $payout['txid']];
        }
        return [
            'id' => $payout['id'],
            'revision' => $payout['revision'],
            'pullPaymentId' => null,
            'date' => (string) $payout['created_at'],
            'destination' => $payout['destination'],
            'originalCurrency' => $payout['original_currency'],
            'originalAmount' => $payout['original_amount'],
            'payoutCurrency' => 'BTC',
            'payoutAmount' => $payout['payout_amount'],
            'payoutMethodId' => 'BTC-CHAIN',
            'state' => $state,
            'paymentProof' => $proof,
            'metadata' => $payout['metadata'],
        ];
    }

    private function enforcePerPayoutLimit(BitcoinAmount $amount): void
    {
        if (!$amount->isPositive() || $amount->compare($this->maxPerPayout) > 0) {
            throw new PayoutException('Per-payout limit would be exceeded.', 'create_payout', 409);
        }
    }

    private function destination(mixed $value): string
    {
        if (!is_string($value)) {
            throw new PayoutException('Destination is required.', 'create_payout', 400);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 150 || preg_match('/[\x00-\x20\x7F]/', $value)) {
            throw new PayoutException('Destination is invalid.', 'create_payout', 400);
        }
        return $value;
    }

    private function decimal(mixed $value): string
    {
        if (!is_string($value)) {
            throw new PayoutException('Amount must be a decimal string.', 'create_payout', 400);
        }
        $value = trim($value);
        if (!preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?\z/D', $value)
            || str_replace(['0', '.'], '', $value) === ''
        ) {
            throw new PayoutException('Amount is invalid.', 'create_payout', 400);
        }
        return $value;
    }

    private function currency(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z]{3}\z/D', trim($value))) {
            throw new PayoutException('Currency is invalid.', 'create_payout', 400);
        }
        return strtoupper(trim($value));
    }

    private function feeRate(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > 10_000) {
            throw new PayoutException('Fee rate must be between 1 and 10000 sat/vbyte.', 'create_payout', 400);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function metadata(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new PayoutException('Payout metadata must be an object.', 'create_payout', 400);
        }
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PayoutException('Payout metadata is invalid.', 'create_payout', 400, $exception);
        }
        if (strlen($json) > 8_192) {
            throw new PayoutException('Payout metadata is too large.', 'create_payout', 413);
        }
        return $value;
    }

    /** @param array<string,mixed> $request */
    private function requestHash(array $request): string
    {
        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PayoutException('Payout request could not be encoded.', 'create_payout', 400, $exception);
        }
        return hash('sha256', $json, true);
    }

    private function idempotencyKey(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/D', $value)) {
            throw new PayoutException('Idempotency-Key must contain 16 to 128 safe characters.', 'create_payout', 400);
        }
        return $value;
    }

    private function walletPath(string $walletPath): string
    {
        $walletPath = trim($walletPath);
        $realPath = realpath($walletPath);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new PayoutException('Store wallet is unavailable.', 'load_wallet', 503);
        }
        return $realPath;
    }

    /** @param array<mixed,mixed> $values @return array<string,string> */
    private function stringMap(array $values, string $name, bool $allowEmptyValue = false): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '' || !is_string($value)
                || (!$allowEmptyValue && strlen($value) < 32)
            ) {
                throw new InvalidArgumentException('Configured ' . $name . ' map is invalid.');
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
