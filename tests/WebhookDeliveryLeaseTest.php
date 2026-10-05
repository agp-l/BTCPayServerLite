<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';

use BtcPayLite\{Database, InstallationSchema, WebhookDeliveryRepository, WebhookProcessor, WebhookTransport};

if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Delivery lease recovery requires MySQL.\n"; return; }
$host = getenv('BTCPAY_TEST_MYSQL_HOST'); $port = (int) (getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass = getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$admin = new PDO("mysql:host=$host;port=$port", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'btcpay_webhook_lease_' . bin2hex(random_bytes(5)); $admin->exec('CREATE DATABASE `' . $name . '`');
try {
    $db = new Database($host, $name, $user, $pass, $port); $pdo = $db->getPdo();
    (new InstallationSchema(dirname(__DIR__) . '/sql.sql'))->import($pdo);
    $pdo->exec("INSERT INTO stores (id,name,api_key) VALUES ('lease','lease','lease-key')");
    $pdo->exec("INSERT INTO webhooks (id,store_id,url,secret,created_at) VALUES ('wh_lease','lease','https://example.test/webhook','lease-secret',1)");
    $repository = new WebhookDeliveryRepository($db);
    for ($i = 0; $i < 40; ++$i) {
        $id = 'inv_lease_' . $i;
        $pdo->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,created_at,expires_at) VALUES (?,'lease',?,'0.00000001',1,2)")->execute([$id, 'address-' . $i]);
        $repository->ensureDeliveries($id, 'lease', 'InvoiceSettled', 1_700_000_000);
    }
    $now = 1_700_000_000;
    $transport = new class($pdo, $now) implements WebhookTransport {
        private int $now;
        public function __construct(private PDO $pdo, int &$now) { $this->now =& $now; }
        public function deliver(string $url, string $payload, string $signature): array {
            coreSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE status='Processing'")->fetchColumn(), 'Waiting records already hold leases.');
            coreSame($this->now, (int) $this->pdo->query("SELECT locked_at FROM webhook_deliveries WHERE status='Processing'")->fetchColumn(), 'Lease started before transport.');
            $this->now += 10;
            return ['http_status' => 204, 'primary_ip' => '8.8.8.8'];
        }
    };
    $report = (new WebhookProcessor($repository, $transport, static function () use (&$now): int { return $now; }))->run(100, 39);
    coreSame(39, $report['deliveries_delivered'], 'Slow deliveries were lost.');
    $claim = $repository->claimDueDeliveries($now, 1)[0];
    coreSame([], $repository->claimDueDeliveries($now + 299, 1), 'Live lease reclaimed too early.');
    $recovered = $repository->claimDueDeliveries($now + 301, 1)[0];
    coreSame($claim['id'], $recovered['id'], 'Crash recovery changed delivery identity.');
    coreCheck($claim['lock_token'] !== $recovered['lock_token'], 'Crash recovery did not replace owner token.');
    coreSame($claim['payload'], $recovered['payload'], 'Retry changed signed payload/delivery ID.');
    try {
        $repository->markDelivered($claim['id'], $claim['lock_token'], $now + 301, 204, '8.8.8.8');
        throw new LogicException('Old owner acknowledged reclaimed delivery.');
    } catch (BtcPayLite\WebhookDeliveryException) {}
    $repository->markDelivered($recovered['id'], $recovered['lock_token'], $now + 302, 204, '8.8.8.8');
    coreSame(40, (int) $pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE status='Delivered'")->fetchColumn(), 'Recovered event not acknowledged.');
    echo "[PASS] Real DB individual claims across a slow 390s batch, expired-lease recovery and stale owner rejection\n";
} finally { $admin->exec('DROP DATABASE `' . $name . '`'); }
