<?php

declare(strict_types=1);

require __DIR__ . '/support/CoreTestSupport.php';

use BtcPayLite\{AddressGeneratorFactory, AddressPaymentObservation, BlockchainProviderInterface, BtcInvoiceManager,
    Database, DatabaseCheckoutFactory, ElectrumRPC, ElectrumWallet, GreenfieldApiException, GreenfieldApiRepository,
    GreenfieldApiService, InstallationManager, PaymentWorker, WalletLockManager, WebhookDeliveryRepository};

if (!getenv('BTCPAY_TEST_MYSQL_HOST')) {
    echo "[SKIP] Set BTCPAY_TEST_MYSQL_HOST to run mandatory real-DB core concurrency tests (enabled in CI).\n";
    return;
}
$testDatabase = 'btcpay_core_test_' . bin2hex(random_bytes(5));
putenv('BTCPAY_TEST_MYSQL_DATABASE=' . $testDatabase);
function coreDbConfig(): array
{
    return ['db_host' => getenv('BTCPAY_TEST_MYSQL_HOST'), 'db_port' => (int) (getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306),
        'db_name' => getenv('BTCPAY_TEST_MYSQL_DATABASE'), 'db_user' => getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root',
        'db_pass' => getenv('BTCPAY_TEST_MYSQL_PASS') ?: ''];
}
function coreDatabase(): Database
{
    $c = coreDbConfig(); return new Database($c['db_host'], $c['db_name'], $c['db_user'], $c['db_pass'], $c['db_port']);
}
final class ForbiddenCoreRPC extends ElectrumRPC
{
    public function __construct() { parent::__construct('127.0.0.1', 1); }
    public function call(string $method, array $params = []): mixed { throw new RuntimeException('Unexpected Electrum RPC: ' . $method); }
}
final class ForbiddenCoreWalletLock extends WalletLockManager
{
    public function withWalletLock(string $walletPath, callable $callback, int $timeoutSeconds = 3): mixed
    { throw new RuntimeException('XPUB acquired a wallet mutation lock'); }
}
function coreApi(): GreenfieldApiService
{
    $db = coreDatabase(); $wallet = new ElectrumWallet(new ForbiddenCoreRPC());
    $factory = new AddressGeneratorFactory($wallet, $db, new ForbiddenCoreWalletLock());
    $manager = new BtcInvoiceManager($wallet, str_repeat('s', 32), $db, null, $factory);
    return new GreenfieldApiService(new GreenfieldApiRepository($db), $db, $wallet, $manager, '', 'https://checkout.example');
}
function coreInsertInvoice(string $id, string $status = 'New'): void
{
    $p = coreDatabase()->getPdo();
    $p->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,status,metadata,created_at,expires_at) VALUES (?, 'worker_store', ?, '0.00000002', ?, '{}', ?, ?)")
      ->execute([$id, 'bc1q' . str_replace('_', '', $id) . '0000000000', $status, time() - 10, time() + 600]);
}
function coreInvoice(string $id): array
{
    $s = coreDatabase()->getPdo()->prepare('SELECT * FROM invoices WHERE id = ?'); $s->execute([$id]); return $s->fetch();
}
final class CoreObservedProvider implements BlockchainProviderInterface
{
    public function __construct(private Database $db, private string $counter, private int $sats = 2, private int $delay = 0) {}
    public function maxObservationDurationSeconds(): int { return 90; }
    public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation
    {
        coreCheck(!$this->db->getPdo()->inTransaction(), 'Blockchain RPC ran inside a DB transaction');
        $stmt = $this->db->getPdo()->prepare('SELECT payment_processing_until - UNIX_TIMESTAMP() FROM invoices WHERE btc_address = ?');
        $stmt->execute([$address]);
        coreCheck((int) $stmt->fetchColumn() > 90, 'Worker lease does not cover the provider operation budget');
        coreIncrement($this->counter); usleep($this->delay);
        return new AddressPaymentObservation($address, $this->sats, 0, $this->sats, time());
    }
}
function coreWorker(string $counter, ?WebhookDeliveryRepository $outbox = null, int $sats = 2): array
{
    $db = coreDatabase();
    return (new PaymentWorker($db, new CoreObservedProvider($db, $counter, $sats), $outbox ?? new WebhookDeliveryRepository($db)))->run(1);
}
$c = coreDbConfig();
$admin = new PDO("mysql:host={$c['db_host']};port={$c['db_port']}", $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4');
$admin = null;
$dir = coreDirectory();
try {
    $pdo = coreDatabase()->getPdo();
    foreach (InstallationManager::splitSqlStatements((string) file_get_contents(dirname(__DIR__) . '/sql.sql')) as $sql) { $pdo->exec($sql); }
    $xpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';
    $insert = $pdo->prepare("INSERT INTO stores (id,name,api_key,address_source,xpub) VALUES (?, ?, ?, 'xpub', ?)");
    foreach (['bulk', 'idem', 'recover', 'worker_store'] as $id) { $insert->execute([$id, $id, $id . '-key', $xpub]); }
    $insert = null; $pdo = null;

    coreConcurrent(100, static fn (): array => coreApi()->createInvoice('bulk', ['amount' => '0.00000002'], 'bulk-key'));
    $pdo = coreDatabase()->getPdo();
    $counts = $pdo->query("SELECT COUNT(*) AS invoices, COUNT(DISTINCT btc_address) AS addresses, COUNT(DISTINCT address_index) AS indices FROM invoices WHERE store_id='bulk'")->fetch();
    foreach ($counts as $count) { coreSame(100, (int) $count, '100 XPUB creates must reserve 100 unique resources'); }
    coreSame(100, (int) $pdo->query("SELECT xpub_last_index FROM stores WHERE id='bulk'")->fetchColumn(), 'XPUB index reservation count');
    $pdo = null;
    echo "[PASS] 100 concurrent XPUB invoices: 100 addresses/indices, zero RPC and zero wallet locks\n";

    $responses = coreConcurrent(100, static fn (): array => coreApi()->createInvoiceWithIdempotency('idem', ['amount' => '0.00000002'], 'idem-key', 'same-key'));
    foreach ($responses as $response) { coreSame($responses[0], $response, 'Idempotent response changed'); }
    $pdo = coreDatabase()->getPdo();
    coreSame(1, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE store_id='idem'")->fetchColumn(), 'Duplicate idempotent invoice');
    coreSame(101, (int) $pdo->query("SELECT xpub_last_index FROM stores WHERE id='idem'")->fetchColumn(), 'Duplicate idempotent index');
    $saved = $pdo->query("SELECT * FROM api_idempotency_keys WHERE store_id='idem'")->fetch();
    coreSame('Completed', $saved['state'], 'Explicit completed state');
    coreSame($responses[0]['body']['id'], $saved['resource_id'], 'Resource ID not persisted');
    try { coreApi()->createInvoiceWithIdempotency('idem', ['amount' => '0.00000003'], 'idem-key', 'same-key'); throw new RuntimeException('Hash conflict accepted'); }
    catch (GreenfieldApiException $e) { coreSame(409, $e->getHttpStatus(), 'Hash conflict status'); }
    try { coreApi()->createInvoiceWithIdempotency('idem', ['amount' => '0.00000002'], 'wrong-key', 'same-key'); throw new RuntimeException('Unauthorized replay'); }
    catch (GreenfieldApiException $e) { coreSame(401, $e->getHttpStatus(), 'Replay authentication'); }
    echo "[PASS] 100 identical keys: one invoice/index, exact response, authenticated replay and 409 conflict\n";

    $pdo->exec("CREATE TRIGGER fail_response BEFORE UPDATE ON api_idempotency_keys FOR EACH ROW BEGIN IF NEW.state='Completed' AND NEW.store_id='recover' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected response failure'; END IF; END");
    try { coreApi()->createInvoiceWithIdempotency('recover', ['amount' => '0.00000002'], 'recover-key', 'recover-key'); throw new RuntimeException('Injected response failure did not abort'); }
    catch (GreenfieldApiException $e) { coreSame(500, $e->getHttpStatus(), 'Response failure status'); }
    $claim = $pdo->query("SELECT * FROM api_idempotency_keys WHERE store_id='recover'")->fetch();
    coreSame('Pending', $claim['state'], 'Failure lost recoverable claim');
    coreCheck(is_array(json_decode($claim['resource_data'], true)), 'Failure lost address snapshot');
    coreSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE store_id='recover'")->fetchColumn(), 'Invoice committed without response');
    $pdo->exec('DROP TRIGGER fail_response');
    $retry = coreApi()->createInvoiceWithIdempotency('recover', ['amount' => '0.00000002'], 'recover-key', 'recover-key');
    coreSame($claim['resource_id'], $retry['body']['id'], 'Retry allocated another invoice ID');
    coreSame(102, (int) $pdo->query("SELECT xpub_last_index FROM stores WHERE id='recover'")->fetchColumn(), 'Retry allocated another address/index');
    echo "[PASS] Response-write failure rolls back invoice and retries the same durable address/index\n";

    // Isolate monitoring cases from address-creation stress invoices.
    $pdo->exec("UPDATE invoices SET status='Settled'");
    $pdo->exec("INSERT INTO webhooks (id,store_id,url,secret,created_at) VALUES ('wh_core','worker_store','https://merchant.example/hook','test-secret',1)");
    $pdo = null;
    coreInsertInvoice('inv_ownership');
    $results = coreConcurrent(2, static function () use ($dir): array {
        $db = coreDatabase();
        return (new PaymentWorker($db, new CoreObservedProvider($db, $dir . '/ownership', 2, 500000), new WebhookDeliveryRepository($db)))->run(1);
    });
    coreSame(1, array_sum(array_column($results, 'scanned')), 'Two workers claimed the same invoice');
    coreSame(1, (int) file_get_contents($dir . '/ownership'), 'Two workers observed the same invoice');
    coreSame('Settled', coreInvoice('inv_ownership')['status'], 'Worker settlement');
    echo "[PASS] Two real worker processes cannot own/observe the same invoice; lease exceeds RPC bound\n";

    coreInsertInvoice('inv_rollback');
    $pdo = coreDatabase()->getPdo();
    $pdo->exec("CREATE TRIGGER fail_outbox BEFORE INSERT ON webhook_deliveries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected outbox failure'");
    $stats = coreWorker($dir . '/rollback');
    coreSame(1, $stats['failed'], 'Outbox failure was swallowed');
    coreSame('New', coreInvoice('inv_rollback')['status'], 'Status committed without outbox');
    coreSame(0, (int) coreInvoice('inv_rollback')['confirmed_balance_sats'], 'Observation committed without outbox');
    $pdo->exec('DROP TRIGGER fail_outbox');
    $pdo->exec("UPDATE invoices SET next_check_at=0 WHERE id='inv_rollback'");
    coreSame(1, coreWorker($dir . '/rollback')['deliveries_queued'], 'Retry lost outbox event');
    coreSame(1, (int) $pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE invoice_id='inv_rollback'")->fetchColumn(), 'Outbox event duplicated');
    $pdo = null;
    echo "[PASS] Enqueue failure rolls back status/observation; retry delivers exactly one event\n";

    coreInsertInvoice('inv_crash');
    $pid = pcntl_fork(); coreCheck($pid >= 0, 'Fork failed');
    if ($pid === 0) {
        $db = coreDatabase();
        $outbox = new class($db) extends WebhookDeliveryRepository {
            public function enqueueInTransaction(PDO $pdo, string $invoiceId, string $storeId, string $eventType, int $timestamp): int
            { posix_kill(getmypid(), SIGKILL); throw new RuntimeException('SIGKILL did not terminate the worker'); }
        };
        (new PaymentWorker($db, new CoreObservedProvider($db, $dir . '/crash'), $outbox))->run(1); exit(1);
    }
    pcntl_waitpid($pid, $status); coreCheck(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'Crash injection point not reached');
    coreSame('New', coreInvoice('inv_crash')['status'], 'Process exit committed status before outbox');
    $pdo = coreDatabase()->getPdo();
    $pdo->exec("UPDATE invoices SET payment_processing_until=0, next_check_at=0 WHERE id='inv_crash'");
    coreSame(1, coreWorker($dir . '/crash')['deliveries_queued'], 'Crash recovery lost webhook event');
    echo "[PASS] SIGKILL between transition and enqueue cannot lose a webhook\n";

    coreInsertInvoice('inv_partial', 'Processing');
    coreWorker($dir . '/partial', null, 0);
    coreSame('Processing', coreInvoice('inv_partial')['status'], 'Processing regressed after disappearance');
    $pdo->exec("UPDATE invoices SET expires_at=created_at+1 WHERE id='inv_partial'");
    $before = coreInvoice('inv_partial');
    $checkout = DatabaseCheckoutFactory::fromConfig(coreDbConfig()); // intentionally zero RPC/secret/wallet config
    coreSame('Processing', $checkout->load('inv_partial')['status'], 'Checkout changed stored status');
    coreSame($before, coreInvoice('inv_partial'), 'Checkout mutated invoice');
    echo "[PASS] Checkout needs DB only; zero RPC and no payment transitions\n";

    // Older daemon/API paths must not create a parallel online status writer.
    $manager = new BtcInvoiceManager(new ElectrumWallet(new ForbiddenCoreRPC()), str_repeat('s', 32), coreDatabase());
    coreSame('Settled', $manager->checkDatabasePaymentStatus('inv_crash')['status'], 'Deprecated monitor is not read-only');
    echo "[PASS] Deprecated database monitor is a DB-only facade\n";
} finally {
    // The test only ever deletes the random database it created itself.
    $admin = new PDO("mysql:host={$c['db_host']};port={$c['db_port']}", $c['db_user'], $c['db_pass']);
    $admin->exec('DROP DATABASE `' . $testDatabase . '`');
}
