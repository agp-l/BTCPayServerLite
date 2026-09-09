<?php

declare(strict_types=1);
require __DIR__.'/support/CoreTestSupport.php';
use BtcPayLite\{Database,InstallationSchema,RememberedLogin,DatabaseMigrationManager};
if (!getenv('BTCPAY_TEST_MYSQL_HOST')) { echo "[SKIP] Remembered login requires MySQL\n"; return; }
$host=getenv('BTCPAY_TEST_MYSQL_HOST');$port=(int)(getenv('BTCPAY_TEST_MYSQL_PORT')?:3306);
$user=getenv('BTCPAY_TEST_MYSQL_USER')?:'root';$pass=getenv('BTCPAY_TEST_MYSQL_PASS')?:'';
$name='btcpay_remember_'.bin2hex(random_bytes(5));
$admin=new PDO("mysql:host=$host;port=$port",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$name.'`');
try {
    $db=new Database($host,$name,$user,$pass,$port);$pdo=$db->getPdo();
    (new InstallationSchema(dirname(__DIR__).'/sql.sql'))->import($pdo);
    $pdo->exec("INSERT INTO users (email,password_hash,role) VALUES ('admin@example.test','unused','admin')");$id=(int)$pdo->lastInsertId();
    $service=new RememberedLogin($pdo);$account=['id'=>$id,'session_version'=>1];$now=time();
    $token=$service->issue($account,$now);
    coreCheck(!str_contains(json_encode($pdo->query('SELECT * FROM remembered_logins')->fetchAll()),explode('.',$token)[1]),'Database stored raw validator');
    $result=$service->resume($token,$now+1);coreSame($id,$result['user']['id'],'Remembered wrong account');coreCheck($result['cookie']!==$token,'Credential not rotated');
    $next=$result['cookie'];coreSame($now+RememberedLogin::LIFETIME,$result['expires_at'],'Restore extended absolute expiry');
    coreSame(null,$service->resume($token,$now+2)['cookie'],'Concurrent previous credential overwrote cookie');
    coreSame(null,$service->resume($token,$now+32),'Old validator remained valid outside grace');
    coreSame(null,$service->resume(substr($next,0,33).str_repeat('0',64),$now+3),'Invalid validator accepted');
    $pdo->exec('UPDATE users SET session_version=2');coreSame(null,$service->resume($next,$now+4),'Password/session revocation ignored');
    $pdo->exec('UPDATE users SET session_version=1,status="suspended"');coreSame(null,$service->resume($next,$now+4),'Suspended user restored');
    $pdo->exec('UPDATE users SET status="active"');coreSame(null,$service->resume($next,$now+RememberedLogin::LIFETIME),'Expired token accepted');
    $service->revoke($next);coreSame(null,$service->resume($next,$now+5),'Logout token accepted');
    $pdo->exec('DROP TABLE remembered_logins');$manager=new DatabaseMigrationManager($pdo,dirname(__DIR__));$plan=$manager->inspect();
    $manager->apply('009_remembered_logins.sql',$plan['plan_hash'],$id);
    coreSame(true,(new InstallationSchema(dirname(__DIR__).'/sql.sql'))->compare($pdo)['ok'],'Fresh/upgrade schema mismatch');
    // Real HTTP cookies and AuthManager restore after the browser loses PHP session.
    $root=coreDirectory();mkdir($root.'/classes');
    copy(dirname(__DIR__).'/classes/AuthManager.php',$root.'/classes/AuthManager.php');
    symlink(dirname(__DIR__).'/vendor',$root.'/vendor');
    copy(__DIR__.'/support/remembered_http_fixture.txt',$root.'/index.php');
    file_put_contents($root.'/config.php','<?php return '.var_export(['db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass],true).';');
    $stmt=$pdo->prepare('UPDATE users SET password_hash=?');$stmt->execute([password_hash('correct horse battery staple',PASSWORD_DEFAULT)]);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$httpPort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$root],[0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes,$root);
    try {
        for($i=0;$i<100;$i++){ $sock=@fsockopen('127.0.0.1',$httpPort,$errno,$errstr,.1);if($sock!==false){fclose($sock);break;}usleep(20000); }
        $jar=[];
        $request=static function(string $action,?array $post=null) use($httpPort,&$jar): array {
            $cookies=[];foreach($jar as $k=>$v){$cookies[]=$k.'='.$v;}
            $opts=['method'=>$post===null?'GET':'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>10,'header'=>'Cookie: '.implode('; ',$cookies)."\r\nContent-Type: application/x-www-form-urlencoded\r\n"];
            if($post!==null){$opts['content']=http_build_query($post);}
            $body=file_get_contents('http://127.0.0.1:'.$httpPort.'/index.php?action='.$action,false,stream_context_create(['http'=>$opts]));
            foreach($http_response_header as $header){if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$m)){$jar[$m[1]]=$m[2];}}
            return [$body,$http_response_header];
        };
        [$body]=$request('form');$csrf=json_decode($body,true)['csrf'];
        [$body,$headers]=$request('login',['csrf'=>$csrf]);coreCheck(str_contains($headers[0],'200'),'HTTP remember login failed: '.$body);
        $token=$jar[RememberedLogin::COOKIE] ?? '';coreCheck($token!=='','Persistent cookie not issued');
        coreCheck(str_contains(strtolower(implode(' ',$headers)),'httponly'),'Cookie not HttpOnly');
        unset($jar['BTCPAYLITESESSID']);
        [$body,$headers]=$request('private');coreCheck(str_contains($headers[0],'200'),'Lost PHP session did not restore');
        coreSame($id,json_decode($body,true)['user'],'Restored wrong HTTP user');coreCheck($token!==$jar[RememberedLogin::COOKIE],'HTTP cookie not rotated');
        $csrf=json_decode($body,true)['csrf'];
        [$body]=$request('logout',['csrf'=>$csrf]);coreSame('logged out',$body,'Logout failed');
        coreSame(0,(int)$pdo->query('SELECT COUNT(*) FROM remembered_logins')->fetchColumn(),'Logout did not revoke device');
        $jar=[RememberedLogin::COOKIE=>$token];[$body,$headers]=$request('private');coreCheck(str_contains($headers[0],'303'),'Logged out device restored');
        $jar=[RememberedLogin::COOKIE=>$service->issue($account)];[$body,$headers]=$request('private',[]);coreCheck(str_contains($headers[0],'303'),'Remembered token authenticated a POST without a session');
    } finally {
        proc_terminate($server);fclose($pipes[0]);proc_close($server);
        unlink($root.'/classes/AuthManager.php');rmdir($root.'/classes');
        foreach(glob($root.'/*') as $file){unlink($file);}rmdir($root);
    }
    echo "[PASS] HTTP remembered login survives lost session, rotates cookie, revokes on logout and never restores POST\n";
    echo "[PASS] Device credentials: hash-only storage, rotation, concurrent grace, expiry, revocation, suspension and migration 009\n";
} finally { $admin->exec('DROP DATABASE `'.$name.'`'); }
