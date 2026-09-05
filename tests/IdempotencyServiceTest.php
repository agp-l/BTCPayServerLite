<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BtcPayLite\Database;
use BtcPayLite\GreenfieldApiException;
use BtcPayLite\IdempotencyService;

final class IdempotencyTestDatabase extends Database
{
    private PDO $testPdo;

    public function __construct(PDO $testPdo)
    {
        $this->testPdo = $testPdo;
        parent::__construct('127.0.0.1', 'test_db', 'user', 'pass');
    }

    protected function createPdo(string $dsn, string $user, string $password, array $options): PDO
    {
        return $this->testPdo;
    }
}

// Create in-memory SQLite database for testing
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
    CREATE TABLE api_idempotency_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        store_id TEXT NOT NULL,
        idempotency_key TEXT NOT NULL,
        request_hash BLOB NOT NULL,
        response_code INTEGER NOT NULL,
        response_body TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        UNIQUE (store_id, idempotency_key)
    )
');

$database = new IdempotencyTestDatabase($pdo);
$service = new IdempotencyService($database);

$storeId = 'store_abc123';
$key = 'req_test_key_001';
$payload = ['amount' => 100000, 'currency' => 'BTC'];

$executionCount = 0;
$operation = function () use (&$executionCount): array {
    $executionCount++;
    return ['invoice_id' => 'inv_999', 'status' => 'New'];
};

// 1. First execution
$res1 = $service->execute($storeId, $key, $payload, $operation);
if ($executionCount !== 1) {
    throw new RuntimeException("Expected executionCount 1, got {$executionCount}");
}
if ($res1['body']['invoice_id'] !== 'inv_999') {
    throw new RuntimeException("Invalid response body");
}

// 2. Second execution with identical key and payload: must NOT re-run operation
$res2 = $service->execute($storeId, $key, $payload, $operation);
if ($executionCount !== 1) {
    throw new RuntimeException("Expected operation to NOT be executed again; count is {$executionCount}");
}
if ($res2['body']['invoice_id'] !== 'inv_999') {
    throw new RuntimeException("Second call did not return cached body");
}

// 3. Execution with same key but different payload: must throw 409 Conflict
$differentPayload = ['amount' => 500000, 'currency' => 'BTC'];
$caughtConflict = false;
try {
    $service->execute($storeId, $key, $differentPayload, $operation);
} catch (GreenfieldApiException $e) {
    if ($e->getHttpStatus() === 409 && $e->getOperation() === 'idempotency_conflict') {
        $caughtConflict = true;
    }
}
if (!$caughtConflict) {
    throw new RuntimeException("Expected 409 idempotency_conflict exception");
}

// 4. Failed operation clears reservation so retry works
$failingKey = 'req_failing_key_002';
$failCount = 0;
$failingOperation = function () use (&$failCount): array {
    $failCount++;
    if ($failCount === 1) {
        throw new RuntimeException("Temporary DB error");
    }
    return ['invoice_id' => 'inv_recovered'];
};

try {
    $service->execute($storeId, $failingKey, $payload, $failingOperation);
} catch (RuntimeException) {
    // Expected on first try
}

$resRetry = $service->execute($storeId, $failingKey, $payload, $failingOperation);
if ($resRetry['body']['invoice_id'] !== 'inv_recovered') {
    throw new RuntimeException("Retry after failure failed to return valid body");
}

echo "IdempotencyServiceTest passed.\n";
