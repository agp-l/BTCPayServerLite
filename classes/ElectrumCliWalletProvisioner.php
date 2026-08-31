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

    public function provision(string $storeId): string
    {
        if (!preg_match('/\Astore_[a-f0-9]{32}\z/D', $storeId)) {
            throw new RuntimeException('Store ID is invalid for wallet provisioning.');
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Process execution is disabled on this server.');
        }

        $resolvedExecutable = realpath($this->executable);
        $resolvedDataDirectory = realpath($this->electrumDataDirectory);
        $resolvedWalletDirectory = realpath($this->walletDirectory);
        if ($resolvedExecutable === false || !is_file($resolvedExecutable) || !is_executable($resolvedExecutable)) {
            throw new RuntimeException('Configured Electrum executable is unavailable.');
        }
        if ($resolvedDataDirectory === false || !is_dir($resolvedDataDirectory)) {
            throw new RuntimeException('Configured Electrum data directory is unavailable.');
        }
        if (
            $resolvedWalletDirectory === false
            || !is_dir($resolvedWalletDirectory)
            || !is_writable($resolvedWalletDirectory)
        ) {
            throw new RuntimeException('Configured wallet directory is unavailable or not writable.');
        }

        $walletPath = $resolvedWalletDirectory . DIRECTORY_SEPARATOR . $storeId . '_wallet';
        if (file_exists($walletPath) || is_link($walletPath)) {
            throw new RuntimeException('A wallet already exists for this store.');
        }

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
            throw new RuntimeException('Electrum wallet process could not be started.');
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
                    throw new RuntimeException('Electrum wallet creation timed out.');
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
            throw new RuntimeException('Electrum wallet creation failed.');
        }

        $resolvedWallet = realpath($walletPath);
        if (
            $resolvedWallet === false
            || !is_file($resolvedWallet)
            || is_link($walletPath)
            || dirname($resolvedWallet) !== $resolvedWalletDirectory
        ) {
            throw new RuntimeException('Electrum did not create the expected wallet file.');
        }
        if (!chmod($resolvedWallet, 0660)) {
            throw new RuntimeException('Electrum wallet permissions could not be restricted.');
        }

        return $resolvedWallet;
    }
}
