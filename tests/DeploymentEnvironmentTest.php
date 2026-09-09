<?php

declare(strict_types=1);
require __DIR__ . '/support/CoreTestSupport.php';
use BtcPayLite\DeploymentEnvironment;

$root = coreDirectory();
try {
    $config = ['wallet_path' => $root . '/wallets/default', 'store_wallets_dir' => $root . '/wallets',
        'electrum_data_dir' => $root . '/electrum', 'rpc_pass' => 'SECRET-NOT-FOR-REPORT', 'secret_key' => 'SECRET-NOT-FOR-REPORT'];
    $report = DeploymentEnvironment::checks($config, $root);
    coreCheck(in_array(false, array_column($report, 'ok'), true), 'Missing shared dirs were reported ready');
    $script = DeploymentEnvironment::permissionsScript($config, $root, 'daemon', 'ag', 'electrum');
    coreCheck(!str_contains($script, 'SECRET-NOT-FOR-REPORT'), 'Permission plan exposed credentials');
    coreCheck(str_contains($script, 'u:daemon:rwx,u:ag:rwx'), 'Web and worker do not share runtime directories');
    coreCheck(str_contains($script, 'u:daemon:rwx,u:ag:rwx,u:electrum:rwx'), 'Wallet directory excludes daemon service');
    coreCheck(str_contains($script, 'setfacl -d -m'), 'New files will not inherit shared ACL');
    coreCheck(str_contains($script, "-maxdepth 1 -type f -name 'store_*_wallet'"), 'Managed wallets lack existing-file ACL repair');
    coreCheck(!str_contains($script, 'chmod 777') && !str_contains($script, 'rm '), 'Permission plan removes files or grants global write');
    coreCheck(!is_dir($root . '/wallets'), 'Generating a plan mutated filesystem');
    foreach (['', 'daemon; id', 'x\nroot', '-root'] as $badUser) {
        try { DeploymentEnvironment::permissionsScript($config, $root, $badUser, 'ag', 'electrum'); throw new LogicException('Unsafe account accepted'); }
        catch (InvalidArgumentException $e) {}
    }
    foreach (['/', '/tmp/../etc', "relative", "/tmp/evil\npath"] as $badPath) {
        try { DeploymentEnvironment::permissionsScript(array_replace($config, ['store_wallets_dir' => $badPath]), $root, 'daemon', 'ag', 'electrum'); throw new LogicException('Unsafe path accepted'); }
        catch (InvalidArgumentException $e) {}
    }
    // Shell quoting must preserve literal quotes and command substitution in paths.
    $config['store_wallets_dir'] = $root . '/literal\'$(touch SHOULD_NOT_EXIST)';
    $script = DeploymentEnvironment::permissionsScript($config, $root, 'daemon', 'ag', 'electrum');
    $file = $root . '/plan.sh'; file_put_contents($file, $script);
    $proc = proc_open(['sh', '-n', $file], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    coreSame(0, proc_close($proc), 'Permission script is invalid shell: ' . $error);
    coreCheck(str_contains($script, "'\\''"), 'Quote in path was not shell escaped');
    unlink($file);
    // CLI bootstrap can report absent config without executing DB/RPC.
    $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/deployment.php', '--check'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    $report = json_decode($output, true);
    coreCheck(is_array($report) && isset($report['checks'], $report['php_binary']), 'CLI check failed to produce structured report: ' . $error);
    coreSame($report['ok'] ? 0 : 1, $exit, 'Readiness exit status disagrees with report');
    coreCheck(!str_contains($output, 'rpc_pass') && !str_contains($output, 'secret_key'), 'CLI report exposed config');
    echo "[PASS] Deployment report and repeatable ACL plan: scoped accounts, inheritance, safe quoting, no secrets or mutation\n";
} finally { rmdir($root); }
