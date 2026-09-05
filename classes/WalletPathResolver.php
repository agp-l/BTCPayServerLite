<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/**
 * Resolves and validates wallet paths, guaranteeing they strictly reside within
 * the authorized wallets directory. Prevents directory traversal and unauthorized
 * file access.
 */
class WalletPathResolver
{
    private string $canonicalBaseDir;

    public function __construct(string $baseWalletsDirectory)
    {
        $base = trim($baseWalletsDirectory);
        if ($base === '' || str_contains($base, "\0")) {
            throw new InvalidArgumentException('Invalid base wallets directory.');
        }

        $realBase = realpath($base);
        if ($realBase === false || !is_dir($realBase)) {
            // If base directory does not exist on disk yet, normalize trailing slash
            $this->canonicalBaseDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR);
        } else {
            $this->canonicalBaseDir = $realBase;
        }
    }

    public function getBaseDirectory(): string
    {
        return $this->canonicalBaseDir;
    }

    /**
     * Resolves a wallet filename, relative path, or absolute path into a safe canonical path.
     *
     * @throws InvalidArgumentException if path is empty, contains null bytes, or escapes base directory
     */
    public function resolve(string $walletPathOrName): string
    {
        $input = trim($walletPathOrName);
        if ($input === '' || str_contains($input, "\0")) {
            throw new InvalidArgumentException('Wallet path must not be empty or contain null bytes.');
        }

        // Detect if input is already absolute
        $isAbsolute = str_starts_with($input, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $input) === 1;

        if ($isAbsolute) {
            $candidate = $input;
        } else {
            $candidate = $this->canonicalBaseDir . DIRECTORY_SEPARATOR . ltrim($input, '/\\');
        }

        // Normalize separators
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);

        // Check if file exists
        $realFile = realpath($normalized);
        if ($realFile !== false) {
            $targetPath = $realFile;
        } else {
            // If file does not exist yet (e.g. newly created wallet), check parent directory
            $dir = dirname($normalized);
            $realDir = realpath($dir);
            if ($realDir === false) {
                // If parent directory also does not exist, resolve lexically
                $targetPath = $this->normalizeLexicalPath($normalized);
            } else {
                $targetPath = $realDir . DIRECTORY_SEPARATOR . basename($normalized);
            }
        }

        // Verify that targetPath is inside canonicalBaseDir
        if (!$this->isSubdirectoryOf($targetPath, $this->canonicalBaseDir)) {
            throw new InvalidArgumentException(
                "Access denied: wallet path '{$input}' is outside authorized directory."
            );
        }

        return $targetPath;
    }

    private function isSubdirectoryOf(string $path, string $parent): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $parent = rtrim($parent, DIRECTORY_SEPARATOR);

        if ($path === $parent) {
            return true;
        }

        return str_starts_with($path, $parent . DIRECTORY_SEPARATOR);
    }

    private function normalizeLexicalPath(string $path): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $safeParts = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($safeParts);
            } else {
                $safeParts[] = $part;
            }
        }

        $prefix = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';

        return $prefix . implode(DIRECTORY_SEPARATOR, $safeParts);
    }
}
