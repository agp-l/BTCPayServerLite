<?php

declare(strict_types=1);

require __DIR__ . '/support/CoreTestSupport.php';

use BtcPayLite\{AddressPaymentObservation, BlockchainProviderInterface, Database, InstallationManager, PaymentWorker, WebhookDeliveryRepository};
if (!getenv('BTCPAY_TEST_MYSQL_HOST')) {
    echo "[SKIP] Core migration test requires BTCPAY_TEST_MYSQL_HOST (enabled in CI).\n";
    return;
}
$host = getenv('BTCPAY_TEST_MYSQL_HOST'); $port = (int) (getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass = getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$name = 'btcpay_core_migration_' . bin2hex(random_bytes(5));
$admin = new PDO("mysql:host={$host};port={$port}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $name . '`');
try {
    $db = new Database($host, $name, $user, $pass, $port); $pdo = $db->getPdo();
    foreach (InstallationManager::splitSqlStatements(file_get_contents(__DIR__ . '/fixtures/schema_cbeba360.sql')) as $sql) { $pdo->exec($sql); }
    $pdo->exec("INSERT INTO stores (id,name,api_key) VALUES ('old','old','old-key')");
    $now = time();
    $stmt = $pdo->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,status,confirmed_received_sats,unconfirmed_received_sats,created_at,expires_at) VALUES (?, 'old', ?, '0.00000002', ?, ?, 0, ?, ?)");
    $stmt->execute(['inv_old_partial', 'bc1qoldpartial00000', 'Expired', 1, $now-120, $now-60]);
    $stmt->execute(['inv_old_settled', 'bc1qoldsettled00000', 'Settled', 2, $now-120, $now-60]);
    $body = '{"id":"inv_existing","status":"New"}';
    $stmt = $pdo->prepare('INSERT INTO api_idempotency_keys (store_id,idempotency_key,request_hash,response_code,response_body,created_at) VALUES (\'old\', ?, ?, ?, ?, ?)');
    $stmt->execute(['completed', str_repeat('a',32),200,$body,$now]);
    $stmt->execute(['anonymous', str_repeat('b',32),0,'',$now]);
    foreach (['004_core_payment_consistency.sql','005_idempotency_resource_reservation.sql'] as $migration) {
        foreach (InstallationManager::splitSqlStatements(file_get_contents(dirname(__DIR__) . '/migrations/' . $migration)) as $sql) { $pdo->exec($sql); }
    }
    $row = $pdo->query("SELECT * FROM api_idempotency_keys WHERE idempotency_key='completed'")->fetch();
    coreSame('Completed', $row['state'], 'Completed response was not migrated');
    coreSame($body, $row['response_body'], 'Migration changed saved response bytes');
    $row = $pdo->query("SELECT * FROM api_idempotency_keys WHERE idempotency_key='anonymous'")->fetch();
    coreSame('Failed', $row['state'], 'Anonymous old operation was left pending');
    coreSame(409, (int) $row['response_code'], 'Anonymous old operation must require reconciliation');
    $provider = new class implements BlockchainProviderInterface {
        public function maxObservationDurationSeconds(): int { return 1; }
        public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation
        { return new AddressPaymentObservation($address, 0, 0, 0, time()); }
    };
    $stats = (new PaymentWorker($db, $provider, new WebhookDeliveryRepository($db)))->run(1);
    coreSame(0, $stats['failed'], 'Worker cannot use migrated schema');
    coreSame('Processing', $pdo->query("SELECT status FROM invoices WHERE id='inv_old_partial'")->fetchColumn(), 'Migration lost previously observed partial payment');
    coreSame('Settled', $pdo->query("SELECT status FROM invoices WHERE id='inv_old_settled'")->fetchColumn(), 'Migration regressed terminal state');
    $pdo->exec("UPDATE invoices SET confirmed_balance_sats=2,mempool_delta_sats=-1 WHERE id='inv_old_partial'");
    coreSame(-1, (int) $pdo->query("SELECT mempool_delta_sats FROM invoices WHERE id='inv_old_partial'")->fetchColumn(), 'Mempool delta is not signed');
    echo "[PASS] Migrations upgrade actual cbeba360 schema, preserve responses/partial payments and fence anonymous claims\n";
} finally {
    $admin->exec('DROP DATABASE `' . $name . '`');
}
