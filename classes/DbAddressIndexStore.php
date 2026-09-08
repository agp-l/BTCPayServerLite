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
            $stmt = $pdo->prepare('SELECT xpub, xpub_last_index FROM stores WHERE id = ? FOR UPDATE');
            $stmt->execute([$storeId]);
            $row = $stmt->fetch();

            if (!is_array($row)) {
                throw new AddressGenerationException(
                    "Store '{$storeId}' does not exist.",
                    GeneratedAddress::SOURCE_XPUB,
                    404
                );
            }

            $identity = XpubDerivationIdentity::describe((string) $row['xpub']);
            // Initialize once from every existing spelling of this XPUB. The pool
            // row serializes different stores sharing a receive branch, without
            // any Electrum lock or RPC. Never delete pool rows with a store.
            $seed = $pdo->prepare('SELECT COALESCE(MAX(xpub_last_index), 0) FROM stores WHERE xpub IN (?, ?, ?, ?, ?, ?)');
            $seed->execute($identity['aliases']);
            $floor = max((int) $row['xpub_last_index'], (int) $seed->fetchColumn());
            $pool = $pdo->prepare('INSERT INTO xpub_address_sequences (key_hash, next_index) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE next_index = GREATEST(next_index, VALUES(next_index))');
            $pool->execute([$identity['id'], $floor]);
            $pool = $pdo->prepare('SELECT next_index FROM xpub_address_sequences WHERE key_hash = ? FOR UPDATE');
            $pool->execute([$identity['id']]);
            $currentIndex = (int) $pool->fetchColumn();
            if ($currentIndex >= 2147483648) {
                throw new AddressGenerationException('Non-hardened receive indices exhausted.', GeneratedAddress::SOURCE_XPUB, 422);
            }
            $nextIndex = $currentIndex + 1;
            $pool = $pdo->prepare('UPDATE xpub_address_sequences SET next_index = ? WHERE key_hash = ?');
            $pool->execute([$nextIndex, $identity['id']]);

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
