<?php

declare(strict_types=1);
namespace BtcPayLite;
use Throwable;

/** Safe cause reporting shared by admin and client store creation. */
final class StoreCreationDiagnostics
{
    public static function message(Throwable $exception): string
    {
        for ($cause=$exception;$cause!==null;$cause=$cause->getPrevious()) {
            if ($cause instanceof StoreCreationException) { return 'Obchod nelze vytvořit ['.$cause->reason.']. '.$cause->getMessage(); }
            if ($cause instanceof \PDOException) {
                $code=(int)($cause->errorInfo[1] ?? 0);
                $hint=match($code) {
                    1054,1146=>'Chybí tabulka nebo sloupec. Administrátor musí zkontrolovat database_upgrade.php.',
                    1044,1045,1142,1143=>'Databázový účet nemá potřebný přístup. Administrátor musí ověřit DB oprávnění.',
                    1452=>'Nelze ověřit vazbu na uživatele nebo peněženku. Administrátor musí zkontrolovat přiřazení účtu.',
                    1062=>'Záznam s touto jedinečnou hodnotou již existuje. Obnovte seznam obchodů.',
                    default=>'Administrátor musí zkontrolovat databázovou konfiguraci a schéma.',
                };
                return 'Obchod nelze vytvořit [database_'.$code.']. '.$hint;
            }
            if ($cause instanceof ElectrumWalletException) {
                return 'Obchod nelze vytvořit [wallet_public_metadata]. Nepodařilo se ověřit veřejný klíč nebo přijímací adresy peněženky. Administrátor musí zkontrolovat Electrum a XPUB konfiguraci.';
            }
        }
        return 'Obchod nelze vytvořit [store_creation_failed]. Administrátor musí zkontrolovat diagnostiku prostředí a serverový log.';
    }

    public static function log(Throwable $exception): void
    {
        // No exception messages, SQL values, stdout/stderr or wallet keys in logs.
        $chain=[];
        for ($cause=$exception;$cause!==null;$cause=$cause->getPrevious()) {
            $entry=['class'=>$cause::class];
            if ($cause instanceof StoreCreationException) { $entry['reason']=$cause->reason; }
            if ($cause instanceof \PDOException) { $entry['driver_code']=(int)($cause->errorInfo[1] ?? 0); }
            $chain[]=$entry;
        }
        error_log('Store creation failed: '.json_encode($chain));
    }

    /** Inspect the CURRENT PHP process, not the CLI process that happened to install dependencies. */
    public static function environment(array $config): array
    {
        $checks=XpubRuntime::requirements();
        $checks[]=['name'=>'proc_open','ok'=>function_exists('proc_open'),'detail'=>'PHP musí umožnit spuštění lokálního Electrum CLI.'];
        $paths=[
            'electrum_cli_path'=>$config['electrum_cli_path'] ?? $config['electrum_cli'] ?? '/opt/electrum/run_electrum',
            'electrum_data_dir'=>$config['electrum_data_dir'] ?? $config['electrum_data_directory'] ?? '/opt/electrum_config',
            'store_wallets_dir'=>$config['store_wallets_dir'] ?? $config['wallet_directory'] ?? dirname($config['wallet_path'] ?? '/opt/btcpay_wallets/wallet_1'),
        ];
        foreach ($paths as $key=>$path) {
            $valid=is_string($path) && $path!=='' && !str_contains($path,"\0");
            $ok=$valid && ($key==='electrum_cli_path' ? is_file($path) && is_executable($path) : is_dir($path) && is_readable($path) && is_writable($path));
            $checks[]=['name'=>$key,'ok'=>$ok,'detail'=>$valid ? $path : 'Neplatná cesta v config.php'];
        }
        $uid=function_exists('posix_geteuid') ? posix_geteuid() : null;
        $account=$uid!==null && function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        return ['php_version'=>PHP_VERSION,'php_sapi'=>PHP_SAPI,'php_ini'=>php_ini_loaded_file() ?: '(žádné)',
            'process_user'=>is_array($account) ? $account['name'] : ($uid ?? 'nezjištěno'),'checks'=>$checks];
    }
}
