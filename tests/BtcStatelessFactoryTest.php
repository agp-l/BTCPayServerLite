<?php

declare(strict_types=1);

use BtcPayLite\BtcStatelessFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

function factoryAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'btcpaylite-stateless-factory-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700) && !is_dir($directory)) {
    throw new RuntimeException('Test wallet directory could not be created.');
}

touch($directory . DIRECTORY_SEPARATOR . 'Wallet-10');
touch($directory . DIRECTORY_SEPARATOR . 'wallet-2');
touch($directory . DIRECTORY_SEPARATOR . '.hidden-wallet');
mkdir($directory . DIRECTORY_SEPARATOR . 'not-a-wallet');

register_shutdown_function(static function () use ($directory): void {
    @unlink($directory . DIRECTORY_SEPARATOR . 'Wallet-10');
    @unlink($directory . DIRECTORY_SEPARATOR . 'wallet-2');
    @unlink($directory . DIRECTORY_SEPARATOR . '.hidden-wallet');
    @rmdir($directory . DIRECTORY_SEPARATOR . 'not-a-wallet');
    @rmdir($directory);
});

$factory = new BtcStatelessFactory([
    'wallet_path' => $directory . DIRECTORY_SEPARATOR . 'wallet-2',
]);

factoryAssertSame(
    ['wallet-2', 'Wallet-10'],
    $factory->availableWallets(),
    'Factory did not return safe naturally sorted wallet files.'
);
factoryAssertSame('wallet-2', $factory->defaultWalletName(), 'Default wallet name changed.');
factoryAssertSame($directory, $factory->walletDirectory(), 'Wallet directory changed.');

echo "[PASS] lists standalone wallet files without dashboard services\n";
echo "1 stateless factory test passed.\n";

