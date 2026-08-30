<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Application service for creating and checking stateless invoices.
 */
class BtcStatelessService
{
    private const DEFAULT_EXPIRATION_MINUTES = 15;
    private const MIN_EXPIRATION_MINUTES = 10;
    private const MAX_EXPIRATION_MINUTES = 43_200;
    private const MAX_ORDER_ID_BYTES = 255;

    /** @var array<string, mixed> */
    private array $config;
    private ElectrumWallet $wallet;
    private BtcInvoiceManager $invoiceManager;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, ElectrumWallet $wallet, BtcInvoiceManager $invoiceManager)
    {
        $this->config = $config;
        $this->wallet = $wallet;
        $this->invoiceManager = $invoiceManager;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createInvoiceFromApi(array $input, string $apiKeyProvided): array
    {
        $apiKeyProvided = trim($apiKeyProvided);
        $clients = $this->config['api_clients'] ?? null;
        if ($apiKeyProvided === '' || !is_array($clients) || !array_key_exists($apiKeyProvided, $clients)) {
            throw new BtcStatelessServiceException('Invalid API key or unknown API client.', 'authenticate', 401);
        }

        $walletName = $clients[$apiKeyProvided];
        if (!is_string($walletName)) {
            throw new BtcStatelessServiceException('Configured API client wallet is invalid.', 'authenticate');
        }

        return $this->processInvoiceCreation($input, $walletName);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createInvoiceAsAdmin(array $input, string $walletName): array
    {
        return $this->processInvoiceCreation($input, $walletName);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentPageData(string $token): array
    {
        $invoiceData = $this->invoiceManager->decodeStatelessToken($token);
        $dashboard = new BtcDashboard($this->wallet, $this->walletDirectory());

        // Fiat is display-only. BTC invoice calculations remain in satoshis.
        $fiatRate = $dashboard->getFiatPrice('CZK');
        $fiatAmount = $fiatRate > 0 ? round((float) $invoiceData['v'] * $fiatRate, 2) : 0.0;

        return [
            'invoice' => $invoiceData,
            'fiat_amount' => $fiatAmount,
            'seconds_remaining' => max(0, (int) $invoiceData['e'] - time()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(string $token): array
    {
        $invoiceData = $this->invoiceManager->decodeStatelessToken($token);
        $customData = $invoiceData['p'] ?? [];
        if (!is_array($customData)) {
            throw new BtcStatelessServiceException('Invoice custom data is invalid.', 'check_status', 400);
        }

        $walletName = $customData['wallet'] ?? $this->defaultWalletName();
        if (!is_string($walletName)) {
            throw new BtcStatelessServiceException('Invoice wallet is invalid.', 'check_status', 400);
        }

        [, $walletPath] = $this->resolveWallet($walletName);
        $this->wallet->loadWallet($walletPath);

        return $this->invoiceManager->checkStatelessPaymentStatus($token);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function processInvoiceCreation(array $input, string $walletName): array
    {
        [$safeWalletName, $walletPath] = $this->resolveWallet($walletName);
        $amount = $this->normalizeAmount($input['amount'] ?? null);
        $description = $this->requireString($input['description'] ?? null, 'Description', 255);
        $orderId = $this->optionalString($input['order_id'] ?? '', 'Order ID', self::MAX_ORDER_ID_BYTES);
        $expirationMinutes = $this->normalizeExpiration($input['expiration_minutes'] ?? null);

        // All validation happens before the wallet is mutated.
        $this->wallet->loadWallet($walletPath);
        $result = $this->invoiceManager->createStatelessInvoice(
            $amount,
            $description,
            ['order_id' => $orderId, 'wallet' => $safeWalletName],
            $expirationMinutes
        );

        if (!isset($result['token']) || !is_string($result['token']) || $result['token'] === '') {
            throw new BtcStatelessServiceException('Invoice manager returned an invalid token.', 'create_invoice');
        }

        return [
            'token' => $result['token'],
            'amount' => $amount,
            'description' => $description,
            'order_id' => $orderId,
            'wallet' => $safeWalletName,
            'expires_in_minutes' => $expirationMinutes,
        ];
    }

    private function normalizeAmount(mixed $amount): string
    {
        if (is_string($amount)) {
            $amount = str_replace(',', '.', trim($amount));
        }
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            throw new BtcStatelessServiceException('Invoice amount must be a BTC decimal.', 'create_invoice', 400);
        }

        try {
            $bitcoinAmount = BitcoinAmount::fromBtc($amount);
        } catch (InvalidArgumentException $exception) {
            throw new BtcStatelessServiceException(
                'Invoice amount must be a positive BTC value with at most 8 decimal places.',
                'create_invoice',
                400,
                $exception
            );
        }

        if (!$bitcoinAmount->isPositive()) {
            throw new BtcStatelessServiceException('Invoice amount must be greater than zero.', 'create_invoice', 400);
        }

        return $bitcoinAmount->toBtcString();
    }

    private function normalizeExpiration(mixed $expiration): int
    {
        if ($expiration === null || $expiration === '') {
            return self::DEFAULT_EXPIRATION_MINUTES;
        }
        if (is_int($expiration)) {
            $minutes = $expiration;
        } elseif (is_string($expiration) && preg_match('/\A[0-9]+\z/D', trim($expiration))) {
            $minutes = filter_var(trim($expiration), FILTER_VALIDATE_INT);
        } else {
            $minutes = false;
        }

        if ($minutes === false) {
            throw new BtcStatelessServiceException('Invoice expiration must be a whole number of minutes.', 'create_invoice', 400);
        }

        return max(self::MIN_EXPIRATION_MINUTES, min(self::MAX_EXPIRATION_MINUTES, $minutes));
    }

    /** @return array{0: string, 1: string} */
    private function resolveWallet(string $walletName): array
    {
        $safeWalletName = trim($walletName);
        if (
            $safeWalletName === ''
            || strlen($safeWalletName) > 255
            || $safeWalletName === '.'
            || $safeWalletName === '..'
            || str_contains($safeWalletName, "\0")
            || str_contains($safeWalletName, '/')
            || str_contains($safeWalletName, '\\')
        ) {
            throw new BtcStatelessServiceException('Wallet name is invalid.', 'resolve_wallet', 400);
        }

        $walletDirectory = realpath($this->walletDirectory());
        if ($walletDirectory === false || !is_dir($walletDirectory)) {
            throw new BtcStatelessServiceException('Configured wallet directory does not exist.', 'resolve_wallet');
        }

        $walletPath = realpath($walletDirectory . DIRECTORY_SEPARATOR . $safeWalletName);
        if (
            $walletPath === false
            || !is_file($walletPath)
            || dirname($walletPath) !== $walletDirectory
        ) {
            throw new BtcStatelessServiceException('Selected wallet does not exist.', 'resolve_wallet', 404);
        }

        return [$safeWalletName, $walletPath];
    }

    private function defaultWalletName(): string
    {
        $walletName = basename($this->configuredWalletPath());
        if ($walletName === '' || $walletName === '.' || $walletName === '..') {
            throw new BtcStatelessServiceException('Configured default wallet is invalid.', 'resolve_wallet');
        }

        return $walletName;
    }

    private function walletDirectory(): string
    {
        return dirname($this->configuredWalletPath());
    }

    private function configuredWalletPath(): string
    {
        $walletPath = $this->config['wallet_path'] ?? null;
        if (!is_string($walletPath) || trim($walletPath) === '' || str_contains($walletPath, "\0")) {
            throw new BtcStatelessServiceException('Configured wallet path is invalid.', 'resolve_wallet');
        }

        return $walletPath;
    }

    private function requireString(mixed $value, string $field, int $maxBytes): string
    {
        $value = $this->optionalString($value, $field, $maxBytes);
        if ($value === '') {
            throw new BtcStatelessServiceException("{$field} is required.", 'create_invoice', 400);
        }

        return $value;
    }

    private function optionalString(mixed $value, string $field, int $maxBytes): string
    {
        if (!is_string($value)) {
            throw new BtcStatelessServiceException("{$field} must be a string.", 'create_invoice', 400);
        }

        $value = trim($value);
        if (str_contains($value, "\0") || strlen($value) > $maxBytes) {
            throw new BtcStatelessServiceException("{$field} is invalid.", 'create_invoice', 400);
        }

        return $value;
    }
}
