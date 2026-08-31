<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;

final class AdminOperationsFactory
{
    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): AdminOperationsService
    {
        $database = new Database(
            self::requiredString($config, 'db_host'),
            self::requiredString($config, 'db_name'),
            self::requiredString($config, 'db_user'),
            self::string($config, 'db_pass'),
            self::positiveInt($config['db_port'] ?? 3306, 'db_port')
        );
        $walletPath = self::requiredString($config, 'wallet_path');

        return new AdminOperationsService(
            new PdoAdminOperationsRepository($database),
            new ElectrumCliWalletProvisioner(
                self::stringOrDefault($config, 'electrum_cli_path', '/opt/electrum/run_electrum'),
                self::stringOrDefault($config, 'electrum_data_dir', '/opt/electrum_config'),
                self::stringOrDefault($config, 'store_wallets_dir', dirname($walletPath))
            ),
            new WebhookEndpointPolicy(
                null,
                ($config['allow_local_webhooks'] ?? false) === true
            )
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
    private static function stringOrDefault(array $config, string $key, string $default): string
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        return self::requiredString($config, $key);
    }

    private static function positiveInt(mixed $value, string $key): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            throw new RuntimeException('Invalid configuration value: ' . $key);
        }
        if ($number < 1 || $number > 65535) {
            throw new RuntimeException('Configuration value is outside the allowed range: ' . $key);
        }

        return $number;
    }
}
