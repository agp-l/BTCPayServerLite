<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
// Bootstrap without Composer: this command must explain missing dependencies.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'BtcPayLite\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/classes/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
try {
    $options = getopt('', ['help', 'check', 'permissions', 'web-user:', 'worker-user:', 'electrum-user:', 'payment-systemd:', 'php-binary:', 'payment-tick:']);
    if (isset($options['help'])) {
        echo "php bin/deployment.php --check\nphp bin/deployment.php --permissions --web-user=daemon --worker-user=ag --electrum-user=ag > /tmp/btcpay-permissions.sh\n";
        echo "php bin/deployment.php --payment-systemd=service --worker-user=ag --php-binary=/usr/bin/php8.3\nphp bin/deployment.php --payment-systemd=timer\n";
        echo "For a larger due queue: --payment-systemd=timer --payment-tick=60 (invoice checks stay at 10–60 minutes).\n";
        exit;
    }
    if (isset($options['payment-systemd'])) {
        $tick = $options['payment-tick'] ?? '600';
        if (!is_string($tick) || !ctype_digit($tick)) { throw new InvalidArgumentException('Invalid payment tick.'); }
        echo \BtcPayLite\PaymentWorkerSchedule::render((string)$options['payment-systemd'], $root,
            (string)($options['php-binary'] ?? PHP_BINARY), (string)($options['worker-user'] ?? ''), (int) $tick);
        exit;
    }
    $config = is_file($root . '/config.php') ? require $root . '/config.php' : [];
    if (!is_array($config)) { throw new RuntimeException('config.php musí vracet pole.'); }
    if (isset($options['permissions'])) {
        if ($config === []) { throw new RuntimeException('Nejprve vytvořte config.php přes install.php; plán používá skutečné cesty.'); }
        echo \BtcPayLite\DeploymentEnvironment::permissionsScript($config, $root,
            (string) ($options['web-user'] ?? ''), (string) ($options['worker-user'] ?? ''), (string) ($options['electrum-user'] ?? ''));
        exit;
    }
    $autoloadError = false;
    try {
        if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
    } catch (Throwable $e) { $autoloadError = true; }
    $report = \BtcPayLite\StoreCreationDiagnostics::environment($config);
    $report['php_binary'] = PHP_BINARY;
    $report['config_present'] = $config !== [];
    $report['composer_lock_sha256'] = is_file($root . '/composer.lock') ? hash_file('sha256', $root . '/composer.lock') : null;
    $report['checks'][] = ['name' => 'config.php', 'ok' => $config !== [], 'detail' => 'Chybějící konfigurace: dokončete install.php.'];
    if ($autoloadError) { $report['checks'][] = ['name' => 'Composer autoload', 'ok' => false, 'detail' => 'Autoload nelze načíst; ověřte composer install a platform requirements.']; }
    $report['ok'] = !in_array(false, array_column($report['checks'], 'ok'), true);
    $report['scope'] = 'Toto PHP a lokální soubory. DB kontrola: wallet_receive_sync.php --check-db; schéma: database_upgrade.php. Žádné RPC ani změny oprávnění. CLI výsledek nepotvrzuje webové PHP.';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($report['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "Deployment tool failed: " . ($e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : $e::class) . PHP_EOL);
    exit(1);
}
