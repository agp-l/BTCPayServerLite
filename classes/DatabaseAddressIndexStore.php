<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Atomically reserves derivation indices inside a MySQL transaction using FOR UPDATE.
 */
class DatabaseAddressIndexStore implements AddressIndexStoreInterface
{
    private Database $database;
    private bool $useStoreTable;

    public function __construct(Database $database, bool $useStoreTable = true)
    {
        $this->database = $database;
        $this->useStoreTable = $useStoreTable;
    }

    public function reserveNextIndex(string $generatorId): int
    {
        $pdo = $this->database->getPdo();
        $pdo->beginTransaction();

        try {
            if ($this->useStoreTable) {
                // Try from stores table first
                $select = $pdo->prepare(
                    'SELECT next_address_index FROM stores WHERE id = ? FOR UPDATE'
                );
                $select->execute([$generatorId]);
                $row = $select->fetch(PDO::FETCH_ASSOC);

                if ($row !== false && isset($row['next_address_index'])) {
                    $index = (int) $row['next_address_index'];
                    $next = $index + 1;

                    $update = $pdo->prepare('UPDATE stores SET next_address_index = ? WHERE id = ?');
                    $update->execute([$next, $generatorId]);

                    $pdo->commit();
                    return $index;
                }
            }

            // Fallback / standalone counter table: address_indices
            $checkTable = $pdo->prepare(
                'SELECT next_index FROM address_indices WHERE id = ? FOR UPDATE'
            );
            $checkTable->execute([$generatorId]);
            $counterRow = $checkTable->fetch(PDO::FETCH_ASSOC);

            $now = time();
            if ($counterRow === false) {
                $index = 0;
                $insert = $pdo->prepare(
                    'INSERT INTO address_indices (id, next_index, updated_at) VALUES (?, 1, ?)'
                );
                $insert->execute([$generatorId, $now]);
            } else {
                $index = (int) $counterRow['next_index'];
                $update = $pdo->prepare(
                    'UPDATE address_indices SET next_index = ?, updated_at = ? WHERE id = ?'
                );
                $update->execute([$index + 1, $now, $generatorId]);
            }

            $pdo->commit();
            return $index;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new DatabaseException(
                'Could not reserve next address index: ' . $e->getMessage(),
                'reserve_address_index',
                500,
                $e
            );
        }
    }
}
