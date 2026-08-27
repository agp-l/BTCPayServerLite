<?php
// BTCPayLite/classes/BtcDashboard.php
declare(strict_types=1);

namespace BtcPayLite;

use Exception;

/**
 * VRSTVA 3: Aplikační třída pro vizuální správu peněženky.
 */
class BtcDashboard
{
    private ElectrumWallet $wallet;
    private string $walletsDirectory;

    public function __construct(ElectrumWallet $wallet, string $walletsDirectory)
    {
        $this->wallet = $wallet;
        $this->walletsDirectory = $walletsDirectory;
    }

    public function getAvailableWallets(): array
    {
        $available = [];
        if (is_dir($this->walletsDirectory)) {
            $files = scandir($this->walletsDirectory);
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..' && !is_dir($this->walletsDirectory . '/' . $f)) {
                    $available[] = $f;
                }
            }
        }
        return $available;
    }

    public function getBalanceInfo(): array
    {
        try {
            $bal = $this->wallet->getWalletBalance();
            $confirmed = (float)($bal['confirmed'] ?? 0);
            return [
                'status' => 'ok',
                'confirmed_num' => $confirmed,
                'confirmed_formatted' => number_format($confirmed, 8, '.', ''),
                'unconfirmed_num' => (float)($bal['unconfirmed'] ?? 0)
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error', 'message' => $e->getMessage(),
                'confirmed_num' => 0, 'confirmed_formatted' => '0.00000000', 'unconfirmed_num' => 0
            ];
        }
    }

    public function getAddressesData(bool $hideEmpty = false): array
    {
        try {
            $receiving = $this->wallet->listAddresses(true, false);
            $change = $this->wallet->listAddresses(false, true);
            $unspent = $this->wallet->listUnspent();

            $balances = [];
            foreach ($unspent as $u) {
                $addr = $u['address'] ?? null;
                if ($addr) {
                    $val = $u['value'] ?? ($u['value_sats'] ? $u['value_sats'] / 100000000 : 0);
                    $balances[$addr] = ($balances[$addr] ?? 0) + (float)$val;
                }
            }

            $allAddresses = [];
            foreach (array_merge($receiving, $change) as $addr) {
                $confirmed = $balances[$addr] ?? 0;
                $isChange = in_array($addr, $change);
                $hasFunds = $confirmed > 0;

                if ($hideEmpty && !$hasFunds) continue;

                $allAddresses[] = [
                    'address' => $addr, 'confirmed' => $confirmed,
                    'valStr' => number_format($confirmed, 8, '.', ''),
                    'hasFunds' => $hasFunds, 'type' => $isChange ? 'change' : 'receiving',
                ];
            }

            $receiveAddress = 'Žádná adresa nenalezena';
            $emptyReceiving = array_filter($allAddresses, fn($a) => $a['confirmed'] == 0 && $a['type'] === 'receiving');
            if (!empty($emptyReceiving)) {
                $newestEmpty = end($emptyReceiving);
                $receiveAddress = $newestEmpty['address'];
            }
            
            usort($allAddresses, fn($a, $b) => $b['confirmed'] <=> $a['confirmed']);

            return ['status' => 'ok', 'list' => $allAddresses, 'recommended_receive' => $receiveAddress];
        } catch (Exception $e) {
            return ['status' => 'error', 'list' => [], 'recommended_receive' => 'Nedostupné'];
        }
    }

    public function getTransactionsHistory(): array
    {
        try {
            $receiving = array_flip($this->wallet->listAddresses(true, false));
            $change = array_flip($this->wallet->listAddresses(false, true));
            $rawTxs = $this->wallet->listTransactions();
            
            $finalTxs = [];

            foreach ($rawTxs as $tx) {
                if (!is_array($tx)) continue;

                $txid = $tx['txid'] ?? $tx['tx_hash'] ?? '';
                if (!$txid) continue;

                $rawVal = $tx['bc_value'] ?? ($tx['value'] ?? 0);
                $isInc = $tx['incoming'] ?? ((float)$rawVal > 0);
                $valNum = abs((float)$rawVal);
                $confs = $tx['confirmations'] ?? 0;
                $timestamp = $tx['timestamp'] ?? null;
                $timeStr = ($timestamp !== null && $timestamp > 0) ? date('j. n. Y H:i:s', (int)$timestamp) : 'Čas neznámý';

                $cleanOutputs = [];
                try {
                    $txInfo = $this->wallet->getTransaction($txid);
                    $hex = is_array($txInfo) ? ($txInfo['hex'] ?? '') : (is_string($txInfo) ? $txInfo : '');
                    if ($hex) {
                        $parsed = $this->wallet->deserializeTransaction($hex);
                        if (isset($parsed['outputs']) && is_array($parsed['outputs'])) {
                            foreach ($parsed['outputs'] as $out) {
                                $addr = $out['address'] ?? null;
                                if (!$addr) continue;
                                $outVal = $out['value_sats'] ?? $out['value'] ?? $out['amount'] ?? 0;
                                $valStr = number_format($outVal / 100000000, 8, '.', '');

                                if ($isInc) {
                                    if (isset($receiving[$addr]) || isset($change[$addr])) $cleanOutputs[] = ['address' => $addr, 'value' => $valStr, 'label' => 'Tvá přijímací adresa'];
                                } else {
                                    if (isset($change[$addr])) $cleanOutputs[] = ['address' => $addr, 'value' => $valStr, 'label' => 'Vrácené drobné zpět tobě'];
                                    else $cleanOutputs[] = ['address' => $addr, 'value' => $valStr, 'label' => 'Příjemce platby'];
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Ignorujeme detaily, pokud selže dekódování jedné konkrétní transakce
                }

                $finalTxs[] = [
                    'txid' => $txid, 'isInc' => $isInc,
                    'valStr' => ($isInc ? '+' : '') . number_format($valNum, 8, '.', ''),
                    'confText' => $confs > 0 ? "{$confs}× potvrzeno" : 'Čeká v síti',
                    'timeStr' => $timeStr, 'outputs' => $cleanOutputs
                ];
            }
            return array_reverse($finalTxs);
        } catch (Exception $e) {
            return [];
        }
    }

    public function getRecommendedFees(): array
    {
        $default = ['low' => 1, 'med' => 1, 'high' => 1];
        $ctx = stream_context_create(['http' => ['timeout' => 2]]); 
        $mempool = @file_get_contents('https://mempool.space/api/v1/fees/recommended', false, $ctx);
        if ($mempool && $fees = json_decode($mempool, true)) {
            return [
                'low'  => $fees['hourFee'] ?? 1,
                'med'  => $fees['halfHourFee'] ?? 1,
                'high' => $fees['fastestFee'] ?? 1
            ];
        }
        return $default;
    }

    public function getFiatPrice(string $currency = 'CZK'): float
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $data = @file_get_contents('https://blockchain.info/ticker', false, $ctx);
        if ($data && $prices = json_decode($data, true)) {
            if (isset($prices[$currency])) return (float)$prices[$currency]['last'];
            elseif (isset($prices['USD'])) return (float)$prices['USD']['last'];
        }
        return 0.0;
    }

    public function executePayment(string $to, int|float|string $amount, ?string $password = null, ?int $fee = null): array
    {
        try {
            $txid = $this->wallet->sendPayment($to, $amount, $password, $fee);
            return ['status' => 'success', 'txid' => $txid, 'message' => 'Odesláno'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function generateNewAddress(): array
    {
        try {
            $addr = $this->wallet->getNewAddress();
            return ['status' => 'success', 'address' => $addr];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Nelze vygenerovat: ' . $e->getMessage()];
        }
    }

    public function exportKeys(string $password): array
    {
        try {
            $seed = $this->wallet->getSeed($password);
            $xprv = '';
            try { $xprv = $this->wallet->getMasterPrivateKey($password); } catch (Exception $e) {}
            return ['status' => 'success', 'seed' => $seed, 'xprv' => $xprv];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    public function getMasterPublicKey(): string
    {
        try { return $this->wallet->getMasterPublicKey(); } catch (Exception $e) { return ''; }
    }
}