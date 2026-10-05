<?php

declare(strict_types=1);

namespace BtcPayLite;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use Throwable;

/**
 * Counts outputs to a unique invoice address, including outputs already spent.
 * Transaction IDs/scripts are verified locally. Confirmation heights still trust
 * the selected Electrum server; walletless history is not an SPV proof.
 */
final class ElectrumReceiptBlockchainProvider extends ElectrumBlockchainProvider
{
    private const MAX_HISTORY = 100;
    private const MAX_NEW_TRANSACTIONS = 2;
    private const MAX_RAW_HEX_BYTES = 2_000_000;
    private const MAX_SATOSHIS = 2_100_000_000_000_000;

    protected function cacheNamespace(): string { return 'receipts-v1'; }

    public function maxObservationDurationSeconds(): int
    {
        // One history + at most two new raw transactions + one current balance.
        return 4 * $this->rpc->getTimeoutSeconds() + 3;
    }

    protected function queryElectrum(string $address): AddressPaymentObservation
    {
        $scriptHex = $this->addressScript($address);
        $history = $this->rpc->callNetwork('getaddresshistory', ['address' => $address]);
        if (!is_array($history) || array_values($history) !== $history || count($history) > self::MAX_HISTORY) {
            throw $this->invalid('invalid_history');
        }
        $heights = [];
        foreach ($history as $row) {
            if (!is_array($row) || !is_string($row['tx_hash'] ?? null)
                || preg_match('/\A[0-9a-fA-F]{64}\z/D', $row['tx_hash']) !== 1
                || !is_int($row['height'] ?? null) || $row['height'] < -1) {
                throw $this->invalid('invalid_history');
            }
            $txid = strtolower($row['tx_hash']);
            if (isset($heights[$txid]) && $heights[$txid] !== $row['height']) {
                throw $this->invalid('invalid_history');
            }
            $heights[$txid] = $row['height']; // Each tx/vout is counted once, even if history repeats it.
        }
        $newTransactions = 0; $incomplete = false; $transactions = [];
        foreach ($heights as $txid => $height) {
            $transaction = $this->cachedTransaction($txid);
            if ($transaction === null) {
                if ($newTransactions >= self::MAX_NEW_TRANSACTIONS) { $incomplete = true; continue; }
                ++$newTransactions;
                $hex = $this->rpc->callNetwork('gettransaction', ['txid' => $txid]);
                $transaction = $this->parseTransaction($hex, $txid);
                $this->saveTransaction($txid, $hex);
            }
            $transactions[$txid] = $transaction;
        }
        // Persisted raw tx cache allows bounded progress on the next scheduled check.
        // Incomplete history must never become a false zero or partial snapshot.
        if ($incomplete) { throw $this->invalid('history_incomplete'); }
        $confirmed = 0; $pending = 0; $spentOutpoints = [];
        foreach ($transactions as $txid => $transaction) {
            $outputs = 0; $inputs = 0;
            foreach ($transaction->getOutputs() as $output) {
                if ($output->getScript()->getHex() === $scriptHex) { $outputs = $this->addSatoshis($outputs, $output->getValue()); }
            }
            foreach ($transaction->getInputs() as $input) {
                $outpoint = $input->getOutPoint(); $previousId = $outpoint->getTxId()->getHex(); $vout = $outpoint->getVout();
                $outpointKey = $previousId . ':' . $vout;
                if ($previousId !== str_repeat('0', 64)) {
                    if (isset($spentOutpoints[$outpointKey])) { throw $this->invalid('invalid_history'); }
                    $spentOutpoints[$outpointKey] = true;
                }
                if (!isset($transactions[$previousId])) { continue; }
                $previousOutput = $transactions[$previousId]->getOutputs()[$vout] ?? null;
                if ($previousOutput === null) { throw $this->invalid('invalid_transaction'); }
                if ($previousOutput->getScript()->getHex() === $scriptHex) {
                    $inputs = $this->addSatoshis($inputs, $previousOutput->getValue());
                }
            }
            // Change returned from this invoice's own inputs is not another payment.
            $received = max(0, $outputs - $inputs);
            if ($heights[$txid] > 0) { $confirmed = $this->addSatoshis($confirmed, $received); }
            else { $pending = $this->addSatoshis($pending, $received); }
        }
        $this->addSatoshis($confirmed, $pending);
        if ($heights === []) {
            return new AddressPaymentObservation($address, 0, 0, 0, time(), 0, 0);
        }
        $balance = parent::queryElectrum($address);
        return new AddressPaymentObservation($address, $balance->getConfirmedBalanceSatoshis(),
            $balance->getMempoolDeltaSatoshis(), $balance->getCurrentBalanceSatoshis(),
            $balance->getObservedAt(), $confirmed, $pending);
    }

    private function addressScript(string $address): string
    {
        $creator = new AddressCreator();
        foreach ([NetworkFactory::bitcoin(), NetworkFactory::bitcoinTestnet(), NetworkFactory::bitcoinRegtest()] as $network) {
            try { return $creator->fromString($address, $network)->getScriptPubKey()->getHex(); }
            catch (Throwable) { /* Try the next Bitcoin address network. */ }
        }
        throw $this->invalid('invalid_address');
    }

    private function addSatoshis(int $total, int $amount): int
    {
        if ($amount < 0 || $amount > self::MAX_SATOSHIS - $total) { throw $this->invalid('invalid_transaction'); }
        return $total + $amount;
    }

    private function parseTransaction(mixed $hex, string $txid): TransactionInterface
    {
        if (!is_string($hex) || $hex === '' || strlen($hex) > self::MAX_RAW_HEX_BYTES
            || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) { throw $this->invalid('invalid_transaction'); }
        try {
            $transaction = TransactionFactory::fromHex($hex);
            if (!hash_equals($txid, $transaction->getTxId()->getHex())
                || strtolower($hex) !== $transaction->getHex()) { throw $this->invalid('invalid_transaction'); }
            return $transaction;
        } catch (Throwable $error) {
            throw new BlockchainProviderException('Invalid payment transaction.', 'observe_address', 503, $error, 'invalid_transaction');
        }
    }

    private function transactionPath(string $txid): string
    {
        return $this->cacheDir . '/tx-' . hash('sha256', $this->rpc->getEndpoint() . '|' . $txid) . '.hex';
    }

    private function cachedTransaction(string $txid): ?TransactionInterface
    {
        $path = $this->transactionPath($txid);
        if (!is_file($path) || filesize($path) > self::MAX_RAW_HEX_BYTES) { return null; }
        try { return $this->parseTransaction(@file_get_contents($path), $txid); }
        catch (Throwable) { return null; }
    }

    private function saveTransaction(string $txid, string $hex): void
    {
        $path = $this->transactionPath($txid); $temp = $path . '.' . bin2hex(random_bytes(8));
        try {
            if (@file_put_contents($temp, $hex) !== strlen($hex) || !@rename($temp, $path)) {
                throw $this->invalid('cache_write');
            }
        } finally { if (is_file($temp)) { @unlink($temp); } }
    }

    private function invalid(string $reason): BlockchainProviderException
    {
        return new BlockchainProviderException('Payment history observation unavailable.', 'observe_address', 503, null, $reason);
    }
}
