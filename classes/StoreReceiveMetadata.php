<?php

declare(strict_types=1);
namespace BtcPayLite;
use PDO;
use RuntimeException;

/** Reuses already persisted receive settings when another store shares a wallet. No RPC. */
final class StoreReceiveMetadata
{
    public static function forWallet(PDO $pdo, string $walletPath, ?ProvisionedWallet $receive = null): array
    {
        if ($receive !== null && $receive->walletPath === $walletPath) { return $receive->columns(); }
        $stmt = $pdo->prepare("SELECT address_source, xpub, xpub_script_type, xpub_last_index FROM stores
            WHERE wallet_path = ? ORDER BY (address_source = 'xpub') DESC, id LIMIT 1");
        $stmt->execute([$walletPath]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            if ($receive !== null) { throw new RuntimeException('Wallet assignment changed during provisioning; retry.'); }
            return ['address_source'=>'electrum', 'xpub'=>null, 'xpub_script_type'=>'p2wpkh', 'xpub_last_index'=>0];
        }
        if ($row['address_source'] === 'xpub') {
            // Fail closed; a broken XPUB setting is never an Electrum fallback.
            new XpubAddressGenerator((string) $row['xpub'], new FileAddressIndexStore(), $row['xpub_script_type']);
        }
        return $row;
    }
}
