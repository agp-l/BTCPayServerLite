<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;

/**
 * File-based atomic counter for address indices in stateless mode.
 *
 * File lock (flock) is strictly held ONLY for the milliseconds required to
 * read, increment, write and flush the counter.
 */
class FileAddressIndexStore implements AddressIndexStoreInterface
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir !== null && trim($storageDir) !== ''
            ? rtrim($storageDir, DIRECTORY_SEPARATOR)
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'btcpaylite_address_indices';

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0770, true);
        }
    }

    public function reserveNextIndex(string $generatorId): int
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $generatorId) ?: 'default';
        $filePath = $this->storageDir . DIRECTORY_SEPARATOR . $safeId . '.counter';

        $fp = fopen($filePath, 'c+');
        if ($fp === false) {
            throw new RuntimeException("Cannot open counter file: {$filePath}");
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException("Cannot acquire counter lock: {$filePath}");
            }

            rewind($fp);
            $content = stream_get_contents($fp);
            $currentIndex = ($content !== false && trim($content) !== '') ? (int) trim($content) : 0;
            $nextIndex = $currentIndex + 1;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $nextIndex);
            fflush($fp);

            return $currentIndex;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
