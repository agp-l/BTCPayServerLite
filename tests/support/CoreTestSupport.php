<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function coreCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function coreSame(mixed $expected, mixed $actual, string $message): void
{
    coreCheck($expected === $actual, $message . ' (actual: ' . var_export($actual, true) . ')');
}
function coreDirectory(): string
{
    $dir = sys_get_temp_dir() . '/btcpay-core-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    return $dir;
}
/** Real processes released from one barrier. Do not retain parent PDO handles across fork. */
function coreConcurrent(int $count, callable $operation): array
{
    coreCheck(function_exists('pcntl_fork'), 'pcntl is required for core concurrency tests.');
    $dir = coreDirectory();
    $children = [];
    for ($i = 0; $i < $count; ++$i) {
        $pid = pcntl_fork();
        coreCheck($pid >= 0, 'Unable to fork concurrency test process.');
        if ($pid === 0) {
            while (!is_file($dir . '/go')) { usleep(1000); }
            try {
                file_put_contents($dir . '/' . $i, json_encode(['result' => $operation($i)], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $e) {
                file_put_contents($dir . '/' . $i, json_encode(['error' => $e::class . ': ' . $e->getMessage()]));
                exit(1);
            }
        }
        $children[] = $pid;
    }
    touch($dir . '/go');
    $results = [];
    foreach ($children as $i => $pid) {
        pcntl_waitpid($pid, $status);
        $result = json_decode((string) file_get_contents($dir . '/' . $i), true);
        coreCheck(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Concurrent child failed: ' . json_encode($result));
        $results[] = $result['result'];
        unlink($dir . '/' . $i);
    }
    unlink($dir . '/go'); rmdir($dir);
    return $results;
}
function coreIncrement(string $path): int
{
    $file = fopen($path, 'c+'); flock($file, LOCK_EX);
    $number = (int) stream_get_contents($file) + 1;
    rewind($file); ftruncate($file, 0); fwrite($file, (string) $number);
    flock($file, LOCK_UN); fclose($file);
    return $number;
}
