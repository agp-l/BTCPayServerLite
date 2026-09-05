<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Atomically reserves derivation indices using database transaction with row-level locking (FOR UPDATE).
 */
class DbAddressIndexStore implements AddressIndexStoreInterface
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function reserveNextIndex(string $storeId): int
    {
        $pdo = $this->database->getPdo();
        $storeId = trim($storeId);

        $alreadyInTransaction = $pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare('SELECT xpub_last_index FROM stores WHERE id = ? FOR UPDATE');
            $stmt->execute([$storeId]);
            $row = $stmt->fetch();

            if (!is_array($row)) {
                throw new AddressGenerationException(
                    "Store '{$storeId}' does not exist.",
                    GeneratedAddress::SOURCE_XPUB,
                    404
                );
            }

            $currentIndex = (int) ($row['xpub_last_index'] ?? 0);
            $nextIndex = $currentIndex + 1;

            $updateStmt = $pdo->prepare('UPDATE stores SET xpub_last_index = ? WHERE id = ?');
            $updateStmt->execute([$nextIndex, $storeId]);

            if (!$alreadyInTransaction) {
                $pdo->commit();
            }

            return $currentIndex;
        } catch (Throwable $e) {
            if (!$alreadyInTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof AddressGenerationException) {
                throw $e;
            }
            $msg = strtolower($e->getMessage());
            $code = (str_contains($msg, 'lock wait timeout') || str_contains($msg, 'deadlock')) ? 503 : 500;
            throw new AddressGenerationException(
                'Database index reservation failed: ' . $e->getMessage(),
                GeneratedAddress::SOURCE_XPUB,
                $code,
                $e
            );
        }
    }
}
