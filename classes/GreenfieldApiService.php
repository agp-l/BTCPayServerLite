<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use Throwable;

/**
 * Application service for authenticated, database-backed Greenfield operations.
 */
class GreenfieldApiService
{
    private const DEFAULT_EXPIRATION_MINUTES = 15;
    private const MAX_EXPIRATION_MINUTES = 43_200;

    private GreenfieldApiRepository $repository;
    private Database $database;
    private ElectrumWallet $wallet;
    private BtcInvoiceManager $invoiceManager;
    private string $adminApiKey;
    private string $checkoutBaseUrl;

    public function __construct(
        GreenfieldApiRepository $repository,
        Database $database,
        ElectrumWallet $wallet,
        BtcInvoiceManager $invoiceManager,
        string $adminApiKey,
        string $checkoutBaseUrl
    ) {
        $checkoutBaseUrl = rtrim(trim($checkoutBaseUrl), '/');
        if ($checkoutBaseUrl === '' || preg_match('/[\x00-\x1F\x7F]/', $checkoutBaseUrl)) {
            throw new GreenfieldApiException('Checkout base URL is invalid.', 'configure_api');
        }

        $this->repository = $repository;
        $this->database = $database;
        $this->wallet = $wallet;
        $this->invoiceManager = $invoiceManager;
        $this->adminApiKey = trim($adminApiKey);
        $this->checkoutBaseUrl = $checkoutBaseUrl;
    }

    /** @return array<string, mixed> */
    public function getStore(string $storeId, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);

        return [
            'id' => $store['id'],
            'name' => $store['name'],
            'website' => null,
            'defaultPaymentMethod' => 'BTC',
        ];
    }

    /** @return array<string, mixed> */
    public function getInvoice(string $storeId, string $invoiceId, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        $invoiceId = $this->validateIdentifier($invoiceId, 'Invoice ID');

        try {
            $invoice = $this->invoiceManager->getDatabaseInvoice($invoiceId);
        } catch (BtcInvoiceManagerException $exception) {
            $status = $exception->getCode() === 404 ? 404 : 500;
            throw new GreenfieldApiException(
                $status === 404 ? 'Invoice was not found.' : 'Invoice could not be loaded.',
                'get_invoice',
                $status,
                $exception
            );
        }

        if (($invoice['store_id'] ?? null) !== $store['id']) {
            throw new GreenfieldApiException('Invoice was not found.', 'get_invoice', 404);
        }

        return $this->invoiceResponse($invoice, $store['id']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createInvoice(string $storeId, array $input, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        $amount = $input['amount'] ?? null;
        if (!is_string($amount)) {
            throw new GreenfieldApiException(
                'Invoice amount must be a BTC decimal string.',
                'create_invoice',
                400
            );
        }

        $metadata = $input['metadata'] ?? [];
        if (!is_array($metadata) || ($metadata !== [] && $this->isList($metadata))) {
            throw new GreenfieldApiException('Invoice metadata must be an object.', 'create_invoice', 400);
        }
        $expirationMinutes = $this->expirationMinutes($input['expirationMinutes'] ?? null);
        $walletPath = $this->resolveWalletPath($store['wallet_path']);

        try {
            $invoice = $this->database->withNamedLock(
                'electrum_rpc',
                10,
                function () use ($walletPath, $store, $amount, $metadata, $expirationMinutes): array {
                    $this->wallet->loadWallet($walletPath);

                    return $this->invoiceManager->createDatabaseInvoice(
                        $store['id'],
                        $amount,
                        $metadata,
                        $expirationMinutes
                    );
                }
            );
        } catch (DatabaseException $exception) {
            throw new GreenfieldApiException(
                $exception->getCode() === 503
                    ? 'Invoice creation is busy. Please retry shortly.'
                    : 'Invoice could not be created.',
                'create_invoice',
                $exception->getCode() === 503 ? 503 : 500,
                $exception
            );
        } catch (InvalidArgumentException $exception) {
            throw new GreenfieldApiException(
                'Invoice input is invalid.',
                'create_invoice',
                400,
                $exception
            );
        } catch (BtcInvoiceManagerException $exception) {
            $status = $exception->getCode() >= 400 && $exception->getCode() < 500
                ? $exception->getCode()
                : 500;
            throw new GreenfieldApiException(
                $status === 500 ? 'Invoice could not be created.' : 'Invoice input is invalid.',
                'create_invoice',
                $status,
                $exception
            );
        } catch (Throwable $exception) {
            throw new GreenfieldApiException(
                'Invoice could not be created.',
                'create_invoice',
                500,
                $exception
            );
        }

        return [
            'id' => $invoice['id'],
            'storeId' => $store['id'],
            'amount' => $invoice['amount'],
            'currency' => 'BTC',
            'type' => 'Standard',
            'checkoutLink' => $this->checkoutBaseUrl . '/checkout/pay.php?id=' . rawurlencode($invoice['id']),
            'createdTime' => $invoice['created_at'],
            'expirationTime' => $invoice['expires_at'],
            'monitoringTime' => $invoice['expires_at'],
            'status' => $invoice['status'],
            'additionalStatus' => 'None',
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createWebhook(string $storeId, array $input, string $apiKey): array
    {
        $store = $this->authenticateStore($storeId, $apiKey);
        $url = $this->validateWebhookUrl($input['url'] ?? null);
        $webhook = $this->repository->findOrCreateWebhook($store['id'], $url);

        return [
            'id' => $webhook['id'],
            'enabled' => true,
            'automaticRedelivery' => true,
            'url' => $webhook['url'],
            'secret' => $webhook['secret'],
        ];
    }

    /**
     * @return array{id: string, name: string, api_key: string, wallet_path: string}
     */
    private function authenticateStore(string $storeId, string $apiKey): array
    {
        $storeId = $this->validateIdentifier($storeId, 'Store ID');
        $apiKey = trim($apiKey);
        $store = $this->repository->findStore($storeId);

        // Compare fixed-length digests so missing stores and different key
        // lengths do not create an avoidable timing distinction.
        $storedKey = $store['api_key'] ?? '';
        $storeAuthorized = $apiKey !== '' && hash_equals(
            hash('sha256', $storedKey, true),
            hash('sha256', $apiKey, true)
        );
        $adminAuthorized = $apiKey !== ''
            && $this->adminApiKey !== ''
            && hash_equals(
                hash('sha256', $this->adminApiKey, true),
                hash('sha256', $apiKey, true)
            );

        if ($store === null || (!$storeAuthorized && !$adminAuthorized)) {
            throw new GreenfieldApiException('Invalid API key or store.', 'authenticate', 401);
        }

        return $store;
    }

    private function validateIdentifier(string $identifier, string $field): string
    {
        $identifier = trim($identifier);
        if (!preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $identifier)) {
            throw new GreenfieldApiException("{$field} is invalid.", 'validate_request', 400);
        }

        return $identifier;
    }

    private function expirationMinutes(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_EXPIRATION_MINUTES;
        }
        if (is_int($value)) {
            $minutes = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/D', trim($value))) {
            $minutes = filter_var(trim($value), FILTER_VALIDATE_INT);
        } else {
            $minutes = false;
        }

        if ($minutes === false || $minutes < 1 || $minutes > self::MAX_EXPIRATION_MINUTES) {
            throw new GreenfieldApiException('Invoice expiration is invalid.', 'create_invoice', 400);
        }

        return $minutes;
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

        $url = trim($value);
        if ($url === '' || strlen($url) > 2_048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new GreenfieldApiException('Webhook URL is invalid.', 'create_webhook', 400);
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !is_string($parts['scheme'] ?? null)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new GreenfieldApiException('Webhook URL is invalid.', 'create_webhook', 400);
        }

        $scheme = strtolower($parts['scheme']);
        $host = trim(strtolower(rtrim($parts['host'], '.')), '[]');
        $loopbackHosts = ['localhost', '127.0.0.1', '::1'];
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, $loopbackHosts, true))) {
            throw new GreenfieldApiException(
                'Webhook URL must use HTTPS (HTTP is allowed only for localhost).',
                'create_webhook',
                400
            );
        }

        if (
            filter_var($host, FILTER_VALIDATE_IP) !== false
            && !in_array($host, $loopbackHosts, true)
            && filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false
        ) {
            throw new GreenfieldApiException('Private-network webhook URLs are not allowed.', 'create_webhook', 400);
        }

        return $url;
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

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private function invoiceResponse(array $invoice, string $storeId): array
    {
        return [
            'id' => $invoice['id'],
            'storeId' => $storeId,
            'amount' => $invoice['amount'],
            'currency' => 'BTC',
            'type' => 'Standard',
            'status' => $invoice['status'],
            'additionalStatus' => 'None',
            'createdTime' => $invoice['created_at'],
            'expirationTime' => $invoice['expires_at'],
            'metadata' => $invoice['metadata'],
        ];
    }
}
