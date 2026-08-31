<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../classes/PdoAdminOperationsRepository.php');
if (!is_string($source)) {
    throw new RuntimeException('Admin operations repository source could not be read.');
}

$checks = [
    'selects explicit store columns' => str_contains(
        $source,
        'SELECT id, name, api_key, wallet_path FROM stores'
    ) && !str_contains($source, 'SELECT *'),
    'limits the default store lookup' => str_contains(
        $source,
        'ORDER BY id LIMIT 1'
    ),
    'uses parameterized store inserts' => str_contains(
        $source,
        'VALUES (?, ?, ?, ?, NULL)'
    ),
    'locks idempotent webhook lookup' => str_contains(
        $source,
        'LIMIT 1 FOR UPDATE'
    ),
    'uses parameterized webhook deletion' => str_contains(
        $source,
        "prepare('DELETE FROM webhooks WHERE id = ?')"
    ),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        throw new RuntimeException('Failed admin repository query contract: ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

echo count($checks) . ' admin operations repository query tests passed.' . PHP_EOL;
