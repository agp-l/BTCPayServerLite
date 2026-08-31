<?php

declare(strict_types=1);

use BtcPayLite\BitcoinMarketDataProvider;
use BtcPayLite\BtcDashboard;
use BtcPayLite\ElectrumWallet;

require_once __DIR__ . '/../vendor/autoload.php';

final class DashboardWalletFixture extends ElectrumWallet
{
    public function __construct()
    {
    }

    public function getWalletBalance(): array
    {
        return ['confirmed' => 1.25, 'unconfirmed' => 0.00000001];
    }

    public function listAddresses(bool $receiving = true, bool $change = false): array
    {
        return $change ? ['bc1change'] : ['bc1empty', 'bc1funded'];
    }

    public function listUnspent(): array
    {
        return [
            ['address' => 'bc1funded', 'value_sats' => 1500],
            ['address' => 'bc1funded', 'value_sats' => 500],
            ['address' => 'bc1change', 'value' => '0.00000025'],
        ];
    }

    public function listTransactions(): array
    {
        return [[
            'txid' => str_repeat('a', 64),
            'bc_value' => '0.00100000',
            'incoming' => true,
            'confirmations' => 3,
            'timestamp' => 1788160000,
        ]];
    }

    public function getTransaction(string $txid): array|string
    {
        return ['hex' => '00'];
    }

    public function deserializeTransaction(string $hex): array
    {
        return ['outputs' => [[
            'address' => 'bc1funded',
            'value' => '0.00100000',
        ]]];
    }

    public function validateAddress(string $address): bool
    {
        return $address === 'bc1recipient';
    }

    public function sendPayment(
        string $destinationAddress,
        int|float|string $amount,
        ?string $password = null,
        ?int $feeRateSatVb = null
    ): string {
        return str_repeat('b', 64);
    }
}

final class DashboardMarketFixture implements BitcoinMarketDataProvider
{
    public function getRecommendedFees(): array
    {
        return ['economy' => 2, 'standard' => 4, 'priority' => 8];
    }

    public function getFiatPrice(string $currency): ?float
    {
        return $currency === 'CZK' ? 1500000.0 : null;
    }
}

$directory = sys_get_temp_dir() . '/btcpay-dashboard-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Test wallet directory could not be created.');
}
file_put_contents($directory . '/wallet-b', '{}');
file_put_contents($directory . '/wallet-a', '{}');
file_put_contents($directory . '/.hidden', '{}');

try {
    $dashboard = new BtcDashboard(new DashboardWalletFixture(), $directory, new DashboardMarketFixture());

    if ($dashboard->listWallets() !== ['wallet-a', 'wallet-b']) {
        throw new RuntimeException('Wallet files were not safely normalized and sorted.');
    }
    echo "[PASS] lists only visible regular wallet files\n";

    $balance = $dashboard->balance();
    if ($balance['confirmed_btc'] !== '1.25000000' || $balance['unconfirmed_sats'] !== 1) {
        throw new RuntimeException('Wallet balance lost exact satoshi precision.');
    }
    echo "[PASS] exposes exact BTC and satoshi balances\n";

    $addresses = $dashboard->addresses(false);
    if (
        $addresses['recommended_receive'] !== 'bc1empty'
        || $addresses['items'][0]['address'] !== 'bc1funded'
        || $addresses['items'][0]['balance_sats'] !== 2000
    ) {
        throw new RuntimeException('Address balances were not aggregated correctly.');
    }
    echo "[PASS] aggregates UTXOs without mixing BTC and satoshi units\n";

    $transactions = $dashboard->transactions();
    if (
        count($transactions) !== 1
        || $transactions[0]['amount_sats'] !== 100000
        || $transactions[0]['outputs'][0]['amount_sats'] !== 100000
    ) {
        throw new RuntimeException('Transaction amounts were not normalized correctly.');
    }
    echo "[PASS] keeps transaction and output values in the correct unit\n";

    $market = $dashboard->marketSnapshot('czk');
    if ($market['fees']['priority'] !== 8 || $market['fiat_price'] !== 1500000.0) {
        throw new RuntimeException('Market data was not composed correctly.');
    }
    echo "[PASS] composes fee and fiat data through the provider boundary\n";

    if ($dashboard->sendPayment('bc1recipient', '0.00000001', null, 2) !== str_repeat('b', 64)) {
        throw new RuntimeException('A valid payment was not delegated to Electrum.');
    }
    echo "[PASS] validates and delegates a payment request\n";

    echo "6 BtcDashboard tests passed.\n";
} finally {
    unlink($directory . '/wallet-b');
    unlink($directory . '/wallet-a');
    unlink($directory . '/.hidden');
    rmdir($directory);
}
