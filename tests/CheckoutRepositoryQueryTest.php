<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../classes/PdoCheckoutRepository.php');
if (!is_string($source)) {
    throw new RuntimeException('Unable to read checkout repository source.');
}

$checks = [
    'uses an explicit invoice and store projection'
        => str_contains($source, 'SELECT i.id, i.store_id, s.wallet_path'),
    'joins the store owning the invoice'
        => str_contains($source, 'INNER JOIN stores AS s ON s.id = i.store_id'),
    'uses a parameterized invoice lookup'
        => str_contains($source, 'WHERE i.id = ?')
            && str_contains($source, '$statement->execute([$invoiceId])'),
    'limits the public lookup to one row'
        => str_contains($source, 'LIMIT 1'),
    'does not select the full invoice or store row'
        => !str_contains($source, 'SELECT *'),
];

$passes = 0;
foreach ($checks as $message => $passed) {
    if (!$passed) {
        throw new RuntimeException('Checkout repository check failed: ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
    ++$passes;
}

echo $passes . ' checkout repository query tests passed.' . PHP_EOL;
