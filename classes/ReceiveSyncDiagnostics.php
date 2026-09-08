<?php

declare(strict_types=1);
namespace BtcPayLite;

use PDO;
use PDOException;
use Throwable;

/** Read-only CLI diagnosis. Never prints credentials, SQL values or raw RPC responses. */
final class ReceiveSyncDiagnostics
{
    private const REQUIRED_COLUMNS = [
        'xpub_address_sequences' => ['key_hash','next_index'],
        'wallet_receive_ranges' => ['wallet_hash','wallet_path','key_hash','xpub','script_type',
            'initial_next_index','registered_next_index','checked_at'],
        'stores' => ['wallet_path','address_source','xpub','xpub_script_type','xpub_last_index'],
    ];
    private const MIGRATIONS = [
        'xpub_address_sequences' => 'migrations/006_shared_xpub_address_sequences.sql',
        'wallet_receive_ranges' => 'migrations/007_wallet_receive_ranges.sql',
    ];

    public static function inspect(PDO $pdo): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,COLLATION_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('xpub_address_sequences','wallet_receive_ranges','stores')");
        $found = []; $collations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $found[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
            if ($column['COLUMN_NAME'] === 'key_hash') { $collations[$column['TABLE_NAME']] = $column['COLLATION_NAME']; }
        }
        $missingTables = []; $missingColumns = []; $migrations = [];
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            if (!isset($found[$table])) {
                $missingTables[] = $table;
                if (isset(self::MIGRATIONS[$table])) { $migrations[] = self::MIGRATIONS[$table]; }
            } else {
                $missing = array_values(array_diff($columns,$found[$table]));
                if ($missing !== []) { $missingColumns[$table] = $missing; }
            }
        }
        $ready = $missingTables === [] && $missingColumns === [];
        if ($ready) {
            // Validate the real worker JOIN/permissions/dialect with a zero-row read.
            $probe = $pdo->prepare(WalletReceiveSyncWorker::PENDING_WALLETS_SQL);
            $probe->bindValue(1,time(),PDO::PARAM_INT); $probe->bindValue(2,0,PDO::PARAM_INT); $probe->execute();
        }
        return ['ok'=>$ready,'database'=>$database,'php_binary'=>PHP_BINARY,'php_version'=>PHP_VERSION,
            'php_ini'=>php_ini_loaded_file() ?: null,'server_version'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'missing_tables'=>$missingTables,'missing_columns'=>$missingColumns,'migration_files'=>$migrations,
            'key_hash_collations'=>$collations,
            'action'=>$ready ? 'Database checks passed; no Electrum RPC was performed.'
                : 'Check that config.php selects the intended database. Import listed migrations into that database. Missing columns in existing tables need a schema repair; CREATE TABLE IF NOT EXISTS will not add them. Do not import all of sql.sql into a populated database.'];
    }

    public static function error(Throwable $exception): string
    {
        for ($cause=$exception; $cause!==null; $cause=$cause->getPrevious()) {
            if (!$cause instanceof PDOException) { continue; }
            $state = (string) ($cause->errorInfo[0] ?? $cause->getCode());
            if (!preg_match('/\A[A-Z0-9]{5}\z/D',$state)) { $state='unknown'; }
            $code = (int) ($cause->errorInfo[1] ?? 0);
            $detail = match ($code) {
                1146 => 'A required table is missing. Run --check-db; check migrations 006 and 007 in the database selected by config.php.',
                1054 => 'A required column is missing. Run --check-db and compare the existing table with its migration.',
                1044,1045,1142,1143 => 'Database access was denied. Check DB credentials and table permissions in config.php.',
                1049 => 'The configured database does not exist. Check db_name in config.php.',
                2002,2003,2006,2013 => 'Database connection failed or was lost. Check MySQL host/port/service and the PHP CLI configuration.',
                1064 => 'The database rejected SQL syntax. Check the database server version and application revision.',
                1267,1271 => 'Database column collations are incompatible. Compare key_hash definitions in migrations 006 and 007.',
                1205,1213 => 'Database lock timeout or deadlock. Retry after competing operations finish.',
                default => 'Database operation failed. Run --check-db and report these SQLSTATE/driver codes.',
            };
            return "Receive synchronization failed: SQLSTATE={$state}; driver_code={$code}. {$detail}";
        }
        if ($exception instanceof \InvalidArgumentException) {
            return 'Receive synchronization failed: ' . $exception->getMessage();
        }
        if ($exception instanceof ElectrumRPCException || $exception instanceof ElectrumWalletException || $exception instanceof WalletBusyException) {
            return 'Receive synchronization failed: ' . WalletBalanceError::message($exception);
        }
        return 'Receive synchronization failed: '.$exception::class.'. Check config.php and PHP CLI requirements; run --check-db.';
    }
}
