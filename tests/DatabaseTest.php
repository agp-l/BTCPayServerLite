<?php

declare(strict_types=1);

use BtcPayLite\Database;
use BtcPayLite\DatabaseException;

require dirname(__DIR__) . '/vendor/autoload.php';

final class DatabaseTestStatement extends PDOStatement
{
    /** @var list<mixed> */
    private array $results;

    /** @var list<array<int, mixed>|null> */
    public array $executions = [];

    /** @param list<mixed> $results */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function execute(?array $params = null): bool
    {
        $this->executions[] = $params;

        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return array_shift($this->results);
    }
}

final class DatabaseTestPdo extends PDO
{
    /** @var list<string> */
    public array $preparedSql = [];

    /** @var list<DatabaseTestStatement> */
    public array $statements = [];

    /** @var list<mixed> */
    public array $lockResults = ['1', '1'];

    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    private bool $transactionActive = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;
        $statement = new DatabaseTestStatement([
            array_shift($this->lockResults),
        ]);
        $this->statements[] = $statement;

        return $statement;
    }

    public function beginTransaction(): bool
    {
        ++$this->begins;
        $this->transactionActive = true;

        return true;
    }

    public function commit(): bool
    {
        ++$this->commits;
        $this->transactionActive = false;

        return true;
    }

    public function rollBack(): bool
    {
        ++$this->rollbacks;
        $this->transactionActive = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }
}

final class DatabaseUnderTest extends Database
{
    private PDO $testPdo;
    public string $dsn = '';
    public string $user = '';

    /** @var array<int, mixed> */
    public array $options = [];

    public function __construct(
        PDO $testPdo,
        string $host = '127.0.0.1',
        string $databaseName = 'btcpay_lite',
        string $user = 'db_user',
        string $password = 'db_password',
        int $port = 3306
    ) {
        $this->testPdo = $testPdo;
        parent::__construct($host, $databaseName, $user, $password, $port);
    }

    protected function createPdo(string $dsn, string $user, string $password, array $options): PDO
    {
        $this->dsn = $dsn;
        $this->user = $user;
        $this->options = $options;

        return $this->testPdo;
    }
}

function databaseAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function databaseAssertThrows(string $expectedClass, callable $callback, string $message): Throwable
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $expectedClass) {
            return $throwable;
        }

        throw new RuntimeException(
            $message . ' Unexpected exception: ' . $throwable::class,
            0,
            $throwable
        );
    }

    throw new RuntimeException($message . ' No exception was thrown.');
}

$tests = [];

$tests['builds a strict utf8mb4 PDO connection'] = static function (): void {
    $pdo = new DatabaseTestPdo();
    $database = new DatabaseUnderTest($pdo, port: 3307);

    databaseAssertSame(
        'mysql:host=127.0.0.1;port=3307;dbname=btcpay_lite;charset=utf8mb4',
        $database->dsn,
        'The database DSN is incorrect.'
    );
    databaseAssertSame(PDO::ERRMODE_EXCEPTION, $database->options[PDO::ATTR_ERRMODE], 'PDO exceptions are disabled.');
    databaseAssertSame(false, $database->options[PDO::ATTR_EMULATE_PREPARES], 'Prepared statements are emulated.');
    databaseAssertSame($pdo, $database->getPdo(), 'The configured PDO instance is not exposed.');
};

$tests['rejects unsafe connection configuration before connecting'] = static function (): void {
    $pdo = new DatabaseTestPdo();

    $exception = databaseAssertThrows(
        DatabaseException::class,
        static fn () => new DatabaseUnderTest($pdo, host: 'localhost;port=9999'),
        'A DSN-injecting host was accepted.'
    );

    databaseAssertSame('configure', $exception->getOperation(), 'The configuration operation is missing.');
};

$tests['commits a successful transaction'] = static function (): void {
    $pdo = new DatabaseTestPdo();
    $database = new DatabaseUnderTest($pdo);

    $result = $database->transactional(static fn (PDO $connection): string => 'committed');

    databaseAssertSame('committed', $result, 'The transaction result changed.');
    databaseAssertSame(1, $pdo->begins, 'The transaction did not begin.');
    databaseAssertSame(1, $pdo->commits, 'The transaction did not commit.');
    databaseAssertSame(0, $pdo->rollbacks, 'A successful transaction was rolled back.');
};

$tests['rolls back and preserves the callback failure'] = static function (): void {
    $pdo = new DatabaseTestPdo();
    $database = new DatabaseUnderTest($pdo);
    $expected = new RuntimeException('callback failed');

    $actual = databaseAssertThrows(
        RuntimeException::class,
        static fn () => $database->transactional(static function () use ($expected): void {
            throw $expected;
        }),
        'A failed transaction did not throw.'
    );

    databaseAssertSame($expected, $actual, 'The callback exception was replaced.');
    databaseAssertSame(1, $pdo->rollbacks, 'The failed transaction was not rolled back.');
    databaseAssertSame(0, $pdo->commits, 'A failed transaction was committed.');
};

$tests['releases a named lock after a successful callback'] = static function (): void {
    $pdo = new DatabaseTestPdo();
    $database = new DatabaseUnderTest($pdo);

    $result = $database->withNamedLock('electrum_rpc', 10, static fn (): string => 'done');

    databaseAssertSame('done', $result, 'The lock callback result changed.');
    databaseAssertSame(
        ['SELECT GET_LOCK(?, ?)', 'SELECT RELEASE_LOCK(?)'],
        $pdo->preparedSql,
        'The named lock lifecycle is incomplete.'
    );
    databaseAssertSame(['electrum_rpc', 10], $pdo->statements[0]->executions[0], 'GET_LOCK parameters are wrong.');
    databaseAssertSame(['electrum_rpc'], $pdo->statements[1]->executions[0], 'RELEASE_LOCK parameters are wrong.');
};

$tests['reports a busy named lock without running the callback'] = static function (): void {
    $pdo = new DatabaseTestPdo();
    $pdo->lockResults = ['0'];
    $database = new DatabaseUnderTest($pdo);
    $called = false;

    $exception = databaseAssertThrows(
        DatabaseException::class,
        static function () use ($database, &$called): void {
            $database->withNamedLock('electrum_rpc', 0, static function () use (&$called): void {
                $called = true;
            });
        },
        'A busy database lock was treated as acquired.'
    );

    databaseAssertSame(503, $exception->getCode(), 'A busy lock returned the wrong status-oriented code.');
    databaseAssertSame(false, $called, 'The callback ran without owning the lock.');
    databaseAssertSame(['SELECT GET_LOCK(?, ?)'], $pdo->preparedSql, 'A lock not owned by us was released.');
};

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, "[PASS] {$name}\n");
    } catch (Throwable $throwable) {
        fwrite(STDERR, "[FAIL] {$name}: {$throwable->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "{$passed} Database tests passed.\n");
