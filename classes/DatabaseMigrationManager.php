<?php

declare(strict_types=1);
namespace BtcPayLite;
use PDO;
use RuntimeException;
use Throwable;

/** Explicit, reviewed migrations only. Never guesses ALTER statements from schema differences. */
final class DatabaseMigrationManager
{
    private const CATALOG = [
        '001_add_xpub_and_address_source.sql'=>[
            ['stores.address_source','stores.xpub','stores.xpub_script_type','stores.xpub_last_index','invoices.address_source','invoices.address_index','invoices.derivation_path'], ['stores.wallet_path','invoices.btc_address']],
        '002_add_api_idempotency_keys.sql'=>[['api_idempotency_keys'],['stores']],
        '003_add_payment_worker_lease_and_observation.sql'=>[
            ['invoices.payment_processing_token','invoices.payment_processing_until','invoices.last_checked_at','invoices.next_check_at','invoices.confirmed_received_sats|confirmed_balance_sats','invoices.unconfirmed_received_sats|mempool_delta_sats','invoices.#idx_invoices_lease','invoices.#idx_invoices_token'],['invoices.metadata']],
        '004_core_payment_consistency.sql'=>[
            ['invoices.confirmed_balance_sats','invoices.mempool_delta_sats','invoices.payment_observed_at'],
            ['invoices.confirmed_received_sats','invoices.unconfirmed_received_sats','invoices.payment_processing_token','invoices.payment_processing_until','invoices.next_check_at']],
        '005_idempotency_resource_reservation.sql'=>[
            ['api_idempotency_keys.state','api_idempotency_keys.resource_id','api_idempotency_keys.resource_data','api_idempotency_keys.#uq_idempotency_resource'],
            ['api_idempotency_keys.idempotency_key','api_idempotency_keys.request_hash','api_idempotency_keys.response_code','api_idempotency_keys.response_body']],
        '006_shared_xpub_address_sequences.sql'=>[['xpub_address_sequences'],['stores']],
        '007_wallet_receive_ranges.sql'=>[['wallet_receive_ranges'],['xpub_address_sequences','stores']],
        '008_schema_migrations.sql'=>[['schema_migrations'],['users']],
        '009_remembered_logins.sql'=>[['remembered_logins'],['users.session_version']],
    ];

    public function __construct(private PDO $pdo, private string $root) {}

    public function inspect(): array
    {
        $schema = (new InstallationSchema($this->root.'/sql.sql'))->compare($this->pdo);
        $history = [];
        if ($this->exists('schema_migrations')) {
            foreach ($this->pdo->query('SELECT * FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) { $history[$row['migration']]=$row; }
        }
        $migrations = [];
        foreach (glob($this->root.'/migrations/*.sql') ?: [] as $path) {
            $file=basename($path); $sql=file_get_contents($path);
            if (!is_string($sql)) { throw new RuntimeException('Soubor migrace nelze přečíst.'); }
            $checksum=hash('sha256',$sql);
            $state='Manual'; $reason='Historická migrace nebo preflight mimo automatický katalog. Postupujte podle komentářů souboru.';
            if (isset(self::CATALOG[$file])) {
                [$effects,$requirements]=self::CATALOG[$file];
                $present=count(array_filter($effects,fn(string $key): bool=>$this->exists($key)));
                if ($present===count($effects)) {
                    $state='Present'; $reason='Strukturální znaky již existují. Předchozí provedení datových kroků není bez historie doložené.';
                } elseif ($present>0) {
                    $state='Blocked'; $reason='Část změn již existuje. Automatické opakování by mohlo poškodit nebo přepsat data; nejprve zkontrolujte rozpracovanou migraci.';
                } elseif (count(array_filter($requirements,fn(string $key): bool=>$this->exists($key)))!==count($requirements)) {
                    $state='Blocked'; $reason='Chybí předpoklady. Nejprve dokončete předchozí migrace.';
                } else { $state='Pending'; $reason='Připraveno ke spuštění po záloze a zastavení zápisů aplikace.'; }
            }
            $record=$history[$file] ?? null;
            if ($record !== null) {
                if (!hash_equals($record['checksum'],$checksum)) { $state='Blocked'; $reason='Obsah dříve zaznamenané migrace se změnil. Je nutná ruční kontrola.'; }
                elseif ($record['state'] !== 'Applied') { $state='Blocked'; $reason='Předchozí běh byl přerušen nebo selhal. Poslední doložený krok: '.$record['completed_statements'].'. '.$record['error_code']; }
                elseif ($state === 'Present') { $state='Applied'; $reason='Provedení všech kroků zaznamenal tento nástroj.'; }
                else { $state='Blocked'; $reason='Historie hlásí dokončení, ale strukturální znaky nyní chybí.'; }
            }
            $migrations[]=['file'=>$file,'checksum'=>$checksum,'state'=>$state,'reason'=>$reason,'sql'=>$sql];
        }
        $report=['schema'=>$schema,'migrations'=>$migrations];
        $report['plan_hash']=hash('sha256',json_encode($report,JSON_THROW_ON_ERROR));
        return $report;
    }

    public function apply(string $file, string $planHash, int $adminId): void
    {
        if (!isset(self::CATALOG[$file])) { throw new RuntimeException('Migrace není v povoleném katalogu.'); }
        if ($this->pdo->inTransaction()) { throw new RuntimeException('DDL migrace nelze spouštět v transakci.'); }
        $database=(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $lock='schema:'.substr(hash('sha256',$database),0,56);
        $stmt=$this->pdo->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn()!==1) { throw new RuntimeException('Jiný proces právě provádí migraci.'); }
        try {
            $report=$this->inspect();
            if (!hash_equals($report['plan_hash'],$planHash)) { throw new RuntimeException('Schéma nebo plán se změnily. Obnovte stránku a zkontrolujte nový plán.'); }
            $entry=array_values(array_filter($report['migrations'],static fn(array $row): bool=>$row['file']===$file))[0] ?? null;
            if (($entry['state'] ?? null)!=='Pending') { throw new RuntimeException('Tuto migraci nyní nelze bezpečně spustit.'); }
            // DDL implicitly commits. A durable Running record survives crashes and prevents blind replay.
            if (!$this->exists('schema_migrations')) {
                $journal=array_values(array_filter($report['migrations'],static fn(array $row): bool=>$row['file']==='008_schema_migrations.sql'))[0] ?? null;
                if ($journal===null) { throw new RuntimeException('Chybí migrace historie.'); }
                $this->pdo->exec($journal['sql']);
            }
            $stmt=$this->pdo->prepare("INSERT INTO schema_migrations (migration,checksum,state,started_at,admin_id) VALUES (?,?,'Running',?,?)");
            $stmt->execute([$file,$entry['checksum'],time(),$adminId]);
            try {
                $sql=(string)file_get_contents($this->root.'/migrations/'.$file);
                if (!hash_equals($entry['checksum'],hash('sha256',$sql))) { throw new RuntimeException('Soubor migrace se změnil.'); }
                foreach (InstallationManager::splitSqlStatements($sql) as $index=>$statement) {
                    // Only the selected database may be changed. No SQL or path from HTTP is executed.
                    if (preg_match('/\A(?:USE|DROP\s+DATABASE|CREATE\s+DATABASE)\b/i',$statement)) { throw new RuntimeException('Migrace se pokouší změnit cílovou databázi.'); }
                    $this->pdo->exec($statement);
                    $stmt=$this->pdo->prepare('UPDATE schema_migrations SET completed_statements=? WHERE migration=?');
                    $stmt->execute([$index+1,$file]);
                }
                $stmt=$this->pdo->prepare("UPDATE schema_migrations SET state='Applied',finished_at=? WHERE migration=?");
                $stmt->execute([time(),$file]);
            } catch (Throwable $error) {
                $code=$error instanceof \PDOException ? 'SQLSTATE='.(string)$error->getCode().'; driver='.(int)($error->errorInfo[1] ?? 0) : $error::class;
                $stmt=$this->pdo->prepare("UPDATE schema_migrations SET state='Failed',error_code=? WHERE migration=?");
                $stmt->execute([$code,$file]);
                throw new RuntimeException('Migrace selhala ('.$code.'). Dřívější DDL kroky mohou být uložené. Nástroj je nebude automaticky opakovat.',0,$error);
            }
        } finally {
            $stmt=$this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$lock]);
        }
    }

    private function exists(string $key): bool
    {
        [$table,$name]=array_pad(explode('.',$key,2),2,null);
        if ($name===null) {
            $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE='BASE TABLE'");
            $stmt->execute([$table]); return (int)$stmt->fetchColumn()>0;
        }
        if (str_starts_with($name,'#')) {
            $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
            $stmt->execute([$table,substr($name,1)]); return (int)$stmt->fetchColumn()>0;
        }
        $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        foreach (explode('|',$name) as $column) { $stmt->execute([$table,$column]); if ((int)$stmt->fetchColumn()>0) { return true; } }
        return false;
    }
}
