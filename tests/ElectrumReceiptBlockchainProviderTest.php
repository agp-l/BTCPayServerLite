<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Script\WitnessProgram;
use BitWasp\Buffertools\Buffer;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BtcPayLite\{AddressPaymentObservation, BlockchainProviderException, BlockchainProviderInterface,
    BtcStatelessInvoiceManager, BtcStatelessTokenCodec, Database, DatabaseMigrationManager,
    ElectrumReceiptBlockchainProvider, ElectrumRPC, ElectrumWallet, InstallationSchema,
    InvoicePaymentPresentation, InvoiceStateMachine, PaymentFailureDiagnostics, PaymentWorker, WebhookDeliveryRepository};

final class ReceiptTestRPC extends ElectrumRPC
{
    public array $calls = [];
    public array $history = [];
    public array $transactions = [];
    public array $balance = ['confirmed' => '0', 'unconfirmed' => '0'];
    public function __construct() { parent::__construct('127.0.0.1', 7777, timeout: 8); }
    public function callNetwork(string $method, array $params = []): mixed
    {
        coreCheck(!isset($params['wallet'], $params['wallet_path']), 'Receipt observation used a wallet');
        $this->calls[] = [$method, $params];
        return match ($method) {
            'getaddresshistory' => $this->history,
            'gettransaction' => $this->transactions[$params['txid']] ?? null,
            'getaddressbalance' => $this->balance,
            default => throw new LogicException('Unexpected receipt RPC'),
        };
    }
}
$address = '1BoatSLRHtKNngkdXEeobR76b53LETtpyT';
$other = '1BitcoinEaterAddressDontSendf59kuE';
$creator = new AddressCreator(); $own = $creator->fromString($address); $outside = $creator->fromString($other);
$funding = TransactionFactory::build()->input(str_repeat('1', 64), 0)
    ->payToAddress(3, $own)->payToAddress(2, $own)->get();
$spend = TransactionFactory::build()->spendOutputFrom($funding, 0)->spendOutputFrom($funding, 1)
    ->payToAddress(4, $outside)->get();
$historyRow = static fn (TransactionInterface $tx, int $height = 100): array => ['tx_hash' => $tx->getTxId()->getHex(), 'height' => $height];
$seed = static function (ReceiptTestRPC $rpc, array $transactions): void {
    foreach ($transactions as $tx) { $rpc->transactions[$tx->getTxId()->getHex()] = $tx->getHex(); }
};
$expire = static function (string $dir): void {
    foreach (glob($dir . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true); $data['time'] = time() - 3600;
        file_put_contents($file, json_encode($data));
    }
    foreach (glob($dir . '/*.retry') as $file) { file_put_contents($file, '0'); }
};
$rpc = new ReceiptTestRPC(); $seed($rpc, [$funding, $spend]);
$rpc->history = [$historyRow($funding), $historyRow($funding), $historyRow($spend, 101)];
$dir = coreDirectory(); $provider = new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir);
coreSame(35, $provider->maxObservationDurationSeconds(), 'Receipt operation budget does not cover all RPCs');
$paid = $provider->observeAddress($address, 5);
coreSame(0, $paid->getCurrentBalanceSatoshis(), 'Spent funds still shown as balance');
coreSame(5, $paid->getConfirmedReceivedSatoshis(), 'Spent payment or repeated history lost/doubled receipts');
coreSame('Settled', InvoiceStateMachine::next('New', 5, $paid, time() + 600, time()), 'Spent-before-first-scan payment not settled');
coreSame(4, count($rpc->calls), 'Unbounded RPC count');
(new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir))->observeAddress($address);
coreSame(4, count($rpc->calls), 'Receipt cache bypassed across processes');
$expire($dir);
(new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir))->observeAddress($address);
coreSame(6, count($rpc->calls), 'Immutable transaction cache did not avoid raw transaction requests');
coreSame(2, count(array_filter($rpc->calls, static fn (array $call): bool => $call[0] === 'gettransaction')), 'Cached transactions refetched');
$secret = str_repeat('s', 32);
$token = (new BtcStatelessTokenCodec($secret))->encode(['ver' => 3, 'a' => $address, 'v' => '0.00000005',
    'd' => 'receipt test', 'p' => [], 't' => time(), 'e' => time() + 600]);
$status = (new BtcStatelessInvoiceManager(new ElectrumWallet($rpc), $secret, null, $provider))->checkStatelessPaymentStatus($token);
coreSame('paid', $status['status'], 'Stateless status lost spent payment');
coreSame('0.00000000', $status['payment']['current_balance'], 'Stateless current balance fabricated from receipts');
coreSame('0.00000005', $status['payment']['received_total'], 'Stateless receipts not shown');
echo "[PASS] Spent-before-first-scan receipts, tx/vout deduplication, bounded RPC and immutable transaction cache\n";

$partial = TransactionFactory::build()->input(str_repeat('2', 64), 0)->payToAddress(2, $own)->get();
$change = TransactionFactory::build()->spendOutputFrom($partial, 0)->payToAddress(2, $own)->get();
$rpc = new ReceiptTestRPC(); $seed($rpc, [$partial, $change]);
$rpc->history = [$historyRow($partial), $historyRow($change, 101)];
$rpc->balance['confirmed'] = '0.00000002';
$observation = (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: coreDirectory()))->observeAddress($address, 4);
coreSame(2, $observation->getConfirmedReceivedSatoshis(), 'Own change was counted as another incoming payment');
coreSame('Processing', InvoiceStateMachine::next('New', 4, $observation, time() + 600, time()), 'Self-spend falsely settled partial payment');
echo "[PASS] Returning own change cannot turn a partial payment into a paid invoice\n";

$rpc = new ReceiptTestRPC(); $seed($rpc, [$funding]);
$rpc->history = [$historyRow($funding, -1)]; $rpc->balance['unconfirmed'] = '0.00000005';
$dir = coreDirectory();
$pending = (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir))->observeAddress($address, 5);
coreSame(0, $pending->getConfirmedReceivedSatoshis(), 'Unconfirmed payment credited as confirmed');
coreSame(5, $pending->getUnconfirmedReceivedSatoshis(), 'Mempool receipt missing');
coreSame('Processing', InvoiceStateMachine::next('New', 5, $pending, time() + 600, time()), 'Mempool payment settled');
$rpc->history = [$historyRow($funding, 100)]; $rpc->balance = ['confirmed' => '0.00000005', 'unconfirmed' => '0'];
$expire($dir);
$confirmed = (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir))->observeAddress($address, 5);
coreSame(5, $confirmed->getConfirmedReceivedSatoshis(), 'Confirmation did not move receipt bucket');
coreSame(0, $confirmed->getUnconfirmedReceivedSatoshis(), 'Confirmation double-counted a payment');
$rpc->history = [$historyRow($funding, 0)]; $rpc->balance = ['confirmed' => '0', 'unconfirmed' => '0.00000005'];
$expire($dir);
$reorg = (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir))->observeAddress($address, 5);
coreSame(0, $reorg->getConfirmedReceivedSatoshis(), 'Reorg retained a stale confirmation height');
$rpc->history = []; $rpc->balance = ['confirmed' => '0', 'unconfirmed' => '0']; $expire($dir);
$dropped = (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir))->observeAddress($address, 5);
coreSame(0, $dropped->getReceivedPaymentSatoshis(), 'Removed/replaced transaction retained phantom receipts');
coreSame('Processing', InvoiceStateMachine::next('Processing', 5, $dropped, 1, time()), 'Observed pending payment regressed to expired');
echo "[PASS] Mempool, confirmation, pre-settlement reorg and dropped transaction reconciliation\n";

$rpc = new ReceiptTestRPC(); $transactions = [];
foreach (['3','4','5'] as $salt) { $transactions[] = TransactionFactory::build()->input(str_repeat($salt, 64), 0)->payToAddress(2, $own)->get(); }
$seed($rpc, $transactions); $rpc->history = array_map($historyRow, $transactions); $rpc->balance['confirmed'] = '0.00000006';
$dir = coreDirectory();
try { (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir))->observeAddress($address, 6); throw new LogicException('Incomplete history accepted'); }
catch (BlockchainProviderException $error) { coreSame('history_incomplete', PaymentFailureDiagnostics::code($error), 'Wrong incomplete-history diagnostic'); }
coreSame(3, count($rpc->calls), 'More than two new transactions fetched');
$expire($dir);
$complete = (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: $dir))->observeAddress($address, 6);
coreSame(6, $complete->getConfirmedReceivedSatoshis(), 'Bounded history progress lost split payments');
coreSame(3, count(array_filter($rpc->calls, static fn (array $call): bool => $call[0] === 'gettransaction')), 'Progress refetched previously verified transactions');
echo "[PASS] Split payments load in bounded resumable steps without false partial snapshots\n";

foreach ([
    ['history' => [['tx_hash' => str_repeat('a', 64), 'height' => 1]], 'transactions' => [str_repeat('a', 64) => $funding->getHex()], 'reason' => 'invalid_transaction'],
    ['history' => [$historyRow($funding)], 'transactions' => [$funding->getTxId()->getHex() => $funding->getHex() . '00'], 'reason' => 'invalid_transaction'],
    ['history' => [$historyRow($funding), $historyRow($funding, 0)], 'transactions' => [], 'reason' => 'invalid_history'],
    ['history' => [['tx_hash' => str_repeat('a', 64), 'height' => '1']], 'transactions' => [], 'reason' => 'invalid_history'],
] as $fixture) {
    $rpc = new ReceiptTestRPC(); $rpc->history = $fixture['history']; $rpc->transactions = $fixture['transactions'];
    try { (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: coreDirectory()))->observeAddress($address); throw new LogicException('Invalid payment evidence accepted'); }
    catch (BlockchainProviderException $error) { coreSame($fixture['reason'], PaymentFailureDiagnostics::code($error), 'Invalid evidence diagnostic'); }
}
echo "[PASS] Wrong transaction IDs, trailing bytes and inconsistent confirmation evidence are rejected\n";

foreach ([NetworkFactory::bitcoin(), NetworkFactory::bitcoinTestnet(), NetworkFactory::bitcoinRegtest()] as $network) {
    $destination = new SegwitAddress(WitnessProgram::v0(Buffer::hex(str_repeat('12', 20))));
    $networkAddress = $destination->getAddress($network);
    $receive = TransactionFactory::build()->input(str_repeat('6', 64), 0)->payToAddress(2, $destination)->get();
    $rpc = new ReceiptTestRPC(); $seed($rpc, [$receive]); $rpc->history = [$historyRow($receive)];
    $rpc->balance['confirmed'] = '0.00000002';
    $segwit = (new ElectrumReceiptBlockchainProvider($rpc, cacheDir: coreDirectory()))->observeAddress($networkAddress, 2);
    coreSame(2, $segwit->getConfirmedReceivedSatoshis(), 'Bech32 receipt script did not match: ' . $networkAddress);
}
echo "[PASS] Default XPUB Bech32 output matching on mainnet, testnet and regtest\n";

if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Receipt persistence requires MySQL (enabled in CI).\n"; return; }
$host = getenv('BTCPAY_TEST_MYSQL_HOST'); $port = (int) (getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass = getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$admin = new PDO("mysql:host=$host;port=$port", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'btcpay_receipts_' . bin2hex(random_bytes(5)); $admin->exec('CREATE DATABASE `' . $name . '`');
try {
    $db = new Database($host, $name, $user, $pass, $port); $pdo = $db->getPdo();
    (new InstallationSchema(dirname(__DIR__) . '/sql.sql'))->import($pdo);
    $pdo->exec('ALTER TABLE invoices DROP COLUMN confirmed_output_sats, DROP COLUMN unconfirmed_output_sats');
    $pdo->exec("INSERT INTO stores (id,name,api_key) VALUES ('receipt','receipt','test-key')");
    $pdo->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,status,confirmed_balance_sats,created_at,expires_at)
        VALUES ('old-settled','receipt',?,'0.00000005','Settled',5,?,?)")->execute([$address, time(), time() + 600]);
    $migration = new DatabaseMigrationManager($pdo, dirname(__DIR__));
    $migration->apply('011_invoice_received_outputs.sql', $migration->inspect()['plan_hash'], 1);
    coreSame(true, $migration->inspect()['schema']['ok'], 'Receipt migration differs from fresh schema');
    coreSame(null, $pdo->query("SELECT confirmed_output_sats FROM invoices WHERE id='old-settled'")->fetchColumn(), 'Migration fabricated receipts from old balance');
    coreSame('Settled', $pdo->query("SELECT status FROM invoices WHERE id='old-settled'")->fetchColumn(), 'Migration changed old terminal invoice');
    $pdo->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,created_at,expires_at)
        VALUES ('receipt-invoice','receipt',?,'0.00000005',?,?)")->execute([$address, time(), time() + 600]);
    $pdo->exec("INSERT INTO webhooks (id,store_id,url,secret,created_at) VALUES ('receipt-webhook','receipt','https://merchant.example/hook','test-secret',1)");
    $provider = new class($paid) implements BlockchainProviderInterface {
        public function __construct(public AddressPaymentObservation $observation) {}
        public function maxObservationDurationSeconds(): int { return 1; }
        public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation { return $this->observation; }
    };
    $worker = new PaymentWorker($db, $provider, new WebhookDeliveryRepository($db));
    $stats = $worker->run(1);
    coreSame(1, $stats['deliveries_queued'], 'Spent payment did not enqueue settlement');
    $row = $pdo->query("SELECT * FROM invoices WHERE id='receipt-invoice'")->fetch(PDO::FETCH_ASSOC);
    coreSame('Settled', $row['status'], 'Spent receipt not settled in DB');
    coreSame(5, (int) $row['confirmed_output_sats'], 'Verified receipts not persisted');
    coreSame(0, (int) $row['confirmed_balance_sats'], 'Receipts overwrote current balance');
    $view = InvoicePaymentPresentation::fromInvoice($row);
    coreSame('received_outputs', $view['payment']['observation_kind'], 'Wrong persisted observation kind');
    coreSame('0.00000000', $view['payment']['current_balance'], 'DB checkout mislabels receipts as balance');
    coreSame('0.00000005', $view['payment']['total_received'], 'DB checkout lost spent receipts');
    coreSame(0, $worker->run(1)['scanned'], 'Terminal spent-payment invoice observed again');
    echo "[PASS] Catalog migration preserves old invoices; spent receipt, balance, settlement and webhook persist atomically\n";
} finally { $admin->exec('DROP DATABASE `' . $name . '`'); }
