<?php

declare(strict_types=1);

use BtcPayLite\InstallationManager;

require_once __DIR__ . '/../vendor/autoload.php';

$sql = <<<'SQL'
-- comment with ;
CREATE TABLE `one` (`value` VARCHAR(50) DEFAULT 'semi;colon');
# another ; comment
INSERT INTO `one` (`value`) VALUES ('it''s;valid');
/* block ; comment */
CREATE TABLE `two` (`id` INT);
SQL;

$statements = InstallationManager::splitSqlStatements($sql);
if (count($statements) !== 3) {
    throw new RuntimeException('SQL splitter returned the wrong statement count.');
}
if (!str_contains($statements[0], "'semi;colon'")) {
    throw new RuntimeException('SQL splitter broke a quoted semicolon.');
}
if (!str_contains($statements[1], "'it''s;valid'")) {
    throw new RuntimeException('SQL splitter broke an escaped SQL quote.');
}

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/sql.sql');
if (!is_string($schema)) {
    throw new RuntimeException('Fresh-install schema is unavailable.');
}
if (str_contains($schema, 'CREATE DATABASE') || preg_match('/^USE\s+/mi', $schema)) {
    throw new RuntimeException('Fresh-install schema is still bound to a fixed database name.');
}
if (str_contains($schema, 'replace-with-a-random-store-api-key')) {
    throw new RuntimeException('Fresh-install schema still creates development credentials.');
}
if (count(InstallationManager::splitSqlStatements($schema)) < 10) {
    throw new RuntimeException('Fresh-install schema was not parsed into all expected statements.');
}

echo "4 installation manager tests passed.\n";
