<?php

declare(strict_types=1);

use BtcPayLite\AuthManager;
use BtcPayLite\BtcDashboard;
use BtcPayLite\ElectrumRPC;
use BtcPayLite\ElectrumWallet;
use BtcPayLite\HttpBitcoinMarketDataProvider;
use BtcPayLite\UrlManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
$urlManager = isset($urlManager) && $urlManager instanceof UrlManager
    ? $urlManager
    : new UrlManager(
        $_SERVER,
        is_string($config['app_url'] ?? null) ? $config['app_url'] : null
    );

AuthManager::requireRole('admin', $urlManager->url('/login'));

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$csrfToken = AuthManager::csrfToken();
$walletPath = $config['wallet_path'] ?? null;
if (!is_string($walletPath) || trim($walletPath) === '' || str_contains($walletPath, "\0")) {
    throw new RuntimeException('Configured wallet path is invalid.');
}

$walletDirectory = dirname($walletPath);
$defaultWalletName = basename($walletPath);
$hideEmpty = isset($_GET['hide_empty']) && $_GET['hide_empty'] === '1';
$requestedWallet = $_GET['w'] ?? $defaultWalletName;

$toastMsg = '';
$sendResult = '';
$sendSucceeded = false;
$sendResultColor = '#dc2626';
$sendResultIcon = '';
$exportedSeed = '';
$exportedXprv = '';
$pageError = null;
$availableWallets = [];
$currentWalletName = $defaultWalletName;
$connStatus = 'Offline';
$fiatText = 'Electrum není dostupný';
$fiatValueStr = '';
$balanceConfirmed = 0.0;
$balanceFormatted = '0.00000000';
$finalTxs = [];
$finalAddresses = [];
$receiveAddress = 'Žádná adresa není dostupná';
$feeLow = 1;
$feeMed = 1;
$feeHigh = 1;
$mpk = '';

$rpc = new ElectrumRPC(
    $config['rpc_host'],
    (int) $config['rpc_port'],
    $config['rpc_user'],
    $config['rpc_pass']
);
$wallet = new ElectrumWallet($rpc);
$dashboard = new BtcDashboard(
    $wallet,
    $walletDirectory,
    new HttpBitcoinMarketDataProvider()
);

try {
    $availableWallets = $dashboard->listWallets();

    if (!is_string($requestedWallet) || !in_array($requestedWallet, $availableWallets, true)) {
        http_response_code(400);
        $pageError = 'Vybraná peněženka neexistuje nebo není dostupná.';
    } else {
        $currentWalletName = $requestedWallet;
    }

    if (!in_array($currentWalletName, $availableWallets, true)) {
        throw new RuntimeException('The configured default wallet is unavailable.');
    }

    $resolvedDirectory = realpath($walletDirectory);
    $activeWalletPath = $resolvedDirectory === false
        ? false
        : realpath($resolvedDirectory . DIRECTORY_SEPARATOR . $currentWalletName);
    if (
        $resolvedDirectory === false
        || $activeWalletPath === false
        || !is_file($activeWalletPath)
        || dirname($activeWalletPath) !== $resolvedDirectory
    ) {
        throw new RuntimeException('The selected wallet path is invalid.');
    }

    $wallet->loadWallet($activeWalletPath);
    $connStatus = 'Online';
    $fiatText = 'Připojeno k peněžence ' . $currentWalletName;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        AuthManager::requireCsrfToken($_POST['csrf_token'] ?? null);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        if ($action === 'new_address') {
            $dashboard->newAddress();
            $toastMsg = 'Nová přijímací adresa byla vytvořena.';
        } elseif ($action === 'export_keys') {
            $password = is_string($_POST['export_password'] ?? null)
                ? $_POST['export_password']
                : '';
            if (strlen($password) > 1024) {
                throw new InvalidArgumentException('Heslo je příliš dlouhé.');
            }

            $keys = $dashboard->privateKeys($password);
            $exportedSeed = $keys['seed'];
            $exportedXprv = $keys['master_private_key'] ?? '';
            $toastMsg = 'Privátní klíče byly zpřístupněny pouze pro tuto odpověď.';
        } elseif ($action === 'send') {
            $destination = is_string($_POST['to'] ?? null) ? trim($_POST['to']) : '';
            $amount = is_string($_POST['amount'] ?? null)
                ? str_replace(',', '.', trim($_POST['amount']))
                : '';
            $password = is_string($_POST['password'] ?? null) && $_POST['password'] !== ''
                ? $_POST['password']
                : null;
            $rawFee = $_POST['fee'] ?? null;
            $feeRate = is_string($rawFee) && ctype_digit($rawFee) ? (int) $rawFee : 0;

            if ($destination === '' || strlen($destination) > 128) {
                throw new InvalidArgumentException('Zadejte platnou cílovou Bitcoin adresu.');
            }
            if ($amount === '' || strlen($amount) > 32) {
                throw new InvalidArgumentException('Zadejte platnou částku v BTC.');
            }
            if ($feeRate < 1 || $feeRate > 10000) {
                throw new InvalidArgumentException('Poplatek musí být mezi 1 a 10 000 sat/vB.');
            }
            if ($password !== null && strlen($password) > 1024) {
                throw new InvalidArgumentException('Heslo je příliš dlouhé.');
            }

            $txid = $dashboard->sendPayment($destination, $amount, $password, $feeRate);
            $sendResult = 'Transakce byla odeslána. TXID: ' . $txid;
            $sendSucceeded = true;
            $sendResultColor = '#15803d';
            $sendResultIcon = '<i class="fa-solid fa-circle-check" aria-hidden="true"></i> ';
            $toastMsg = 'Platba byla úspěšně odeslána.';
        } else {
            throw new InvalidArgumentException('Neznámá akce peněženky.');
        }
    }

    $balance = $dashboard->balance();
    $addresses = $dashboard->addresses($hideEmpty);
    $transactions = $dashboard->transactions();
    $market = $dashboard->marketSnapshot('CZK');

    $balanceFormatted = $balance['confirmed_btc'];
    $balanceConfirmed = $balance['confirmed_sats'] / 100000000;
    $receiveAddress = $addresses['recommended_receive'] ?? 'Vytvořte novou přijímací adresu';
    $fees = $market['fees'];
    $feeLow = $fees['economy'];
    $feeMed = $fees['standard'];
    $feeHigh = $fees['priority'];

    if ($market['fiat_price'] !== null) {
        $fiatValueStr = '~ ' . number_format(
            $balanceConfirmed * $market['fiat_price'],
            2,
            ',',
            ' '
        ) . ' CZK';
    }

    $finalAddresses = array_map(
        static fn (array $address): array => [
            'address' => $address['address'],
            'confirmed' => $address['balance_sats'] / 100000000,
            'valStr' => $address['balance_btc'],
            'hasFunds' => $address['has_funds'],
            'type' => $address['type'],
        ],
        $addresses['items']
    );

    $outputLabels = [
        'receiving' => 'Vlastní přijímací adresa',
        'change' => 'Vlastní vratná adresa',
        'recipient' => 'Příjemce platby',
        'external' => 'Externí adresa',
    ];
    $finalTxs = array_map(
        static function (array $transaction) use ($outputLabels): array {
            $incoming = $transaction['direction'] === 'incoming';

            return [
                'txid' => $transaction['txid'],
                'isInc' => $incoming,
                'valStr' => ($incoming ? '+' : '-') . $transaction['amount_btc'],
                'confText' => $transaction['confirmations'] > 0
                    ? $transaction['confirmations'] . '× potvrzeno'
                    : 'Čeká v síti',
                'timeStr' => $transaction['timestamp'] === null
                    ? 'Čas není dostupný'
                    : date('j. n. Y H:i:s', $transaction['timestamp']),
                'outputs' => array_map(
                    static fn (array $output): array => [
                        'address' => $output['address'],
                        'value' => $output['amount_btc'],
                        'label' => $outputLabels[$output['ownership']] ?? 'Výstup transakce',
                    ],
                    $transaction['outputs']
                ),
            ];
        },
        $transactions
    );

    try {
        $mpk = $dashboard->masterPublicKey();
    } catch (Throwable $exception) {
        error_log('Wallet master public key load failed: ' . $exception->getMessage());
    }
} catch (InvalidArgumentException $exception) {
    $sendResult = $exception->getMessage();
    $sendResultColor = '#dc2626';
    $sendResultIcon = '<i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> ';
} catch (Throwable $exception) {
    error_log(sprintf(
        'Admin wallet request failed: %s (%s)',
        $exception->getMessage(),
        $exception::class
    ));
    $pageError = 'Peněženka nyní není dostupná. Ověřte prosím stav Electrum služby.';
    $fiatText = 'Spojení s Electrum není dostupné';
}

require __DIR__ . '/views/wallet_view.php';
