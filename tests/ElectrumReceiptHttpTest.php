<?php
declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BtcPayLite\{BtcStatelessFactory, BtcStatelessTokenCodec, Database, ElectrumReceiptBlockchainProvider,
    InstallationSchema, InvoicePaymentPresentation, PaymentWorkerRunner};

$dir = coreDirectory(); $oldCache = getenv('BTCPAY_BLOCKCHAIN_CACHE_DIR');
putenv('BTCPAY_BLOCKCHAIN_CACHE_DIR=' . $dir . '/cache');
$address = '1BoatSLRHtKNngkdXEeobR76b53LETtpyT'; $creator = new AddressCreator();
$funding = TransactionFactory::build()->input(str_repeat('1', 64), 0)->payToAddress(5, $creator->fromString($address))->get();
$spend = TransactionFactory::build()->spendOutputFrom($funding, 0)
    ->payToAddress(4, $creator->fromString('1BitcoinEaterAddressDontSendf59kuE'))->get();
$fixture = ['address' => $address, 'history' => [['tx_hash' => $funding->getTxId()->getHex(), 'height' => 100],
    ['tx_hash' => $spend->getTxId()->getHex(), 'height' => 101]],
    'transactions' => [$funding->getTxId()->getHex() => $funding->getHex(), $spend->getTxId()->getHex() => $spend->getHex()]];
file_put_contents($dir . '/fixture.json', json_encode($fixture));
file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$request = json_decode(file_get_contents('php://input'), true);
$fixture = json_decode(file_get_contents(__DIR__ . '/fixture.json'), true);
file_put_contents(__DIR__ . '/requests.jsonl', json_encode($request) . "\n", FILE_APPEND);
$params = $request['params'] ?? [];
if (isset($params['wallet']) || isset($params['wallet_path'])) { http_response_code(400); exit; }
$result = match ($request['method'] ?? '') {
    'getaddresshistory' => ($params['address'] ?? '') === $fixture['address'] ? $fixture['history'] : [],
    'gettransaction' => $fixture['transactions'][$params['txid']] ?? null,
    'getaddressbalance' => ['confirmed' => '0', 'unconfirmed' => '0'],
    default => null,
};
header('Content-Type: application/json');
echo json_encode(['jsonrpc' => '2.0', 'id' => $request['id'] ?? null, 'result' => $result]);
PHP);
$reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
coreCheck(is_resource($reservation), 'Could not reserve RPC test port');
$port = (int) substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1); fclose($reservation);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'a'], 2 => ['file', $dir . '/server.log', 'a']], $pipes, $dir);
coreCheck(is_resource($server), 'Could not start RPC HTTP fixture');
$admin = null; $name = null;
try {
    $ready = false;
    for ($i = 0; $i < 250; ++$i) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($socket !== false) { fclose($socket); $ready = true; break; } usleep(20000);
    }
    coreCheck($ready, 'RPC fixture did not start');
    $secret = str_repeat('s', 32);
    $config = ['rpc_host' => '127.0.0.1', 'rpc_port' => $port, 'secret_key' => $secret, 'rpc_timeout' => 30];
    $factory = new BtcStatelessFactory($config);
    coreCheck($factory->blockchainProvider() instanceof ElectrumReceiptBlockchainProvider, 'Production factory selected balance-only observer');
    coreSame(35, $factory->blockchainProvider()->maxObservationDurationSeconds(), 'Factory did not bound receipt operation');
    $token = (new BtcStatelessTokenCodec($secret))->encode(['ver' => 3, 'a' => $address, 'v' => '0.00000005',
        'd' => 'HTTP receipt', 'p' => [], 't' => time(), 'e' => time() + 600]);
    $result = $factory->service()->checkStatus($token);
    coreSame('paid', $result['status'], 'Real RPC/factory did not detect spent payment');
    coreSame('0.00000000', $result['payment']['current_balance'], 'Real RPC balance not preserved');
    coreSame(4, count(file($dir . '/requests.jsonl')), 'Unexpected production RPC calls');
    (new BtcStatelessFactory($config))->service()->checkStatus($token);
    coreSame(4, count(file($dir . '/requests.jsonl')), 'Another request bypassed shared cache');
    echo "[PASS] Real HTTP JSON-RPC, production stateless factory, spent payment and shared cadence\n";

    if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Production worker factory requires MySQL (enabled in CI).\n"; return; }
    $host = getenv('BTCPAY_TEST_MYSQL_HOST'); $dbPort = (int) (getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
    $user = getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass = getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
    $admin = new PDO("mysql:host=$host;port=$dbPort", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = 'btcpay_receipt_http_' . bin2hex(random_bytes(5)); $admin->exec('CREATE DATABASE `' . $name . '`');
    $db = new Database($host, $name, $user, $pass, $dbPort); $pdo = $db->getPdo();
    (new InstallationSchema(dirname(__DIR__) . '/sql.sql'))->import($pdo);
    $pdo->exec("INSERT INTO stores (id,name,api_key) VALUES ('http','http','test-key')");
    $pdo->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,created_at,expires_at)
        VALUES ('http-receipt','http',?,'0.00000005',?,?)")->execute([$address, time(), time() + 600]);
    $run = PaymentWorkerRunner::fromConfig($db, $config)->run('cli');
    coreSame(true, $run['success'], 'Production worker failed');
    coreSame(1, $run['stats']['transitioned'], 'Production worker did not settle from history');
    coreSame(4, count(file($dir . '/requests.jsonl')), 'Worker and stateless status did not share cache');
    $invoice = $pdo->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
    coreSame('Settled', $invoice['status'], 'Production worker did not persist settlement');
    coreSame('0.00000005', InvoicePaymentPresentation::fromInvoice($invoice)['payment']['total_received'], 'DB presentation lost receipt');
    $pdo->prepare("INSERT INTO invoices (id,store_id,btc_address,amount,created_at,expires_at)
        VALUES ('manual-receipt','http',?,'0.00000005',?,?)")->execute(['1BitcoinEaterAddressDontSendf59kuE', time(), time() + 600]);
    $manual = PaymentWorkerRunner::fromConfig($db, $config, true)->run('manual');
    coreSame(1, $manual['stats']['scanned'], 'Manual receipt bound silently skipped every invoice');
    coreSame(5, count(file($dir . '/requests.jsonl')), 'Empty address needed more than one history request');
    coreSame('New', $pdo->query("SELECT status FROM invoices WHERE id='manual-receipt'")->fetchColumn(), 'Different address inherited paid receipt');
    echo "[PASS] Production CLI/manual factories, real DB and zero-RPC shared cached scan\n";
} finally {
    if ($admin !== null && $name !== null) { $admin->exec('DROP DATABASE `' . $name . '`'); }
    proc_terminate($server); fclose($pipes[0]); proc_close($server);
    putenv($oldCache === false ? 'BTCPAY_BLOCKCHAIN_CACHE_DIR' : 'BTCPAY_BLOCKCHAIN_CACHE_DIR=' . $oldCache);
}
