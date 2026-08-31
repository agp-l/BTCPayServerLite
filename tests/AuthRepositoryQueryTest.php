<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../classes/PdoAuthUserRepository.php');
if (!is_string($source)) {
    throw new RuntimeException('Repository source could not be read.');
}

$checks = [
    'selects only authentication columns' => "SELECT id, email, password_hash, role FROM users",
    'limits the user lookup' => 'WHERE email = ? LIMIT 1',
    'stores binary throttle identities' => 'identity_hash = UNHEX(?)',
    'uses parameterized failure inserts' => 'VALUES (UNHEX(?), ?)',
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
