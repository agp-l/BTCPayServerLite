<?php

declare(strict_types=1);

namespace BtcPayLite;

use JsonException;
use PDO;
use Throwable;

/** Durable idempotency and state boundary for outgoing Bitcoin payouts. */
class PayoutRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function reserve(array $data): array
    {
        try {
            return $this->database->transactional(function (PDO $pdo) use ($data): array {
                $select = $pdo->prepare(
                    'SELECT * FROM payouts WHERE store_id = ? AND idempotency_hash = ? LIMIT 1 FOR UPDATE'
                );
                $select->execute([$data['store_id'], $data['idempotency_hash']]);
                $existing = $select->fetch(PDO::FETCH_ASSOC);
                if (is_array($existing)) {
                    if (!is_string($existing['request_hash'] ?? null)
                        || !hash_equals($existing['request_hash'], $data['request_hash'])
                    ) {
                        throw new PayoutException(
                            'Idempotency key was already used for a different payout.',
                            'reserve_payout',
                            409
                        );
                    }
                    return $this->normalize($existing);
                }

                $insert = $pdo->prepare(
                    'INSERT INTO payouts
                        (id, store_id, idempotency_hash, request_hash, destination,
                         original_currency, original_amount, payout_amount, exchange_fee,
                         fee_rate_sat_vb, state, revision, metadata, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
                );
                $insert->execute([
                    $data['id'],
                    $data['store_id'],
                    $data['idempotency_hash'],
                    $data['request_hash'],
                    $data['destination'],
                    $data['original_currency'],
                    $data['original_amount'],
                    $data['payout_amount'],
                    $data['exchange_fee'],
                    $data['fee_rate_sat_vb'],
                    $data['state'],
                    $this->encodeMetadata($data['metadata']),
                    $data['created_at'],
                    $data['created_at'],
                ]);

                $created = $this->findWithPdo($pdo, $data['id'], true);
                if ($created === null) {
                    throw new PayoutException('Payout could not be reloaded.', 'reserve_payout', 500);
                }
                return $created;
            });
        } catch (PayoutException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PayoutException('Payout could not be stored.', 'reserve_payout', 500, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    public function find(string $payoutId): ?array
    {
        try {
            return $this->findWithPdo($this->database->getPdo(), $payoutId, false);
        } catch (PayoutException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PayoutException('Payout could not be loaded.', 'find_payout', 500, $exception);
        }
    }

    /** @return list<array<string,mixed>> */
    public function listForStore(string $storeId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        try {
            $statement = $this->database->getPdo()->prepare(
                'SELECT * FROM payouts WHERE store_id = :store_id ORDER BY created_at DESC, id DESC LIMIT :row_limit'
            );
            $statement->bindValue(':store_id', $storeId);
            $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                throw new PayoutException('Stored payout list is invalid.', 'list_payouts', 500);
            }
            return array_map(fn (array $row): array => $this->normalize($row), $rows);
        } catch (PayoutException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PayoutException('Payouts could not be loaded.', 'list_payouts', 500, $exception);
        }
    }

    public function reservedAmountSince(string $storeId, int $since): BitcoinAmount
    {
        try {
            $statement = $this->database->getPdo()->prepare(
                "SELECT COALESCE(SUM(payout_amount), 0)
                   FROM payouts
                  WHERE store_id = ?
                    AND created_at >= ?
                    AND state NOT IN ('Cancelled')"
            );
            $statement->execute([$storeId, $since]);
            $amount = $statement->fetchColumn();
            if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
                throw new PayoutException('Stored payout total is invalid.', 'sum_payouts', 500);
            }
            return BitcoinAmount::fromBtc($amount);
        } catch (PayoutException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PayoutException('Payout total could not be loaded.', 'sum_payouts', 500, $exception);
        }
    }

    public function approve(string $payoutId, int $revision, int $now): array
    {
        return $this->transition(
            $payoutId,
            "UPDATE payouts
                SET state = 'AwaitingPayment', revision = revision + 1, updated_at = ?
              WHERE id = ? AND state = 'AwaitingApproval' AND revision = ?",
            [$now, $payoutId, $revision],
            'approve_payout'
        );
    }

    public function markPrepared(string $payoutId, string $rawTransaction, int $now): array
    {
        return $this->transition(
            $payoutId,
            "UPDATE payouts
                SET state = 'Prepared', raw_transaction = ?, last_error = NULL, updated_at = ?
              WHERE id = ? AND state = 'AwaitingPayment'",
            [$rawTransaction, $now, $payoutId],
            'prepare_payout'
        );
    }

    public function markBroadcast(string $payoutId, string $txid, int $now): array
    {
        return $this->transition(
            $payoutId,
            "UPDATE payouts
                SET state = 'InProgress', txid = ?, last_error = NULL, updated_at = ?
              WHERE id = ? AND state = 'Prepared'",
            [$txid, $now, $payoutId],
            'broadcast_payout'
        );
    }

    public function rememberBroadcastFailure(string $payoutId, string $message, int $now): void
    {
        try {
            $statement = $this->database->getPdo()->prepare(
                "UPDATE payouts SET last_error = ?, updated_at = ? WHERE id = ? AND state = 'Prepared'"
            );
            $statement->execute([substr($message, 0, 255), $now, $payoutId]);
        } catch (Throwable $exception) {
            error_log('Payout broadcast failure could not be persisted: ' . $exception->getMessage());
        }
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function transition(string $payoutId, string $sql, array $params, string $operation): array
    {
        try {
            $statement = $this->database->getPdo()->prepare($sql);
            $statement->execute($params);
            $payout = $this->find($payoutId);
            if ($payout === null) {
                throw new PayoutException('Payout was not found.', $operation, 404);
            }
            if ($statement->rowCount() !== 1) {
                throw new PayoutException('Payout state or revision has changed.', $operation, 409);
            }
            return $payout;
        } catch (PayoutException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PayoutException('Payout state could not be changed.', $operation, 500, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    private function findWithPdo(PDO $pdo, string $payoutId, bool $forUpdate): ?array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM payouts WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$payoutId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalize($row) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        foreach (['id', 'store_id', 'destination', 'original_currency', 'original_amount',
                     'payout_amount', 'exchange_fee', 'state'] as $field) {
            if (!is_string($row[$field] ?? null) || $row[$field] === '') {
                throw new PayoutException('Stored payout data is invalid.', 'normalize_payout', 500);
            }
        }

        $metadata = [];
        if (is_string($row['metadata'] ?? null) && $row['metadata'] !== '') {
            try {
                $decoded = json_decode($row['metadata'], true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new PayoutException('Stored payout metadata is invalid.', 'normalize_payout', 500, $exception);
            }
            if (!is_array($decoded)) {
                throw new PayoutException('Stored payout metadata is invalid.', 'normalize_payout', 500);
            }
            $metadata = $decoded;
        }

        return [
            'id' => $row['id'],
            'store_id' => $row['store_id'],
            'request_hash' => $row['request_hash'] ?? '',
            'destination' => $row['destination'],
            'original_currency' => $row['original_currency'],
            'original_amount' => $row['original_amount'],
            'payout_amount' => $row['payout_amount'],
            'exchange_fee' => $row['exchange_fee'],
            'fee_rate_sat_vb' => $row['fee_rate_sat_vb'] === null ? null : (int) $row['fee_rate_sat_vb'],
            'state' => $row['state'],
            'revision' => (int) ($row['revision'] ?? 0),
            'raw_transaction' => is_string($row['raw_transaction'] ?? null) ? $row['raw_transaction'] : null,
            'txid' => is_string($row['txid'] ?? null) ? $row['txid'] : null,
            'metadata' => $metadata,
            'last_error' => is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
            'created_at' => (int) ($row['created_at'] ?? 0),
            'updated_at' => (int) ($row['updated_at'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function encodeMetadata(array $metadata): string
    {
        try {
            return json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PayoutException('Payout metadata could not be encoded.', 'reserve_payout', 400, $exception);
        }
    }
}
