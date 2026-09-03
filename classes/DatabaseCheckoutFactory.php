<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;

/**
 * Composes the database checkout from validated configuration.
 */
final class DatabaseCheckoutFactory
{
    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): DatabaseCheckoutService
    {
        $database = new Database(
            self::requiredString($config, 'db_host'),
            self::requiredString($config, 'db_name'),
            self::requiredString($config, 'db_user'),
            self::string($config, 'db_pass'),
            self::port($config['db_port'] ?? 3306, 'db_port')
        );

        $rpcHost = self::requiredString($config, 'rpc_host');
        $rpcPort = self::port($config['rpc_port'] ?? null, 'rpc_port');
        $rpcUser = self::optionalString($config, 'rpc_user');
        $rpcPass = self::optionalString($config, 'rpc_pass');
        if (($rpcUser === null) !== ($rpcPass === null)) {
            throw new RuntimeException('Electrum RPC credentials must be configured as a pair.');
        }

        $secretKey = self::requiredString($config, 'secret_key');
        $rpcScheme = self::optionalString($config, 'rpc_scheme') ?? 'http';
        if (!in_array(strtolower($rpcScheme), ['http', 'https'], true)) {
            throw new RuntimeException('Invalid configuration value: rpc_scheme');
        }

        return new DatabaseCheckoutService(
            new PdoCheckoutRepository($database),
            static function (string $invoiceId, string $walletPath) use (
                $database,
                $rpcHost,
                $rpcPort,
                $rpcUser,
                $rpcPass,
                $rpcScheme,
                $secretKey
            ): array {
                $rpc = new ElectrumRPC(
                    $rpcHost,
                    $rpcPort,
                    $rpcUser,
                    $rpcPass,
                    30,
                    5,
                    strtolower($rpcScheme)
                );
                $blockchainProvider = new ElectrumBlockchainProvider($rpc);
                $wallet = new ElectrumWallet($rpc);

                return (new BtcInvoiceManager(
                    $wallet,
                    $secretKey,
                    $database,
                    null,
                    null,
                    $blockchainProvider
                ))->checkDatabasePaymentStatus($invoiceId);
            }
        );
    }

    /** @param array<string,mixed> $config */
    private static function requiredString(array $config, string $key): string
    {
        $value = self::string($config, $key);
        if ($value === '') {
            throw new RuntimeException('Missing configuration value: ' . $key);
        }

        return $value;
    }

    /** @param array<string,mixed> $config */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid configuration value: ' . $key);
        }

        return trim($value);
    }

    /** @param array<string,mixed> $config */
    private static function optionalString(array $config, string $key): ?string
    {
        if (!array_key_exists($key, $config) || $config[$key] === null) {
            return null;
        }

        $value = self::string($config, $key);
        return $value === '' ? null : $value;
    }

    private static function port(mixed $value, string $key): int
    {
        if (is_int($value)) {
            $port = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $port = (int) $value;
        } else {
            throw new RuntimeException('Invalid configuration value: ' . $key);
        }

        if ($port < 1 || $port > 65_535) {
            throw new RuntimeException('Configuration value is outside the allowed range: ' . $key);
        }

        return $port;
    }
}
