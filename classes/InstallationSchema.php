<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;

/** Reads the bundled schema and recognizes a pristine manual import without changing it. */
final class InstallationSchema
{
    private array $statements;
    private array $tables = [];

    public function __construct(string $path)
    {
        $sql = @file_get_contents($path);
        if (!is_string($sql) || trim($sql) === '') {
            throw new InstallerException('Soubor sql.sql je prázdný nebo nečitelný.');
        }
        $this->statements = InstallationManager::splitSqlStatements($sql);
        foreach ($this->statements as $statement) {
            if (!preg_match('/\ACREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([a-z_]+)`?\s*\((.*)\)\s*ENGINE=InnoDB\b/is', $statement, $match)) {
                continue;
            }
            $columns = []; $indexes = [];
            foreach (preg_split('/,\s*\n/', $match[2]) as $definition) {
                $definition = trim($definition);
                if (preg_match('/\A`?([a-z_]+)`?\s+((?:BIGINT|SMALLINT|INT|VARCHAR|CHAR|BINARY|DECIMAL|ENUM|JSON|LONGTEXT|TEXT|TIMESTAMP)(?:\([^)]*\))?(?:\s+UNSIGNED)?)/i', $definition, $column)) {
                    $columns[$column[1]] = [self::type($column[2]), !str_contains(strtoupper($definition), 'NOT NULL')];
                } elseif (preg_match('/\A(PRIMARY KEY|(?:(UNIQUE)\s+)?KEY\s+`?([a-z_]+)`?)\s*\(([^)]+)\)/is', $definition, $index)) {
                    $primary = $index[1] === 'PRIMARY KEY';
                    $indexes[$primary ? 'PRIMARY' : $index[3]] = [
                        $primary || ($index[2] ?? '') === 'UNIQUE',
                        array_map(static fn (string $name): string => trim($name, " `\t\n\r"), explode(',', $index[4])),
                    ];
                }
            }
            if ($columns === [] || !isset($indexes['PRIMARY'])) {
                throw new InstallerException('Formát sql.sql není podporovaný instalátorem.');
            }
            $this->tables[$match[1]] = [$columns, $indexes];
        }
        if (!isset($this->tables['users'], $this->tables['api_idempotency_keys'])) {
            throw new InstallerException('Soubor sql.sql neobsahuje aktuální schéma aplikace.');
        }
    }

    public function tableNames(): array { return array_keys($this->tables); }

    public function import(PDO $pdo): void
    {
        foreach ($this->statements as $statement) { $pdo->exec($statement); }
    }

    public function assertPristineImport(PDO $pdo): void
    {
        $actual = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $names = array_column($actual, 0); sort($names);
        $expected = $this->tableNames(); sort($expected);
        if ($names !== $expected || array_filter($actual, static fn (array $row): bool => $row[1] !== 'BASE TABLE')) {
            throw new InstallerException('Databáze není prázdná ani čistým importem aktuálního sql.sql. Existující instalaci obnovte pomocí původního config.php a migrací.');
        }
        if ($pdo->query('SHOW TRIGGERS')->fetch() !== false) {
            throw new InstallerException('Databáze obsahuje vlastní triggery; instalátor ji nebude měnit.');
        }
        foreach ($this->tables as $table => [$columns, $indexes]) {
            if ($table === 'app_settings') {
                $settings = $pdo->query('SELECT * FROM app_settings')->fetchAll(PDO::FETCH_ASSOC);
                if (count($settings) !== 1 || $settings[0]['setting_key'] !== 'registration_enabled'
                    || $settings[0]['setting_value'] !== '1' || $settings[0]['updated_by'] !== null) {
                    throw new InstallerException('Databáze už obsahuje nastavení aplikace. Obnovte původní config.php.');
                }
            } elseif ($pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1')->fetchColumn() !== false) {
                throw new InstallerException('Databáze už obsahuje data v tabulce ' . $table . '. Instalátor nepřidá nového administrátora do používané databáze; obnovte původní config.php.');
            }
            $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $engine->execute([$table]);
            $foundColumns = [];
            foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $type = self::type($column['Type']);
                // MariaDB exposes its JSON alias as LONGTEXT.
                if (($columns[$column['Field']][0] ?? null) === 'json' && $type === 'longtext') { $type = 'json'; }
                $foundColumns[$column['Field']] = [$type, $column['Null'] === 'YES'];
            }
            $foundIndexes = [];
            foreach ($pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                if ($index['Sub_part'] !== null) { throw new InstallerException('Schéma obsahuje zkrácený index; použijte aktuální sql.sql.'); }
                $foundIndexes[$index['Key_name']][0] = !(bool) $index['Non_unique'];
                $foundIndexes[$index['Key_name']][1][(int) $index['Seq_in_index']] = $index['Column_name'];
            }
            foreach ($foundIndexes as &$index) { ksort($index[1]); $index[1] = array_values($index[1]); }
            unset($index);
            if ($engine->fetchColumn() !== 'InnoDB' || $foundColumns != $columns || $foundIndexes != $indexes) {
                throw new InstallerException('Struktura tabulky ' . $table . ' neodpovídá aktuálnímu sql.sql. Instalátor není migrační nástroj.');
            }
        }
    }

    private static function type(string $type): string
    {
        $type = strtolower(preg_replace('/\s+/', '', $type));
        return preg_replace('/\b(bigint|smallint|int)\([0-9]+\)/', '$1', $type);
    }
}
