<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';
use BtcPayLite\{InstallationManager, InstallationSchema, InstallerException};

if (!getenv('BTCPAY_TEST_MYSQL_HOST')) {
    echo "[SKIP] Installer integration requires BTCPAY_TEST_MYSQL_HOST (enabled in CI).\n"; return;
}
$host = getenv('BTCPAY_TEST_MYSQL_HOST'); $port = (int) (getenv('BTCPAY_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('BTCPAY_TEST_MYSQL_USER') ?: 'root'; $pass = getenv('BTCPAY_TEST_MYSQL_PASS') ?: '';
$admin = new PDO("mysql:host={$host};port={$port}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$names = []; $dirs = [];
$create = static function (bool $existing = true) use ($admin, &$names, &$dirs): array {
    $name = 'btcpay_install_' . bin2hex(random_bytes(5)); $names[] = $name;
    if ($existing) { $admin->exec('CREATE DATABASE `' . $name . '`'); }
    $root = coreDirectory(); $dirs[] = $root;
    copy(dirname(__DIR__) . '/sql.sql', $root . '/sql.sql');
    return [$name, $root];
};
$input = static fn (string $name): array => ['db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass,
    'admin_email'=>'admin@example.test','admin_password'=>'A-long-test-password!','admin_password_confirm'=>'A-long-test-password!',
    'app_url'=>'http://localhost/BTCPayLite/','wallet_path'=>'/opt/btcpay_wallets/wallet_1',
    'electrum_cli_path'=>'/opt/electrum/run_electrum','electrum_data_dir'=>'/opt/electrum_config',
    'store_wallets_dir'=>'/opt/btcpay_wallets','rpc_host'=>'127.0.0.1','rpc_port'=>1]; // no daemon available
$connect = static fn (string $name): PDO => new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
try {
    foreach (['empty','new','imported'] as $mode) {
        [$name,$root] = $create($mode !== 'new');
        if ($mode === 'imported') { (new InstallationSchema($root.'/sql.sql'))->import($connect($name)); }
        $manager = new InstallationManager($root);
        coreCheck($manager->canInstall(), 'Test PHP must meet installation requirements');
        $manager->install($input($name) + ['create_database' => $mode === 'new']);
        $pdo = $connect($name);
        $rows = $pdo->query('SELECT * FROM users')->fetchAll();
        coreSame(1,count($rows),'Must create exactly one admin');
        coreSame('admin',$rows[0]['role'],'First account must be admin');
        coreSame('active',$rows[0]['status'],'First account must be active');
        coreCheck(password_verify('A-long-test-password!',$rows[0]['password_hash']),'Admin login hash is wrong');
        $config = require $root.'/config.php';
        coreSame($name,$config['db_name'],'Config targets wrong database');
        coreSame('wallet_path',$config['rpc_wallet_param_key'],'Missing central RPC dialect');
        coreSame(false,$config['payout_api_enabled'],'Fresh installer enabled outgoing payments');
        coreCheck(strlen($config['secret_key'])>=64,'Missing generated signing secret');
        coreCheck($manager->isInstalled() && !$manager->canInstall(),'Installed application must be locked');
        try { $manager->install($input($name)); throw new RuntimeException('Installer ran twice'); }
        catch (InstallerException $e) { coreCheck(str_contains($e->getMessage(),'nainstalovaná'),'Wrong repeat-install error'); }
        unlink($root.'/config.php');
        try { $manager->install($input($name)); throw new RuntimeException('Deleting config allowed admin takeover'); }
        catch (InstallerException $e) { coreCheck(str_contains($e->getMessage(),'obsahuje data'),'Populated DB must be protected'); }
        coreSame(1,(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),'Existing admin was modified');
        echo "[PASS] {$mode} database installs one admin without Electrum; deleted config cannot take over data\n";
    }
    [$name,$root] = $create();
    $pdo=$connect($name); (new InstallationSchema($root.'/sql.sql'))->import($pdo);
    $pdo->exec('ALTER TABLE invoices DROP COLUMN payment_observed_at');
    try { (new InstallationManager($root))->install($input($name)); throw new RuntimeException('Outdated schema accepted'); }
    catch (InstallerException $e) { coreCheck(str_contains($e->getMessage(),'Struktura'),'Wrong schema failure'); }
    coreSame(13,count($pdo->query('SHOW TABLES')->fetchAll()),'Installer dropped imported schema');
    coreSame(0,(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),'Invalid schema created admin');
    echo "[PASS] Outdated imported schema rejected without deletion\n";

    [$name,$root] = $create();
    file_put_contents($root.'/sql.sql', "\nTHIS IS NOT VALID SQL;",FILE_APPEND);
    try { (new InstallationManager($root))->install($input($name)); throw new RuntimeException('Invalid DDL accepted'); }
    catch (InstallerException $e) { coreCheck(str_contains($e->getMessage(),'SQL schéma'),'DDL error is not actionable'); }
    coreSame([], $connect($name)->query('SHOW TABLES')->fetchAll(),'Failed fresh install left tables, including idempotency');
    coreCheck(!is_file($root.'/config.php'),'Failed install published config');
    echo "[PASS] Failed fresh DDL cleans every created table and publishes no config\n";

    [$name,$root] = $create();
    copy(dirname(__DIR__).'/install.php',$root.'/install.php');
    symlink(dirname(__DIR__).'/vendor',$root.'/vendor');
    $httpPort=random_int(20000,50000); $log=tempnam(sys_get_temp_dir(),'install-http-');
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$root],
        [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
    coreCheck(is_resource($server),'Cannot start installer HTTP test');
    try {
        for($attempt=0;$attempt<100;++$attempt) {
            $socket=@fsockopen('127.0.0.1',$httpPort,$errno,$error,.1);
            if($socket!==false) { fclose($socket); break; } usleep(20000);
        }
        $request=static function (?array $post=null,string $cookie='') use ($httpPort): array {
            $options=['method'=>$post===null?'GET':'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>10,
                'header'=>"Cookie: {$cookie}\r\nContent-Type: application/x-www-form-urlencoded\r\n"];
            if($post!==null) { $options['content']=http_build_query($post); }
            $body=file_get_contents('http://127.0.0.1:'.$httpPort.'/install.php',false,stream_context_create(['http'=>$options]));
            return [$body,$http_response_header];
        };
        [$body,$headers]=$request();
        coreCheck(str_contains($body,'Připraveno'),'HTTP installer is not ready');
        coreCheck(str_contains($body,'PHP webového serveru'),'Missing runtime diagnostics');
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$body,$match);
        $csrf=$match[1]??'';coreCheck($csrf!=='','Installer did not render CSRF token');
        $cookie='';foreach($headers as $header) { if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$match)) { $cookie=$match[1]; } }
        $request($input($name),$cookie);
        coreSame([], $connect($name)->query('SHOW TABLES')->fetchAll(),'Missing CSRF installed schema');
        [$body]=$request($input($name)+['csrf_token'=>$csrf],$cookie);
        coreCheck(str_contains($body,'Instalace byla dokončena'),'HTTP POST did not complete installation');
        coreCheck(!str_contains($body,'A-long-test-password!'),'Installer reflected the admin password');
        coreSame(1,(int)$connect($name)->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn(),'HTTP POST did not create admin');
        [$body,$headers]=$request();coreCheck(str_contains($headers[0],'303'),'Installed HTTP entrypoint did not redirect');
        echo "[PASS] Actual installer HTTP GET/CSRF/POST creates first admin and locks subsequent requests\n";
    } finally {
        proc_terminate($server);fclose($pipes[0]);proc_close($server);unlink($log);
        unlink($root.'/install.php');unlink($root.'/vendor');
    }
} finally {
    foreach ($names as $name) { $admin->exec('DROP DATABASE IF EXISTS `' . $name . '`'); }
    foreach ($dirs as $root) {
        foreach (['config.php','sql.sql','.install.lock'] as $file) { if(is_file($root.'/'.$file)) { unlink($root.'/'.$file); } }
        rmdir($root);
    }
}
