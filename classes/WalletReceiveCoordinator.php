<?php

declare(strict_types=1);
namespace BtcPayLite;
use Closure;

/** Allocates public receive addresses for an already authorized wallet. */
final class WalletReceiveCoordinator
{
    public function __construct(private Database $database) {}

    public function allocate(string $path): ?GeneratedAddress
    {
        $row = (new WalletReceiveRegistry($this->database->getPdo()))->resolve($path);
        if ($row === null) { return null; } // Explicit legacy wallet: caller owns its mutation path.
        $indexStore = new class(new DbAddressIndexStore($this->database), $row) implements AddressIndexStoreInterface {
            public function __construct(private DbAddressIndexStore $store, private array $row) {}
            public function reserveNextIndex(string $storeId): int
            { return $this->store->reserveForKey($this->row['xpub'], (int) $this->row['initial_next_index']); }
        };
        return (new XpubAddressGenerator($row['xpub'], $indexStore, $row['script_type']))
            ->generateAddress(new AddressGenerationContext('wallet', $path));
    }

    public function assertLegacyGenerationAllowed(string $path): void
    {
        if ((new WalletReceiveRegistry($this->database->getPdo()))->resolve($path) !== null) {
            throw new AddressGenerationException('This wallet uses a shared XPUB receive sequence. Repair this Electrum store before issuing addresses.', 'electrum', 409);
        }
    }

    /** Lazy on purpose: status/checkout/ordinary dashboard reads never open this DB. */
    public static function allocatorFromConfig(array $config): ?Closure
    {
        if (!array_key_exists('db_host', $config) && !array_key_exists('db_name', $config)) { return null; }
        $coordinator = null;
        return static function (string $path) use ($config, &$coordinator): ?GeneratedAddress {
            $coordinator ??= new self(new Database((string) ($config['db_host'] ?? ''), (string) ($config['db_name'] ?? ''),
                (string) ($config['db_user'] ?? ''), (string) ($config['db_pass'] ?? ''), (int) ($config['db_port'] ?? 3306)));
            return $coordinator->allocate($path);
        };
    }
}
