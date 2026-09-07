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

        return new DatabaseCheckoutService(new PdoCheckoutRepository($database));
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
