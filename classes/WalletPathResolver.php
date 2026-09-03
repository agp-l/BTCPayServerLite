<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Resolves logical wallet names to strictly validated canonical filesystem paths.
 *
 * Prevents directory traversal attacks (e.g. ../../) and ensures all target
 * files reside within the authorized wallet directory.
 */
final class WalletPathResolver
{
    private string $walletDirectory;

    public function __construct(string $walletDirectory)
    {
        $walletDirectory = trim($walletDirectory);
        if ($walletDirectory === '') {
            throw new InvalidArgumentException('Wallet directory must not be empty.');
        }

        $realBase = realpath($walletDirectory);
        if ($realBase === false || !is_dir($realBase)) {
            // If directory doesn't exist yet, normalize path format
            $this->walletDirectory = rtrim($walletDirectory, DIRECTORY_SEPARATOR);
        } else {
            $this->walletDirectory = $realBase;
        }
    }

    public function resolve(string $walletName): string
    {
        $walletName = trim($walletName);
        if ($walletName === '' || str_contains($walletName, "\0")) {
            throw new InvalidArgumentException('Invalid wallet name or identifier.');
        }

        // If it's already an absolute path to an existing file without path traversal
        if ((str_starts_with($walletName, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $walletName)) && !str_contains($walletName, '..')) {
            $realPath = realpath($walletName);
            if ($realPath !== false && is_file($realPath)) {
                return $realPath;
            }
        }

        // Prevent traversal characters for relative names
        if (str_contains($walletName, '..') || str_contains($walletName, '/') || str_contains($walletName, '\\')) {
            throw new InvalidArgumentException('Path traversal is strictly prohibited in wallet names.');
        }

        $safeName = basename($walletName);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            throw new InvalidArgumentException('Invalid wallet filename.');
        }

        $targetPath = $this->walletDirectory . DIRECTORY_SEPARATOR . $safeName;

        // If file exists, verify realpath is within walletDirectory
        $realTarget = realpath($targetPath);
        if ($realTarget !== false) {
            $realBase = realpath($this->walletDirectory);
            if ($realBase !== false && !str_starts_with($realTarget, $realBase . DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException('Resolved wallet path is outside authorized directory.');
            }
            return $realTarget;
        }

        return $targetPath;
    }

    public function getWalletDirectory(): string
    {
        return $this->walletDirectory;
    }
}
