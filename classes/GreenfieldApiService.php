<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Application layer for the BTCPay Greenfield-compatible subset. */
class GreenfieldApiService
{
    private const DEFAULT_EXPIRATION_MINUTES = 15;
    private const MAX_EXPIRATION_MINUTES = 43_200;
    private const META_AMOUNT = '_btcpaylite_original_amount';
    private const META_CURRENCY = '_btcpaylite_original_currency';
    private const META_REDIRECT_URL = '_btcpaylite_redirect_url';
    private const META_REDIRECT_AUTO = '_btcpaylite_redirect_automatic';

    private GreenfieldApiRepository $repository;
    private Database $database;
    private ElectrumWallet $wallet;
    private BtcInvoiceManager $invoiceManager;
    private string $adminApiKey;
    private string $checkoutBaseUrl;
    private WebhookEndpointPolicy $webhookEndpointPolicy;
    private BitcoinMarketDataProvider $marketData;
    private ExchangeQuoteService $exchangeQuotes;
    private ?PayoutService $payoutService;

    public function __construct(
        GreenfieldApiRepository $repository,
        Database $database,
        ElectrumWallet $wallet,
        BtcInvoiceManager $invoiceManager,
        string $adminApiKey,
        string $checkoutBaseUrl,
        ?WebhookEndpointPolicy $webhookEndpointPolicy = null,
        ?BitcoinMarketDataProvider $marketData = null,
        ?ExchangeQuoteService $exchangeQuotes = null,
        ?PayoutService $payoutService = null
    ) {
        $checkoutBaseUrl = rtrim(trim($checkoutBaseUrl), '/');
        $parts = parse_url($checkoutBaseUrl);
        if ($checkoutBaseUrl === ''
            || strlen($checkoutBaseUrl) > 2_048
            || preg_match('/[\x00-\x1F\x7F]/', $checkoutBaseUrl)
            || filter_var($checkoutBaseUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new GreenfieldApiException('Checkout base URL is invalid.', 'configure_api');
        }

        $this->repository = $repository;
        $this->database = $database;
        $this->wallet = $wallet;
        $this->invoiceManager = $invoiceManager;
        $this->adminApiKey = trim($adminApiKey);
        $this->checkoutBaseUrl = $checkoutBaseUrl;
        $this->webhookEndpointPolicy = $webhookEndpointPolicy ?? new WebhookEndpointPolicy();
        $this->marketData = $marketData ?? new HttpBitcoinMarketDataProvider();
        $this->exchangeQuotes = $exchangeQuotes ?? new ExchangeQuoteService($this->marketData);
        $this->payoutService = $payoutService;
    }

    /** @return array<string,mixed> */
    public function getServerInfo(string $apiKey): array
    {
        $this->authorizeApiKey($apiKey);
        return [
            'version' => '2.0.0-btcpaylite',
            'onion' => '',
            'fullySynched' => true,
            'supportedPaymentMethods' => ['BTC-CHAIN'],
            'syncStatus' => ['blockchainInfo' => ['available' => true, 'synced' => true]],
        ];
    }

    /** @return array<string,mixed> */
    public function getCurrentApiKey(string $apiKey): array
    {
        $store = $this->repository->findStoreByApiKey($apiKey);
        if ($store === null) {
            $this->authorizeApiKey($apiKey);
            return ['apiKey' => $apiKey, 'label' => 'BTCPayLite administrator', 'permissions' => []];
        }

        $suffix = ':' . $store['id'];
        return [
            'apiKey' => $apiKey,
            'label' => 'BTCPayLite store ' . $store['name'],
            'permissions' => [
                'btcpay.store.canviewinvoices' . $suffix,
                'btcpay.store.cancreateinvoice' . $suffix,
                'btcpay.store.canviewstoresettings' . $suffix,
                'btcpay.store.canmodifyinvoices' . $suffix,
                'btcpay.store.webhooks.canmodifywebhooks' . $suffix,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function getStore(string $storeId, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        return [
            'id' => $store['id'],
            'name' => $store['name'],
            'website' => null,
            'defaultCurrency' => 'BTC',
            'invoiceExpiration' => self::DEFAULT_EXPIRATION_MINUTES,
            'defaultPaymentMethod' => 'BTC-CHAIN',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getStorePaymentMethods(string $storeId, string $apiKey): array
    {
        $this->authenticateStore($storeId, $apiKey);
        return [[
            'paymentMethodId' => 'BTC-CHAIN',
            'paymentMethod' => 'BTC',
            'cryptoCode' => 'BTC',
            'enabled' => true,
        ]];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createExchangeQuote(string $storeId, array $input, string $apiKey): array
    {
        $this->authenticateStore($storeId, $apiKey);
        if (!is_string($input['amount'] ?? null) || !is_string($input['currency'] ?? null)) {
            throw new GreenfieldApiException(
                'Exchange quote requires string amount and currency fields.',
                'create_exchange_quote',
                400
            );
        }

        try {
            return $this->exchangeQuotes->quote($input['amount'], $input['currency']);
        } catch (InvalidArgumentException $exception) {
            throw new GreenfieldApiException(
                $exception->getMessage(),
                'create_exchange_quote',
                400,
                $exception
            );
        } catch (RuntimeException $exception) {
            throw new GreenfieldApiException(
                'Exchange rate is temporarily unavailable.',
                'create_exchange_quote',
                503,
                $exception
            );
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createPayout(
        string $storeId,
        array $input,
        string $apiKey,
        string $idempotencyKey
    ): array {
        return $this->payoutCall(
            fn (PayoutService $service): array => $service->create($storeId, $input, $apiKey, $idempotencyKey)
        );
    }

    /** @return list<array<string,mixed>> */
    public function getPayouts(string $storeId, string $apiKey): array
    {
        return $this->payoutCall(
            fn (PayoutService $service): array => $service->list($storeId, $apiKey)
        );
    }

    /** @return array<string,mixed> */
    public function getPayout(string $payoutId, string $apiKey): array
    {
        return $this->payoutCall(
            fn (PayoutService $service): array => $service->get($payoutId, $apiKey)
        );
    }

    /** @return array<string,mixed> */
    public function approvePayout(string $payoutId, int $revision, string $apiKey): array
    {
        return $this->payoutCall(
            fn (PayoutService $service): array => $service->approve($payoutId, $revision, $apiKey)
        );
    }

    /** @return array<string,mixed> */
    public function getInvoice(string $storeId, string $invoiceId, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        $invoice = $this->loadStoreInvoice($store['id'], $invoiceId);
        return $this->invoiceResponse($invoice, $store['id']);
    }

    /** @return list<array<string,mixed>> */
    public function getInvoicePaymentMethods(string $storeId, string $invoiceId, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        $invoice = $this->loadStoreInvoice($store['id'], $invoiceId);
        $settled = ($invoice['status'] ?? null) === 'Settled';
        $btcAmount = (string) $invoice['amount'];
        return [[
            'paymentMethodId' => 'BTC-CHAIN',
            'paymentMethod' => 'BTC',
            'cryptoCode' => 'BTC',
            'currency' => 'BTC',
            'destination' => (string) $invoice['btc_address'],
            'paymentLink' => (string) $invoice['bip21_uri'],
            'rate' => '1',
            'paymentMethodPaid' => $settled ? $btcAmount : '0.00000000',
            'totalPaid' => $settled ? $btcAmount : '0.00000000',
            'due' => $settled ? '0.00000000' : $btcAmount,
            'amount' => $btcAmount,
            'paymentMethodFee' => '0.00000000',
            'networkFee' => '0.00000000',
            'payments' => [],
        ]];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createInvoice(string $storeId, array $input, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        $originalAmount = $this->decimalAmount($input['amount'] ?? null);
        $currency = $this->currency($input['currency'] ?? 'BTC');
        $btcAmount = $this->convertToBitcoin($originalAmount, $currency);

        $metadata = $input['metadata'] ?? [];
        if ($metadata === null) {
            $metadata = [];
        }
        if (!is_array($metadata) || ($metadata !== [] && $this->isList($metadata))) {
            throw new GreenfieldApiException('Invoice metadata must be an object.', 'create_invoice', 400);
        }
        foreach ([self::META_AMOUNT, self::META_CURRENCY, self::META_REDIRECT_URL, self::META_REDIRECT_AUTO] as $reserved) {
            if (array_key_exists($reserved, $metadata)) {
                throw new GreenfieldApiException('Invoice metadata contains a reserved key.', 'create_invoice', 400);
            }
        }

        $checkout = $input['checkout'] ?? [];
        if ($checkout === null) {
            $checkout = [];
        }
        if (!is_array($checkout) || ($checkout !== [] && $this->isList($checkout))) {
            throw new GreenfieldApiException('Invoice checkout options must be an object.', 'create_invoice', 400);
        }
        $expiration = $this->expirationMinutes($checkout['expirationMinutes'] ?? $input['expirationMinutes'] ?? null);
        $redirectUrl = $this->optionalRedirectUrl($checkout['redirectURL'] ?? null);
        $redirectAutomatically = ($checkout['redirectAutomatically'] ?? false) === true;

        $storedMetadata = $metadata;
        $storedMetadata[self::META_AMOUNT] = $originalAmount;
        $storedMetadata[self::META_CURRENCY] = $currency;
        if ($redirectUrl !== null) {
            $storedMetadata[self::META_REDIRECT_URL] = $redirectUrl;
            $storedMetadata[self::META_REDIRECT_AUTO] = $redirectAutomatically;
        }

        $walletPath = $this->resolveWalletPath($store['wallet_path']);
        try {
            $invoice = $this->database->withNamedLock(
                'electrum_rpc',
                10,
                function () use ($walletPath, $store, $btcAmount, $storedMetadata, $expiration): array {
                    $this->wallet->loadWallet($walletPath);
                    return $this->invoiceManager->createDatabaseInvoice(
                        $store['id'],
                        $btcAmount,
                        $storedMetadata,
                        $expiration
                    );
                }
            );
        } catch (DatabaseException $exception) {
            throw new GreenfieldApiException(
                $exception->getCode() === 503 ? 'Invoice creation is busy. Please retry shortly.' : 'Invoice could not be created.',
                'create_invoice',
                $exception->getCode() === 503 ? 503 : 500,
                $exception
            );
        } catch (InvalidArgumentException|BtcInvoiceManagerException $exception) {
            $status = $exception->getCode() >= 400 && $exception->getCode() < 500 ? $exception->getCode() : 500;
            throw new GreenfieldApiException(
                $status === 500 ? 'Invoice could not be created.' : 'Invoice input is invalid.',
                'create_invoice',
                $status,
                $exception
            );
        } catch (Throwable $exception) {
            throw new GreenfieldApiException('Invoice could not be created.', 'create_invoice', 500, $exception);
        }

        $invoice['store_id'] = $store['id'];
        $invoice['metadata'] = $storedMetadata;
        return $this->invoiceResponse($invoice, $store['id']);
    }

    /** @return list<array<string,mixed>> */
    public function getWebhooks(string $storeId, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        return array_map(fn (array $webhook): array => $this->webhookResponse($webhook, false), $this->repository->listWebhooks($store['id']));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createWebhook(string $storeId, array $input, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        $url = $this->validateWebhookUrl($input['url'] ?? null);
        $secret = $this->optionalWebhookSecret($input['secret'] ?? null);
        $webhook = $this->repository->findOrCreateWebhook($store['id'], $url, $secret);
        return $this->webhookResponse($webhook, true);
    }

    /** @return array{id:string,name:string,api_key:string,wallet_path:string} */
    private function authenticateStore(string $storeId, string $apiKey): array
    {
        $storeId = $this->validateIdentifier($storeId, 'Store ID');
        $apiKey = trim($apiKey);
        $store = $this->repository->findStore($storeId);
        $storedKey = $store['api_key'] ?? '';
        $storeAuthorized = $apiKey !== '' && hash_equals(hash('sha256', $storedKey, true), hash('sha256', $apiKey, true));
        $adminAuthorized = $this->isAdminKey($apiKey);
        if ($store === null || (!$storeAuthorized && !$adminAuthorized)) {
            throw new GreenfieldApiException('Invalid API key or store.', 'authenticate', 401);
        }
        return $store;
    }

    /** @template T @param callable(PayoutService):T $callback @return T */
    private function payoutCall(callable $callback): mixed
    {
        if ($this->payoutService === null) {
            throw new GreenfieldApiException('Payout API is disabled.', 'payout_api', 503);
        }
        try {
            return $callback($this->payoutService);
        } catch (PayoutException $exception) {
            throw new GreenfieldApiException(
                $exception->getMessage(),
                $exception->getOperation(),
                $exception->getHttpStatus(),
                $exception
            );
        }
    }

    private function authorizeApiKey(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || ($this->repository->findStoreByApiKey($apiKey) === null && !$this->isAdminKey($apiKey))) {
            throw new GreenfieldApiException('Invalid API key.', 'authenticate', 401);
        }
    }

    private function isAdminKey(string $apiKey): bool
    {
        return $apiKey !== '' && $this->adminApiKey !== ''
            && hash_equals(hash('sha256', $this->adminApiKey, true), hash('sha256', $apiKey, true));
    }

    /** @return array<string,mixed> */
    private function loadStoreInvoice(string $storeId, string $invoiceId): array
    {
        $invoiceId = $this->validateIdentifier($invoiceId, 'Invoice ID');
        try {
            $invoice = $this->invoiceManager->getDatabaseInvoice($invoiceId);
        } catch (BtcInvoiceManagerException $exception) {
            $status = $exception->getCode() === 404 ? 404 : 500;
            throw new GreenfieldApiException($status === 404 ? 'Invoice was not found.' : 'Invoice could not be loaded.', 'get_invoice', $status, $exception);
        }
        if (($invoice['store_id'] ?? null) !== $storeId) {
            throw new GreenfieldApiException('Invoice was not found.', 'get_invoice', 404);
        }
        return $invoice;
    }

    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    private function invoiceResponse(array $invoice, string $storeId): array
    {
        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $amount = is_string($metadata[self::META_AMOUNT] ?? null) ? $metadata[self::META_AMOUNT] : (string) $invoice['amount'];
        $currency = is_string($metadata[self::META_CURRENCY] ?? null) ? $metadata[self::META_CURRENCY] : 'BTC';
        unset($metadata[self::META_AMOUNT], $metadata[self::META_CURRENCY], $metadata[self::META_REDIRECT_URL], $metadata[self::META_REDIRECT_AUTO]);
        $created = (int) ($invoice['created_at'] ?? 0);
        $expires = (int) ($invoice['expires_at'] ?? 0);
        return [
            'id' => (string) $invoice['id'],
            'storeId' => $storeId,
            'amount' => $amount,
            'currency' => $currency,
            'type' => 'Standard',
            'checkoutLink' => $this->checkoutBaseUrl . '/pay?id=' . rawurlencode((string) $invoice['id']),
            'createdTime' => $created,
            'expirationTime' => $expires,
            'monitoringTime' => $expires,
            'archived' => false,
            'status' => (string) ($invoice['status'] ?? 'New'),
            'additionalStatus' => 'None',
            'availableStatusesForManualMarking' => [],
            'metadata' => $metadata,
        ];
    }

    /** @param array{id:string,url:string,secret:string} $webhook @return array<string,mixed> */
    private function webhookResponse(array $webhook, bool $includeSecret): array
    {
        $result = [
            'id' => $webhook['id'],
            'enabled' => true,
            'automaticRedelivery' => true,
            'url' => $webhook['url'],
            'authorizedEvents' => ['everything' => true, 'specificEvents' => []],
        ];
        if ($includeSecret) {
            $result['secret'] = $webhook['secret'];
        }
        return $result;
    }

    private function decimalAmount(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw new GreenfieldApiException('Invoice amount must be a decimal string.', 'create_invoice', 400);
        }
        $value = trim($value);
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,12})?\z/D', $value) || (float) $value <= 0) {
            throw new GreenfieldApiException('Invoice amount is invalid.', 'create_invoice', 400);
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value;
    }

    private function currency(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Z]{3,5}\z/D', strtoupper(trim($value)))) {
            throw new GreenfieldApiException('Invoice currency is invalid.', 'create_invoice', 400);
        }
        return strtoupper(trim($value));
    }

    private function convertToBitcoin(string $amount, string $currency): string
    {
        if ($currency === 'BTC') {
            try {
                return BitcoinAmount::fromBtc($amount)->toBtcString();
            } catch (InvalidArgumentException $exception) {
                throw new GreenfieldApiException('BTC amount is invalid.', 'create_invoice', 400, $exception);
            }
        }
        if ($currency === 'SAT' || $currency === 'SATS') {
            if (!ctype_digit($amount) || strlen($amount) > 16) {
                throw new GreenfieldApiException('Satoshi amount is invalid.', 'create_invoice', 400);
            }
            $sats = (int) $amount;
            if ($sats < 1) {
                throw new GreenfieldApiException('Satoshi amount is invalid.', 'create_invoice', 400);
            }
            return BitcoinAmount::fromSatoshis($sats)->toBtcString();
        }

        try {
            $price = $this->marketData->getFiatPrice($currency);
        } catch (Throwable $exception) {
            throw new GreenfieldApiException('Exchange rate is temporarily unavailable.', 'convert_currency', 503, $exception);
        }
        if ($price === null || !is_finite($price) || $price <= 0) {
            throw new GreenfieldApiException('Currency is not supported by the exchange-rate provider.', 'convert_currency', 400);
        }
        $btc = number_format(((float) $amount) / $price, 8, '.', '');
        try {
            $normalized = BitcoinAmount::fromBtc($btc);
        } catch (InvalidArgumentException $exception) {
            throw new GreenfieldApiException('Converted BTC amount is invalid.', 'convert_currency', 400, $exception);
        }
        if (!$normalized->isPositive()) {
            throw new GreenfieldApiException('Invoice amount is below one satoshi.', 'convert_currency', 400);
        }
        return $normalized->toBtcString();
    }

    private function expirationMinutes(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_EXPIRATION_MINUTES;
        }
        $minutes = is_int($value) ? $value : (is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : 0);
        if ($minutes < 1 || $minutes > self::MAX_EXPIRATION_MINUTES) {
            throw new GreenfieldApiException('Invoice expiration is invalid.', 'create_invoice', 400);
        }
        return $minutes;
    }

    private function optionalRedirectUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 2_048 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new GreenfieldApiException('Checkout redirect URL is invalid.', 'create_invoice', 400);
        }
        $parts = parse_url($value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new GreenfieldApiException('Checkout redirect URL is invalid.', 'create_invoice', 400);
        }
        return $value;
    }

    private function resolveWalletPath(string $configuredPath): string
    {
        $configuredPath = trim($configuredPath);
        if ($configuredPath === '' || str_contains($configuredPath, "\0")) {
            throw new GreenfieldApiException('Store wallet configuration is invalid.', 'load_wallet');
        }
        $walletPath = realpath($configuredPath);
        if ($walletPath === false || !is_file($walletPath)) {
            throw new GreenfieldApiException('Store wallet is unavailable.', 'load_wallet');
        }
        return $walletPath;
    }

    private function validateWebhookUrl(mixed $value): string
    {
        if (!is_string($value)) {
            throw new GreenfieldApiException('Webhook URL is required.', 'create_webhook', 400);
        }
        try {
            return $this->webhookEndpointPolicy->inspect($value)['url'];
        } catch (WebhookDeliveryException $exception) {
            throw new GreenfieldApiException($exception->getMessage(), 'create_webhook', 400, $exception);
        }
    }

    private function optionalWebhookSecret(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || strlen($value) < 16 || strlen($value) > 255 || str_contains($value, "\0")) {
            throw new GreenfieldApiException('Webhook secret is invalid.', 'create_webhook', 400);
        }
        return $value;
    }

    private function validateIdentifier(string $identifier, string $field): string
    {
        $identifier = trim($identifier);
        if (!preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $identifier)) {
            throw new GreenfieldApiException("{$field} is invalid.", 'validate_request', 400);
        }
        return $identifier;
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expected) {
                return false;
            }
            ++$expected;
        }
        return true;
    }
}
