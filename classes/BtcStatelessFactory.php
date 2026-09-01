<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Small dependency factory for the standalone stateless-invoice subsystem.
 *
 * It intentionally creates no Database connection and can therefore be used
 * by a minimal integration that only ships the Electrum and stateless classes.
 */
final class BtcStatelessFactory
{
    /** @var array<string, mixed> */
    private array $config;
    private ?ElectrumWallet $wallet = null;
    private ?BtcStatelessInvoiceManager $invoiceManager = null;
    private ?BtcStatelessService $service = null;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function wallet(): ElectrumWallet
    {
        if ($this->wallet === null) {
            $rpc = new ElectrumRPC(
                $this->requiredString('rpc_host'),
                $this->requiredPort('rpc_port'),
                $this->requiredString('rpc_user'),
                $this->requiredString('rpc_pass', true, false)
            );
            $this->wallet = new ElectrumWallet($rpc);
        }

        return $this->wallet;
    }

    public function invoiceManager(): BtcStatelessInvoiceManager
    {
        if ($this->invoiceManager === null) {
            $this->invoiceManager = new BtcStatelessInvoiceManager(
                $this->wallet(),
                $this->requiredString('secret_key')
            );
        }

        return $this->invoiceManager;
    }

    public function service(): BtcStatelessService
    {
        if ($this->service === null) {
            $this->service = new BtcStatelessService(
                $this->config,
                $this->wallet(),
                $this->invoiceManager()
            );
        }

        return $this->service;
    }

    public function defaultWalletName(): string
    {
        $name = basename($this->requiredString('wallet_path'));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new InvalidArgumentException('Configured default wallet is invalid.');
        }

        return $name;
    }

    public function walletDirectory(): string
    {
        return dirname($this->requiredString('wallet_path'));
    }

    private function requiredString(
        string $key,
        bool $allowEmpty = false,
        bool $trim = true
    ): string
    {
        $value = $this->config[$key] ?? null;
        if (!is_string($value) || str_contains($value, "\0")) {
            throw new InvalidArgumentException("Configuration value {$key} is invalid.");
        }

        $value = $trim ? trim($value) : $value;
        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException("Configuration value {$key} is required.");
        }

        return $value;
    }

    private function requiredPort(string $key): int
    {
        $value = $this->config[$key] ?? null;
        if (is_int($value)) {
            $port = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/D', trim($value))) {
            $port = (int) trim($value);
        } else {
            throw new InvalidArgumentException("Configuration value {$key} is invalid.");
        }

        if ($port < 1 || $port > 65_535) {
            throw new InvalidArgumentException("Configuration value {$key} is outside the valid range.");
        }

        return $port;
    }
}
