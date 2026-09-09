<?php

declare(strict_types=1);
namespace BtcPayLite;

/** Deployment checks/permission plan; no RPC, SQL writes or privileged execution. */
final class DeploymentEnvironment
{
    public static function paths(array $config, string $root): array
    {
        return [
            'wallets' => $config['store_wallets_dir'] ?? $config['wallet_directory'] ?? dirname($config['wallet_path'] ?? '/opt/btcpay_wallets/wallet_1'),
            'electrum_data' => $config['electrum_data_dir'] ?? $config['electrum_data_directory'] ?? '/opt/electrum_config',
            'locks' => getenv('BTCPAY_WALLET_LOCK_DIR') ?: $root . '/var/locks',
            'blockchain_cache' => getenv('BTCPAY_BLOCKCHAIN_CACHE_DIR') ?: $root . '/var/blockchain',
        ];
    }

    public static function checks(array $config, string $root): array
    {
        $paths = self::paths($config, $root);
        $checks = [];
        foreach (['locks', 'blockchain_cache'] as $key) {
            $path = $paths[$key];
            $ok = is_dir($path) && is_readable($path) && is_writable($path);
            $bad = 0;
            if ($ok) {
                foreach (glob($path . '/*') ?: [] as $file) {
                    if (is_file($file) && (!is_readable($file) || !is_writable($file))) { ++$bad; }
                }
            }
            $checks[] = ['name' => $key, 'ok' => $ok && $bad === 0,
                'detail' => $path . ($bad ? ' — nepřístupných souborů: ' . $bad : '') . '. Sdílený přístup musí mít web i CLI worker.'];
        }
        $source = $paths['electrum_data'] . '/config';
        $checks[] = ['name' => 'Electrum config read', 'ok' => !file_exists($source) || is_readable($source),
            'detail' => $source . ' — existující konfigurace musí být čitelná.'];
        $checks[] = ['name' => 'PHP temporary directory', 'ok' => is_writable(sys_get_temp_dir()), 'detail' => sys_get_temp_dir()];
        foreach (['pdo_mysql', 'curl'] as $extension) {
            $checks[] = ['name' => $extension, 'ok' => extension_loaded($extension), 'detail' => 'Kontrola právě běžícího PHP.'];
        }
        return $checks;
    }

    /** Shell script is returned for review; PHP never executes sudo or reads private keys. */
    public static function permissionsScript(array $config, string $root, string $webUser, string $workerUser, string $electrumUser): string
    {
        $users = array_values(array_unique([$webUser, $workerUser, $electrumUser]));
        foreach ($users as $user) {
            if (!preg_match('/\A[a-z_][a-z0-9_-]*\$?\z/iD', $user)) {
                throw new \InvalidArgumentException('Zadejte platná systémová jména web, worker a electrum uživatele.');
            }
        }
        $paths = self::paths($config, $root);
        foreach (array_merge($paths, [$root]) as $path) { self::path($path); }
        $webWorker = array_values(array_unique([$webUser, $workerUser]));
        $acl = static fn(array $accounts, string $mode): string => implode(',', array_map(static fn($u) => 'u:' . $u . ':' . $mode, $accounts));
        $q = static fn(string $s): string => "'" . str_replace("'", "'\\''", $s) . "'";
        $lines = ['#!/bin/sh', 'set -eu', '# Generated from local config; contains paths/accounts, no passwords.',
            '# Run with sudo sh after review. Existing ownership and lockfiles are preserved.',
            'command -v setfacl >/dev/null || { echo "Install acl first (sudo apt install acl on Debian/Ubuntu)." >&2; exit 1; }'];
        foreach ($users as $user) { $lines[] = 'id -u ' . $q($user) . ' >/dev/null'; }
        foreach (['locks', 'blockchain_cache', 'wallets'] as $key) {
            $path = $q($paths[$key]);
            $accounts = $key === 'wallets' ? $users : $webWorker;
            $lines[] = 'test ! -L ' . $path . ' || { echo "Refusing a symlink directory" >&2; exit 1; }';
            $lines[] = 'mkdir -p -- ' . $path;
            $lines[] = 'setfacl -m ' . $q($acl($accounts, 'rwx')) . ' -- ' . $path;
            $lines[] = 'setfacl -d -m ' . $q($acl($accounts, 'rwx')) . ' -- ' . $path;
            // Dedicated runtime dirs; for wallets touch only application-created files.
            $filter = $key === 'wallets' ? " -name 'store_*_wallet'" : '';
            $lines[] = 'find ' . $path . ' -maxdepth 1 -type f' . $filter . ' -exec setfacl -m ' . $q($acl($accounts, 'rw')) . ' -- {} +';
        }
        // Existing configured default wallet also needs the shared mutation permissions.
        if (isset($config['wallet_path'])) {
            self::path($config['wallet_path']);
            $path = $q($config['wallet_path']);
            $lines[] = 'if test -f ' . $path . ' && test ! -L ' . $path . '; then setfacl -m ' . $q($acl($users, 'rw')) . ' -- ' . $path . '; fi';
        }
        $data = $q($paths['electrum_data']);
        $lines[] = 'test -d ' . $data . ' && test ! -L ' . $data;
        $lines[] = 'setfacl -m ' . $q($acl($webWorker, 'rx')) . ' -- ' . $data;
        foreach ([$paths['electrum_data'] . '/config', $root . '/config.php'] as $file) {
            $path = $q($file);
            $lines[] = 'if test -f ' . $path . ' && test ! -L ' . $path . '; then setfacl -m ' . $q($acl($webWorker, 'r')) . ' -- ' . $path . '; fi';
        }
        $lines[] = 'echo "Permissions applied. Recheck web diagnostics and CLI deployment check."';
        return implode("\n", $lines) . "\n";
    }

    private static function path(mixed $path): void
    {
        if (!is_string($path) || $path === '/' || !str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path)
            || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            throw new \InvalidArgumentException('Cesty pro plán oprávnění musí být absolutní, bez . nebo .. a řídicích znaků.');
        }
    }
}
