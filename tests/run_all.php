<?php

declare(strict_types=1);

$testFiles = glob(__DIR__ . '/*Test.php');
if ($testFiles === false || count($testFiles) === 0) {
    echo "No tests found in " . __DIR__ . PHP_EOL;
    exit(1);
}

$passed = 0;
$failed = 0;
$failures = [];

echo "========================================\n";
echo " Running BTC Pay Lite Test Suite\n";
echo " Total test files: " . count($testFiles) . "\n";
echo "========================================\n";

foreach ($testFiles as $file) {
    $filename = basename($file);
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0) {
        echo "[OK]   {$filename}\n";
        $passed++;
    } else {
        echo "[FAIL] {$filename}\n";
        $failed++;
        $failures[$filename] = implode("\n", $output);
    }
}

echo "========================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

if ($failed > 0) {
    echo "\nFailure Details:\n";
    foreach ($failures as $name => $err) {
        echo "\n--- {$name} ---\n{$err}\n";
    }
    exit(1);
}

exit(0);
