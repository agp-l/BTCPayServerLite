<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;

final class ElectrumCliWalletProvisioner implements StoreWalletProvisioner
{
    private string $executable;
    private string $electrumDataDirectory;
    private string $walletDirectory;
    private int $timeoutSeconds;

    public function __construct(
        string $executable,
        string $electrumDataDirectory,
        string $walletDirectory,
        int $timeoutSeconds = 20
    ) {
        $executable = trim($executable);
        $electrumDataDirectory = rtrim(trim($electrumDataDirectory), DIRECTORY_SEPARATOR);
        $walletDirectory = rtrim(trim($walletDirectory), DIRECTORY_SEPARATOR);
        foreach ([$executable, $electrumDataDirectory, $walletDirectory] as $path) {
            if ($path === '' || str_contains($path, "\0")) {
                throw new RuntimeException('Wallet provisioning configuration contains an invalid path.');
            }
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new RuntimeException('Wallet provisioning timeout is outside the allowed range.');
        }

        $this->executable = $executable;
        $this->electrumDataDirectory = $electrumDataDirectory;
        $this->walletDirectory = $walletDirectory;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function provision(string $storeId): ProvisionedWallet
    {
        XpubRuntime::assertAvailable();
        if (!preg_match('/\Astore_[a-f0-9]{32}\z/D', $storeId)) {
            throw new RuntimeException('Store ID is invalid for wallet provisioning.');
        }
        if (!function_exists('proc_open')) {
            throw new StoreCreationException('process_disabled', 'PHP webového serveru má zakázané proc_open.');
        }

        $resolvedExecutable = realpath($this->executable);
        $resolvedDataDirectory = realpath($this->electrumDataDirectory);
        $resolvedWalletDirectory = realpath($this->walletDirectory);
        if ($resolvedExecutable === false || !is_file($resolvedExecutable) || !is_executable($resolvedExecutable)) {
            throw new StoreCreationException('electrum_executable', 'Electrum CLI není dostupné nebo spustitelné pro uživatele PHP. Ověřte electrum_cli_path.');
        }
        if ($resolvedDataDirectory === false || !is_dir($resolvedDataDirectory)) {
            throw new StoreCreationException('electrum_data_directory', 'Electrum datový adresář není dostupný pro uživatele PHP. Ověřte electrum_data_dir a oprávnění.');
        }
        if (
            $resolvedWalletDirectory === false
            || !is_dir($resolvedWalletDirectory)
            || !is_writable($resolvedWalletDirectory)
        ) {
            throw new StoreCreationException('wallet_directory', 'Adresář peněženek není dostupný nebo zapisovatelný pro uživatele PHP. Ověřte store_wallets_dir a oprávnění.');
        }

        $walletPath = $resolvedWalletDirectory . DIRECTORY_SEPARATOR . $storeId . '_wallet';
        if (file_exists($walletPath) || is_link($walletPath)) {
            throw new RuntimeException('A wallet already exists for this store.');
        }

        $context = new ElectrumOfflineContext($resolvedDataDirectory);
        try {
            return $this->provisionOffline($resolvedExecutable, $context->directory(), $resolvedWalletDirectory, $walletPath);
        } finally {
            $context->close();
        }
    }

    private function provisionOffline(string $resolvedExecutable, string $resolvedDataDirectory, string $resolvedWalletDirectory, string $walletPath): ProvisionedWallet
    {
        $command = [
            $resolvedExecutable,
            '-D',
            $resolvedDataDirectory,
            'create',
            '--offline',
            '-w',
            $walletPath,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new StoreCreationException('electrum_start', 'Proces Electrum CLI se nepodařilo spustit.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + $this->timeoutSeconds;
        $exitCode = null;

        try {
            while (true) {
                // Electrum may print seed material. Drain it without retaining it.
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);

                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new RuntimeException('Electrum wallet process status is unavailable.');
                }
                if (!$status['running']) {
                    $exitCode = is_int($status['exitcode']) ? $status['exitcode'] : null;
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    throw new StoreCreationException('electrum_create_timeout', 'Vytváření peněženky překročilo časový limit.');
                }

                usleep(50_000);
            }
        } finally {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closeCode = proc_close($process);
            if ($exitCode === null && $closeCode >= 0) {
                $exitCode = $closeCode;
            }
        }

        if ($exitCode !== 0) {
            throw new StoreCreationException('electrum_create_failed', 'Electrum CLI skončilo chybou. Ověřte jeho Python prostředí a přístup uživatele PHP k datovému adresáři.');
        }

        $resolvedWallet = realpath($walletPath);
        if (
            $resolvedWallet === false
            || !is_file($resolvedWallet)
            || is_link($walletPath)
            || dirname($resolvedWallet) !== $resolvedWalletDirectory
        ) {
            throw new StoreCreationException('wallet_file_missing', 'Electrum nevytvořilo očekávaný wallet soubor.');
        }
        if (!chmod($resolvedWallet, 0660)) {
            throw new StoreCreationException('wallet_permissions', 'Nepodařilo se nastavit oprávnění nového wallet souboru.');
        }

        try {
            $publicKey = $this->readPublicCommand($resolvedExecutable, $resolvedDataDirectory, $resolvedWallet, 'getmpk');
            $addresses = $this->readPublicCommand($resolvedExecutable, $resolvedDataDirectory, $resolvedWallet, 'listaddresses', ['--receiving']);
            if (!is_string($publicKey) || !is_array($addresses) || array_values($addresses) !== $addresses) {
                throw new ElectrumWalletException('Invalid public wallet provisioning response.', 'getmpk');
            }
            $receive = new ProvisionedWallet($resolvedWallet, $publicKey, count($addresses));
            WalletXpubReader::verifyAddresses($receive, $addresses);
            return $receive;
        } catch (\Throwable $exception) {
            // The fresh wallet has never been loaded by this provisioner.
            $this->discard($resolvedWallet);
            throw $exception;
        }
    }

    /** Runs only explicitly public, offline commands. Creation output is never retained. */
    private function readPublicCommand(string $executable, string $dataDir, string $walletPath, string $method, array $options = []): mixed
    {
        $process = proc_open([$executable, '-D', $dataDir, $method, '--offline', '-w', $walletPath, ...$options],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Public wallet inspection could not start.'); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $code = -1; $deadline = microtime(true) + $this->timeoutSeconds;
        try {
            do {
                $output .= stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) { $code = $status['exitcode']; break; }
                if (microtime(true) >= $deadline || strlen($output) > 1048576) {
                    proc_terminate($process);
                    throw new RuntimeException('Public wallet inspection exceeded its limit.');
                }
                usleep(20000);
            } while (true);
            $output .= stream_get_contents($pipes[1]);
        } finally {
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        if ($code !== 0) { throw new ElectrumWalletException('Offline public wallet inspection failed.', $method); }
        // Electrum CLI prints scalar strings directly and structured values as JSON.
        $decoded = json_decode(trim($output), true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : trim($output);
    }

    public function discard(string $walletPath): void
    {
        if ($walletPath === '' || str_contains($walletPath, "\0")) {
            throw new RuntimeException('Discarded wallet path is invalid.');
        }

        $resolvedWalletDirectory = realpath($this->walletDirectory);
        if ($resolvedWalletDirectory === false || !is_dir($resolvedWalletDirectory)) {
            throw new RuntimeException('Configured wallet directory is unavailable.');
        }
        if (is_link($walletPath)) {
            throw new RuntimeException('Refusing to discard a wallet symlink.');
        }

        $resolvedWallet = realpath($walletPath);
        if ($resolvedWallet === false) {
            if (file_exists($walletPath)) {
                throw new RuntimeException('Discarded wallet path cannot be resolved.');
            }
            return;
        }

        if (
            !is_file($resolvedWallet)
            || dirname($resolvedWallet) !== $resolvedWalletDirectory
            || !preg_match('/\Astore_[a-f0-9]{32}_wallet\z/D', basename($resolvedWallet))
        ) {
            throw new RuntimeException('Refusing to discard a wallet outside the managed directory.');
        }
        if (!unlink($resolvedWallet)) {
            throw new RuntimeException('Unused wallet could not be discarded.');
        }
    }
}
