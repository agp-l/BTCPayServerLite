<?php

declare(strict_types=1);

require __DIR__ . '/support/CoreTestSupport.php';

$root = dirname(__DIR__);
$port = random_int(20000, 50000);
$log = tempnam(sys_get_temp_dir(), 'worker-http-');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
coreCheck(is_resource($server), 'Could not start checkout HTTP test server');
try {
    $ready = false;
    for ($i = 0; $i < 100; ++$i) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($socket !== false) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    coreCheck($ready, 'HTTP test server did not start');
    foreach (['payment_worker.php','wallet_receive_sync.php','repair_store_xpub.php'] as $entrypoint) {
    $body = file_get_contents('http://127.0.0.1:' . $port . '/' . $entrypoint, false,
        stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]));
    coreCheck(str_contains($http_response_header[0] ?? '', '404'), 'Payment worker is publicly runnable');
    coreSame('', $body, 'Worker initialized application/config or scanned over HTTP');
    echo "[PASS] {$entrypoint} returns HTTP 404 before configuration or any scan\n";
    }
} finally {
    proc_terminate($server); fclose($pipes[0]); proc_close($server); unlink($log);
}
