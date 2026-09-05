<?php

declare(strict_types=1);

namespace BtcPayLite;

use RuntimeException;
use Throwable;

/**
 * Atomically reserves derivation indices in stateless/file mode using flock().
 */
class FileAddressIndexStore implements AddressIndexStoreInterface
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir !== null && $storageDir !== ''
            ? rtrim($storageDir, '/\\')
            : sys_get_temp_dir();
    }

    public function reserveNextIndex(string $storeId): int
    {
        $storeId = trim($storeId);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $storeId);
        $filePath = $this->storageDir . DIRECTORY_SEPARATOR . 'btcpay_idx_' . $safeName . '.dat';

        $handle = @fopen($filePath, 'c+');
        if ($handle === false) {
            throw new AddressGenerationException(
                'Cannot open index counter file.',
                GeneratedAddress::SOURCE_XPUB,
                500
            );
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new AddressGenerationException(
                'Cannot lock index counter file.',
                GeneratedAddress::SOURCE_XPUB,
                503
            );
        }

        try {
            rewind($handle);
            $content = stream_get_contents($handle);
            $currentIndex = 0;
            if ($content !== false && trim($content) !== '') {
                $parsed = filter_var(trim($content), FILTER_VALIDATE_INT);
                if ($parsed !== false && $parsed >= 0) {
                    $currentIndex = $parsed;
                }
            }

            $nextIndex = $currentIndex + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $nextIndex);
            fflush($handle);

            return $currentIndex;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
