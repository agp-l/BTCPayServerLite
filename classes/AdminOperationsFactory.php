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
                self::firstStringOrDefault(
                    $config,
                    ['electrum_cli_path', 'electrum_cli'],
                    '/opt/electrum/run_electrum'
                ),
                self::firstStringOrDefault(
                    $config,
                    ['electrum_data_dir', 'electrum_data_directory'],
                    '/opt/electrum_config'
                ),
                self::firstStringOrDefault(
                    $config,
                    ['store_wallets_dir', 'wallet_directory'],
                    dirname($walletPath)
                )
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
    private static function firstStringOrDefault(array $config, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $config)) {
                return self::requiredString($config, $key);
            }
        }

        return $default;
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
