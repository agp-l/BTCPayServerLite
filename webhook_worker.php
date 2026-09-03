<?php

declare(strict_types=1);

require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/WebhookWorker.php';

use BtcPayLite\Database;
use BtcPayLite\WebhookWorker;

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo "Configuration file missing.\n";
    exit(1);
}

$config = require $configFile;
$db = new Database($config['db']);
$worker = new WebhookWorker($db);

$iterations = isset($argv[1]) && $argv[1] === '--once' ? 1 : 5;
for ($i = 0; $i < $iterations; $i++) {
    $delivered = $worker->runOnce();
    if ($iterations > 1 && $delivered === 0) {
        sleep(2);
    }
}
