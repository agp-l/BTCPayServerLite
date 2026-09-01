<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../classes/PdoAuthUserRepository.php');
if (!is_string($source)) {
    throw new RuntimeException('Repository source could not be read.');
}

$checks = [
    'selects only authentication and session columns' =>
        'SELECT id, email, password_hash, role, status, session_version',
    'limits the user lookup' => 'WHERE email = ? LIMIT 1',
    'stores binary throttle identities' => 'identity_hash = UNHEX(?)',
    'uses parameterized failure inserts' => 'VALUES (UNHEX(?), ?)',
    'records successful login telemetry without credentials' =>
        'SET last_login_at = ?, last_login_ip = ?, last_seen_at = ?, last_seen_ip = ?',
];

foreach ($checks as $name => $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Missing query contract: ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

if (str_contains($source, 'SELECT *')) {
    throw new RuntimeException('Repository must not use SELECT *.');
}

echo count($checks) . ' authentication repository query tests passed.' . PHP_EOL;
