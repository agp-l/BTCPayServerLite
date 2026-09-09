<?php

declare(strict_types=1);
namespace BtcPayLite;

use PDOException;
use Throwable;

/** Action-specific diagnostics; never expose exception messages or key material. */
final class WalletAddressError
{
    public static function message(Throwable $exception): string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException) {
                $code = (int) ($cause->errorInfo[1] ?? 0);
                $hint = match ($code) {
                    1054, 1146 => 'Chybí tabulka nebo sloupec pro příjem adres. Zkontrolujte database_upgrade.php.',
                    1044, 1045, 1142, 1143 => 'Databázový účet nemá potřebné oprávnění pro čtení nebo rezervaci indexu.',
                    1267, 1271 => 'Databázové sloupce mají nekompatibilní collation. Zkontrolujte database_upgrade.php.',
                    1205, 1213 => 'Rezervaci indexu blokuje jiná transakce. Zkuste to za chvíli.',
                    default => 'Ověřte databázovou konfiguraci a schéma v database_upgrade.php.',
                };
                return 'Adresu nelze vytvořit [address_database_' . $code . ']. ' . $hint;
            }
            if ($cause instanceof StoreCreationException && in_array($cause->reason, ['xpub_gmp_missing', 'xpub_dependencies_missing'], true)) {
                return 'Adresu nelze vytvořit [' . $cause->reason . ']. Zkontrolujte GMP a Composer knihovny v diagnostice webového PHP.';
            }
            if ($cause instanceof WalletBusyException) {
                return 'Adresu nelze vytvořit [address_wallet_lock]. Ověřte práva PHP k var/locks (nebo BTCPAY_WALLET_LOCK_DIR). Pokud adresář funguje, může peněženku právě měnit jiný proces.';
            }
            if ($cause instanceof ElectrumRPCException) {
                return 'Adresu nelze vytvořit [address_electrum_rpc]. ' . WalletBalanceError::message($cause);
            }
        }
        if ($exception instanceof AddressGenerationException && $exception->getSource() === 'xpub') {
            return 'Adresu nelze vytvořit [address_xpub]. Ověřte XPUB, typ adres a trvalou vazbu peněženky. Indexy ani vazbu nemažte.';
        }
        return 'Adresu nelze vytvořit [address_generation_failed]. Pošlete tento kód a odpovídající záznam serverového logu.';
    }

    public static function log(Throwable $exception): void
    {
        $chain = [];
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $entry = ['class' => $cause::class];
            if ($cause instanceof PDOException) { $entry['driver_code'] = (int) ($cause->errorInfo[1] ?? 0); }
            if ($cause instanceof AddressGenerationException) { $entry['source'] = $cause->getSource(); }
            if ($cause instanceof ElectrumRPCException) {
                $entry['type'] = $cause->getType();
                $entry['method'] = $cause->getRpcMethod();
            }
            $chain[] = $entry;
        }
        error_log('Wallet address generation failed: ' . json_encode($chain));
    }
}
