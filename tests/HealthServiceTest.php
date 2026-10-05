<?php

declare(strict_types=1);

require __DIR__ . '/support/CoreTestSupport.php';

use BtcPayLite\Database;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumRPCException;
use BtcPayLite\HealthService;

final class HealthTestDatabase extends Database
{
    public function __construct(private PDO $connection) {}
    public function getPdo(): PDO { return $this->connection; }
}
final class HealthTestRpc extends ElectrumRPC
{
    public array $calls = [];
    public mixed $info = ['connected' => true, 'blockchain_height' => 900000, 'server_height' => 900000];
    public function __construct() {}
    public function getEndpoint(): string { return 'http://127.0.0.1:7777'; }
    public function callDaemon(string $method, array $params = []): mixed
    {
        $this->calls[] = $method;
        coreSame('version', $method, 'Unexpected daemon command');
        return '4.6';
    }
    public function callNetwork(string $method, array $params = []): mixed
    {
        $this->calls[] = $method;
        coreSame('getinfo', $method, 'Unsupported daemon_status command must not be used');
        if ($this->info instanceof Throwable) { throw $this->info; }
        return $this->info;
    }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['stores', 'users', 'webhooks', 'api_idempotency_keys'] as $table) { $pdo->exec("CREATE TABLE {$table} (id INTEGER)"); }
$pdo->exec('CREATE TABLE invoices (status TEXT)');
$pdo->exec('CREATE TABLE webhook_deliveries (status TEXT)');
$pdo->exec("INSERT INTO webhook_deliveries VALUES ('Pending'), ('Retry'), ('Processing'), ('Dead'), ('Dead'), ('Delivered')");
$pdo->exec("INSERT INTO invoices VALUES ('New'), ('Processing'), ('Settled')");
$rpc = new HealthTestRpc();
$service = new HealthService(new HealthTestDatabase($pdo), $rpc);
$report = $service->check();
coreSame(true, $report['database']['healthy'], 'Complete schema was rejected');
coreSame(null, $report['electrum']['synced'], 'Equal heights do not prove global synchronization');
coreSame(true, $report['electrum']['network_connected'], 'Connected network evidence lost');
coreSame(3, $report['queues']['pending_webhook_deliveries'], 'Retry/Processing were omitted');
coreSame(2, $report['queues']['failed_webhook_deliveries'], 'Dead deliveries were omitted');
coreSame(2, $report['queues']['active_monitored_invoices'], 'Active count differs');
$rpc->info = ['connected' => false];
coreSame(false, $service->check()['electrum']['healthy'], 'Disconnected network passed health');
$rpc->info = new ElectrumRPCException('unsupported', 'remote', 'getinfo', rpcCode: -32601);
$report = $service->check();
coreSame(true, $report['electrum']['healthy'], 'Reachable legacy daemon failed reachability');
coreSame(null, $report['electrum']['network_connected'], 'Missing getinfo fabricated network evidence');
$rpc->info = new ElectrumRPCException('secret=do-not-leak', 'authentication', 'getinfo');
$report = $service->check();
coreSame(false, $report['electrum']['healthy'], 'Failed authenticated network probe passed');
coreCheck(!str_contains(json_encode($report), 'do-not-leak'), 'Raw error leaked into diagnostic');
$pdo->exec('DROP TABLE users');
coreSame(false, $service->check()['database']['healthy'], 'Missing core table passed health');
$pdo->exec('DROP TABLE webhook_deliveries');
coreSame('queue_unavailable', $service->check()['queues']['error'], 'Queue error is not sanitized');
echo "[PASS] Health schema, queue states, unknown sync and safe errors\n";
