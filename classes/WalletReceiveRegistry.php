<?php

declare(strict_types=1);
namespace BtcPayLite;
use PDO;

/** Durable ownership of an application's receive branch, independent of store lifetime. */
final class WalletReceiveRegistry
{
    public function __construct(private PDO $pdo) {}

    public function bind(string $path, string $xpub, string $script, int $floor): array
    {
        $path = WalletLockManager::canonicalWalletPath($path);
        $script = XpubAddressGenerator::requireScriptType($script);
        new XpubAddressGenerator($xpub, new FileAddressIndexStore(), $script);
        $key = XpubDerivationIdentity::describe($xpub)['id'];
        $hash = hash('sha256', $path);
        $stmt = $this->pdo->prepare('INSERT INTO wallet_receive_ranges (wallet_hash,wallet_path,key_hash,xpub,script_type,initial_next_index)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE wallet_hash=VALUES(wallet_hash)');
        $stmt->execute([$hash,$path,$key,$xpub,$script,$floor]);
        $row = $this->registered($path);
        if ($row === null || $row['wallet_path'] !== $path || $row['key_hash'] !== $key || $row['script_type'] !== $script) {
            throw new AddressGenerationException('Wallet receive branch conflicts with its persistent binding.', 'xpub', 409);
        }
        return $row;
    }

    public function registered(string $path): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wallet_receive_ranges WHERE wallet_hash=?');
        $stmt->execute([hash('sha256', WalletLockManager::canonicalWalletPath($path))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** A specific authorized wallet, never an implicit default store. No RPC. */
    public function resolve(string $path): ?array
    {
        $path = WalletLockManager::canonicalWalletPath($path);
        $bound = $this->registered($path);
        if ($bound !== null) { XpubAddressGenerator::requireScriptType($bound['script_type']); return $bound; }
        $stmt = $this->pdo->prepare("SELECT xpub,xpub_script_type,xpub_last_index FROM stores WHERE wallet_path=? AND address_source='xpub'");
        $stmt->execute([$path]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) { return null; }
        $first = $rows[0]; $key = XpubDerivationIdentity::describe((string) $first['xpub'])['id']; $floor = 0;
        foreach ($rows as $row) {
            if (XpubDerivationIdentity::describe((string) $row['xpub'])['id'] !== $key || $row['xpub_script_type'] !== $first['xpub_script_type']) {
                throw new AddressGenerationException('Stores disagree about the wallet receive branch.', 'xpub', 409);
            }
            $floor = max($floor, (int) $row['xpub_last_index']);
        }
        return $this->bind($path, $first['xpub'], $first['xpub_script_type'], $floor);
    }
}
