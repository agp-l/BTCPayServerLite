<?php

declare(strict_types=1);
namespace BtcPayLite;
use InvalidArgumentException;
use PDO;

/** Bounded registration of reserved receive ranges in Electrum; never an invoice dependency. */
final class WalletReceiveSyncWorker
{
    public const PENDING_WALLETS_SQL = 'SELECT r.wallet_path FROM wallet_receive_ranges r
            LEFT JOIN xpub_address_sequences s ON s.key_hash=r.key_hash
            WHERE GREATEST(COALESCE(s.next_index,0),r.initial_next_index)>r.registered_next_index OR r.checked_at<?
            ORDER BY r.checked_at,r.wallet_hash LIMIT ?';

    public function __construct(private Database $database, private ElectrumWallet $wallet, private ?WalletLockManager $locks = null)
    { $this->locks ??= new WalletLockManager(); }

    public function run(int $walletLimit = 2, int $maxAddresses = 25, int $budgetSeconds = 10): array
    {
        if ($walletLimit < 1 || $walletLimit > 20) { throw new InvalidArgumentException('Wallet limit must be 1..20.'); }
        $this->validateBudget($maxAddresses, $budgetSeconds);
        $stmt = $this->database->getPdo()->prepare(self::PENDING_WALLETS_SQL);
        $stmt->bindValue(1,time()-300,PDO::PARAM_INT); $stmt->bindValue(2,$walletLimit,PDO::PARAM_INT); $stmt->execute();
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            try { $results[] = $this->synchronizeWallet($path,$maxAddresses,$budgetSeconds); }
            catch (WalletBusyException) { $results[] = ['wallet_hash'=>hash('sha256',$path),'status'=>'busy']; }
            catch (\Throwable $exception) {
                WalletBalanceError::log($exception,$path);
                $results[] = ['wallet_hash'=>hash('sha256',$path),'status'=>'failed','error_type'=>$exception::class];
            }
        }
        return $results;
    }

    public function synchronizeWallet(string $path, int $maxAddresses = 25, int $budgetSeconds = 10): array
    {
        $this->validateBudget($maxAddresses,$budgetSeconds);
        $pdo = $this->database->getPdo();
        if ($pdo->inTransaction()) { throw new \LogicException('Receive synchronization must run outside a DB transaction.'); }
        $row = (new WalletReceiveRegistry($pdo))->resolve($path);
        if ($row === null) { throw new InvalidArgumentException('Wallet has no managed XPUB receive range.'); }
        $path = $row['wallet_path'];
        $stmt = $pdo->prepare('SELECT next_index FROM xpub_address_sequences WHERE key_hash=?');
        $stmt->execute([$row['key_hash']]);
        $target = max((int)$row['initial_next_index'], (int)$stmt->fetchColumn());
        $result = $this->locks->withWalletLock($path,function () use ($path,$row,$target,$maxAddresses,$budgetSeconds): array {
            $deadline = microtime(true)+$budgetSeconds;
            $this->wallet->ensureWalletLoaded($path);
            $public = new ProvisionedWallet($path,$this->wallet->getMasterPublicKey($path));
            if (XpubDerivationIdentity::describe($public->xpub)['id'] !== $row['key_hash'] || $public->scriptType !== $row['script_type']) {
                throw new ElectrumWalletException('Loaded wallet does not match the registered receive branch.', 'receive_sync');
            }
            $addresses = $this->wallet->listAddresses(true,false,$path);
            $this->verify($row,$addresses);
            $known = count($addresses); $created = 0;
            while ($known < $target && $created < $maxAddresses && microtime(true) < $deadline) {
                $address = $this->wallet->getNewAddress($path); ++$created;
                if ($address !== $this->derive($row,$known)) {
                    // Electrum's own synchronizer can extend the gap concurrently.
                    // Reconcile once by reading actual state, never replay a mutation.
                    $addresses = $this->wallet->listAddresses(true,false,$path);
                    $this->verify($row,$addresses);
                    $position = array_search($address,$addresses,true);
                    if ($position === false || $position < $known || $address !== $this->derive($row,$position)) {
                        throw new ElectrumWalletException('Unexpected receive address during range synchronization.', 'receive_sync');
                    }
                    $known = count($addresses);
                } else { ++$known; }
            }
            return ['wallet_hash'=>$row['wallet_hash'],'status'=>$known >= $target ? 'registered' : 'partial',
                'target_next_index'=>$target,'registered_next_index'=>$known,'created'=>$created,'remaining'=>max(0,$target-$known)];
        },0);
        // The daemon is the source of truth after failure/restart. This is only a
        // scheduling hint, never authority to skip the next live wallet read.
        $stmt = $pdo->prepare('UPDATE wallet_receive_ranges SET registered_next_index=?,checked_at=? WHERE wallet_hash=?');
        $stmt->execute([$result['registered_next_index'],time(),$row['wallet_hash']]);
        return $result;
    }

    private function verify(array $row, array $addresses): void
    {
        if ($addresses === []) { return; }
        foreach (array_unique([0,count($addresses)-1]) as $index) {
            if ($addresses[$index] !== $this->derive($row,$index)) {
                throw new ElectrumWalletException('Electrum receive addresses do not match the XPUB branch.', 'receive_sync');
            }
        }
    }

    private function derive(array $row, int $index): string
    {
        $store = new class($index) implements AddressIndexStoreInterface {
            public function __construct(private int $index) {}
            public function reserveNextIndex(string $storeId): int { return $this->index; }
        };
        return (new XpubAddressGenerator($row['xpub'],$store,XpubAddressGenerator::requireScriptType($row['script_type'])))
            ->generateAddress(new AddressGenerationContext('receive-sync'))->getAddress();
    }

    private function validateBudget(int $addresses, int $seconds): void
    {
        if ($addresses < 1 || $addresses > 100 || $seconds < 1 || $seconds > 30) {
            throw new InvalidArgumentException('Sync bounds: 1..100 addresses and 1..30 seconds per wallet plus an in-flight RPC timeout.');
        }
    }
}
