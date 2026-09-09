<?php

declare(strict_types=1);
require __DIR__.'/support/CoreTestSupport.php';
use BtcPayLite\{Database, InstallationSchema, DatabaseMigrationManager, PaymentWorker, PaymentWorkerMonitor,
    PaymentWorkerRunner, WebhookDeliveryRepository, BlockchainProviderInterface, AddressPaymentObservation};
if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Payment monitor requires MySQL.\n"; return; }
$host=getenv('BTCPAY_TEST_MYSQL_HOST'); $port=(int)(getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user=getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass=getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$admin=new PDO("mysql:host=$host;port=$port",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='btcpay_monitor_'.bin2hex(random_bytes(5)); $admin->exec('CREATE DATABASE `'.$name.'`');
try {
    $db=new Database($host,$name,$user,$pass,$port); $pdo=$db->getPdo();
    (new InstallationSchema(dirname(__DIR__).'/sql.sql'))->import($pdo);
    $pdo->exec('DROP TABLE payment_worker_runtime');
    $m=new DatabaseMigrationManager($pdo,dirname(__DIR__));
    $m->apply('010_payment_worker_runtime.sql',$m->inspect()['plan_hash'],1);
    coreSame(true,$m->inspect()['schema']['ok'],'010 does not match fresh schema');
    $provider=new class implements BlockchainProviderInterface {
        public bool $fail=false; public int $calls=0; public int $bound=1;
        public function maxObservationDurationSeconds(): int { return $this->bound; }
        public function observeAddress(string $address,int $expectedSatoshis=0): AddressPaymentObservation {
            ++$this->calls;
            if ($this->fail) { throw new RuntimeException('private-upstream-error'); }
            return new AddressPaymentObservation($address,$expectedSatoshis,0,$expectedSatoshis,time());
        }
    };
    $runner=new PaymentWorkerRunner($db,fn()=>new PaymentWorker($db,$provider,new WebhookDeliveryRepository($db)));
    $monitor=new PaymentWorkerMonitor($pdo);
    coreSame('Nezaznamenán žádný CLI běh',PaymentWorkerMonitor::automaticState($monitor->snapshot()),'Invented scheduler');
    $r=$runner->run('manual'); coreSame(0,$r['stats']['scanned'],'Empty run scanned work');
    coreSame(0,$provider->calls,'Empty run queried Electrum');
    coreSame('Succeeded',$monitor->snapshot()['runs']['manual']['state'],'Empty run not recorded');
    coreSame('Nezaznamenán žádný CLI běh',PaymentWorkerMonitor::automaticState($monitor->snapshot()),'Manual run reported automatic health');
    coreSame('cooldown',$runner->run('manual')['reason'],'Manual cooldown bypassed');
    $runner->run('cli');
    coreSame('Nedávný CLI běh ověřen',PaymentWorkerMonitor::automaticState($monitor->snapshot()),'CLI empty success not healthy');
    $pdo->exec("INSERT INTO stores (id,name,api_key) VALUES ('monitor','monitor','test-key')");
    $pdo->exec("INSERT INTO invoices (id,store_id,btc_address,amount,status,created_at,expires_at) VALUES ('monitor-invoice','monitor','test-address','0.00000001','New',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()+600)");
    coreSame(1,$monitor->snapshot()['due'],'Due invoice not visible');
    coreSame(0,$provider->calls,'Snapshot queried blockchain');
    $provider->fail=true;
    $r=$runner->run('cli'); coreSame(false,$r['success'],'Observation failure reported success');
    $snapshot=$monitor->snapshot(); coreSame('Failed',$snapshot['runs']['cli']['state'],'Failure not recorded');
    coreCheck(!str_contains(json_encode($snapshot),'private-upstream-error'),'Raw error persisted');
    $empty=$runner->run('cli');
    coreSame(0,$empty['stats']['scanned'],'Retry delay did not produce empty batch');
    $snapshot=$monitor->snapshot();
    coreSame('Succeeded',$snapshot['runs']['cli']['state'],'Empty process heartbeat should succeed');
    coreSame('observation_failed',$snapshot['runs']['cli']['error_type'],'Empty batch erased unresolved payment failure');
    coreSame('CLI se spouští; předchozí chyba kontroly zatím není ověřeně vyřešena',PaymentWorkerMonitor::automaticState($snapshot),'False recovery advertised');
    $pdo->exec("UPDATE invoices SET next_check_at=NULL WHERE id='monitor-invoice'");
    $provider->fail=false; $r=$runner->run('cli');
    coreSame(1,$r['stats']['transitioned'],'Shared runner did not transition invoice');
    coreSame('Settled',$pdo->query("SELECT status FROM invoices WHERE id='monitor-invoice'")->fetchColumn(),'Invoice not settled');
    $snapshot=$monitor->snapshot(); coreSame('Succeeded',$snapshot['runs']['cli']['state'],'Recovery did not clear failure state');
    coreCheck($snapshot['runs']['cli']['last_failed_at']!==null,'Lost failure history');
    coreSame(null,$snapshot['runs']['cli']['error_type'],'Successful nonempty batch did not clear error');
    // Independent connection holds the instance lock: neither source may start another batch.
    $other=new Database($host,$name,$user,$pass,$port);
    $q=$other->getPdo()->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$monitor->lockName()]);
    coreSame('running',$runner->run('cli')['reason'],'Concurrent CLI acquired runner lock');
    coreSame('running',$runner->run('manual')['reason'],'Concurrent admin acquired runner lock');
    $q=$other->getPdo()->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$monitor->lockName()]);
    $monitor->start('cli',str_repeat('a',32));
    coreSame('CLI běh byl přerušen',PaymentWorkerMonitor::automaticState($monitor->snapshot()),'Abandoned run invisible');
    $runner->run('cli'); coreSame('Succeeded',$monitor->snapshot()['runs']['cli']['state'],'Interrupted run not recoverable');
    $pdo->exec("UPDATE payment_worker_runtime SET started_at=UNIX_TIMESTAMP()-121 WHERE source='cli'");
    coreSame('CLI kontrola je opožděná',PaymentWorkerMonitor::automaticState($monitor->snapshot()),'Stale run reported current');
    $failing=new PaymentWorkerRunner($db,static function(){ throw new RuntimeException('secret config detail'); });
    try { $failing->run('cli'); throw new LogicException('Factory error swallowed'); } catch (RuntimeException $e) {}
    coreSame('worker_exception',$monitor->snapshot()['runs']['cli']['error_type'],'Startup error not recorded');
    $provider->bound=90; $before=$provider->calls;
    $worker=new PaymentWorker($db,$provider,new WebhookDeliveryRepository($db));
    coreSame(0,$worker->run(1,1)['scanned'],'Claim started beyond operation budget');
    coreSame($before,$provider->calls,'RPC ran beyond budget');
    echo "[PASS] Monitor migration, empty runs, source isolation, failed/recovered runs, safe errors, runtime lock and bounded work\n";
} finally { $admin->exec('DROP DATABASE `'.$name.'`'); }
