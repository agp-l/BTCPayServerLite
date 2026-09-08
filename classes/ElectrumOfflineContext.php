<?php

declare(strict_types=1);

namespace BtcPayLite;

/** Private CLI data directory, never shared with a running Electrum daemon. */
final class ElectrumOfflineContext
{
    private string $directory;

    public function __construct(string $daemonDataDirectory)
    {
        // Carry only chain selection. Never copy RPC credentials, plugins,
        // wallet defaults or the daemon lockfile into the offline context.
        $config = [];
        $source = $daemonDataDirectory . '/config';
        if (file_exists($source)) {
            $raw = @file_get_contents($source, false, null, 0, 1048577);
            $decoded = is_string($raw) && strlen($raw) <= 1048576 ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                throw new StoreCreationException('electrum_config_read', 'Konfiguraci Electra nelze přečíst nebo není platná. Ověřte oprávnění souboru electrum_data_dir/config.');
            }
            foreach (['testnet', 'testnet4', 'regtest', 'simnet', 'signet'] as $key) {
                if (array_key_exists($key, $decoded)) {
                    if (!is_bool($decoded[$key])) {
                        throw new StoreCreationException('electrum_chain_config', 'Volba sítě v konfiguraci Electra musí být boolean.');
                    }
                    $config[$key] = $decoded[$key];
                }
            }
            unset($raw, $decoded);
        }

        $this->directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . '/btcpay-electrum-' . bin2hex(random_bytes(16));
        if (!@mkdir($this->directory, 0700)) {
            throw new StoreCreationException('electrum_temporary_directory', 'PHP nemůže vytvořit soukromý dočasný adresář pro Electrum.');
        }
        if (@file_put_contents($this->directory . '/config', json_encode((object) $config, JSON_THROW_ON_ERROR)) === false) {
            $this->close();
            throw new StoreCreationException('electrum_temporary_directory', 'PHP nemůže zapsat dočasnou konfiguraci Electra.');
        }
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function close(): void
    {
        // Electrum may create chain subdirectories/config files. Never follow
        // symlinks, and never clean the daemon directory or the wallet path.
        if (!$this->remove($this->directory)) {
            error_log('Electrum offline temporary directory cleanup failed.');
        }
    }

    private function remove(string $path): bool
    {
        if (is_link($path) || !is_dir($path)) {
            return !file_exists($path) && !is_link($path) || @unlink($path);
        }
        $entries = @scandir($path);
        if ($entries === false) { return false; }
        $ok = true;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $ok = $this->remove($path . '/' . $entry) && $ok;
            }
        }
        return @rmdir($path) && $ok;
    }
}
