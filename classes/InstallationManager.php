<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use PDOException;
use Throwable;

/**
 * Owns the one-time fresh installation of BTCPay Server Lite.
 */
final class InstallationManager
{
    private string $rootDirectory;
    private string $configPath;
    private string $schemaPath;
    private string $lockPath;

    public function __construct(string $rootDirectory)
    {
        $rootDirectory = rtrim($rootDirectory, DIRECTORY_SEPARATOR);
        if ($rootDirectory === '' || !is_dir($rootDirectory)) {
            throw new InstallerException('Kořenový adresář aplikace není dostupný.');
        }

        $this->rootDirectory = $rootDirectory;
        $this->configPath = $rootDirectory . DIRECTORY_SEPARATOR . 'config.php';
        $this->schemaPath = $rootDirectory . DIRECTORY_SEPARATOR . 'sql.sql';
        $this->lockPath = $rootDirectory . DIRECTORY_SEPARATOR . '.install.lock';
    }

    public function isInstalled(): bool
    {
        return is_file($this->configPath);
    }

    /**
     * @return list<array{name:string,ok:bool,detail:string,required:bool}>
     */
    public function requirements(): array
    {
        $pdoMysql = class_exists(PDO::class)
            && extension_loaded('pdo_mysql')
            && in_array('mysql', PDO::getAvailableDrivers(), true);

        return [
            [
                'name' => 'PHP 8.0+',
                'ok' => PHP_VERSION_ID >= 80000,
                'detail' => PHP_VERSION,
                'required' => true,
            ],
            [
                'name' => 'PDO MySQL',
                'ok' => $pdoMysql,
                'detail' => $pdoMysql ? 'Dostupné' : 'Chybí rozšíření pdo_mysql',
                'required' => true,
            ],
            [
                'name' => 'JSON',
                'ok' => extension_loaded('json'),
                'detail' => extension_loaded('json') ? 'Dostupné' : 'Chybí rozšíření json',
                'required' => true,
            ],
            [
                'name' => 'cURL',
                'ok' => extension_loaded('curl'),
                'detail' => extension_loaded('curl') ? 'Dostupné' : 'Chybí rozšíření curl',
                'required' => true,
            ],
            [
                'name' => 'Databázové schéma',
                'ok' => is_file($this->schemaPath) && is_readable($this->schemaPath),
                'detail' => 'sql.sql',
                'required' => true,
            ],
            [
                'name' => 'Zápis konfigurace',
                'ok' => is_writable($this->rootDirectory),
                'detail' => is_writable($this->rootDirectory)
                    ? 'Adresář aplikace je zapisovatelný'
                    : 'Webový server nemůže vytvořit config.php',
                'required' => true,
            ],
        ];
    }

    public function canInstall(): bool
    {
        foreach ($this->requirements() as $requirement) {
            if ($requirement['required'] && !$requirement['ok']) {
                return false;
            }
        }

        return !$this->isInstalled();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{admin_email:string,app_url:string}
     */
    public function install(array $input): array
    {
        if ($this->isInstalled()) {
            throw new InstallerException('Aplikace už je nainstalovaná.');
        }
        if (!$this->canInstall()) {
            throw new InstallerException('Server nesplňuje všechny požadavky instalace.');
        }

        $lockHandle = @fopen($this->lockPath, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            throw new InstallerException('Právě probíhá jiná instalace. Zkuste to za chvíli znovu.');
        }

        $temporaryConfig = null;
        $databaseCreated = false;
        $databaseWasEmpty = false;
        $database = null;

        try {
            if ($this->isInstalled()) {
                throw new InstallerException('Aplikace už je nainstalovaná.');
            }

            $values = $this->validateInput($input);
            $config = $this->buildConfig($values);
            $temporaryConfig = $this->prepareConfigFile($config);

            $server = $this->connectDatabaseServer($values);
            $databaseExisted = $this->databaseExists($server, $values['db_name']);
            if (!$databaseExisted) {
                if (!$values['create_database']) {
                    throw new InstallerException(
                        'Databáze neexistuje. Povolte její vytvoření nebo ji nejprve vytvořte ručně.'
                    );
                }
                $server->exec(sprintf(
                    'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '``', $values['db_name'])
                ));
                $databaseCreated = true;
            }

            $database = $this->connectTargetDatabase($values);
            $databaseWasEmpty = $this->databaseIsEmpty($database);
            if (!$databaseWasEmpty) {
                throw new InstallerException(
                    'Zvolená databáze není prázdná. Pro novou instalaci použijte prázdnou databázi.'
                );
            }

            $schema = file_get_contents($this->schemaPath);
            if (!is_string($schema) || trim($schema) === '') {
                throw new InstallerException('Databázové schéma je prázdné nebo nečitelné.');
            }
            foreach (self::splitSqlStatements($schema) as $statement) {
                $database->exec($statement);
            }

            $passwordHash = password_hash($values['admin_password'], PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new InstallerException('Heslo administrátora se nepodařilo bezpečně uložit.');
            }
            $statement = $database->prepare(
                "INSERT INTO users (email, password_hash, role, status) VALUES (?, ?, 'admin', 'active')"
            );
            $statement->execute([$values['admin_email'], $passwordHash]);

            if (!@rename($temporaryConfig, $this->configPath)) {
                throw new InstallerException('Nepodařilo se dokončit zápis config.php.');
            }
            $temporaryConfig = null;
            @chmod($this->configPath, 0600);

            return [
                'admin_email' => $values['admin_email'],
                'app_url' => $values['app_url'],
            ];
        } catch (InstallerException $exception) {
            if ($database instanceof PDO && $databaseWasEmpty) {
                $this->removeInstalledSchema($database);
            }
            if ($databaseCreated) {
                $this->dropDatabaseAfterFailure($input);
            }
            throw $exception;
        } catch (PDOException $exception) {
            if ($database instanceof PDO && $databaseWasEmpty) {
                $this->removeInstalledSchema($database);
            }
            if ($databaseCreated) {
                $this->dropDatabaseAfterFailure($input);
            }
            throw new InstallerException(
                'Databázovou instalaci se nepodařilo dokončit. Ověřte přístupové údaje a oprávnění.',
                previous: $exception
            );
        } catch (Throwable $exception) {
            if ($database instanceof PDO && $databaseWasEmpty) {
                $this->removeInstalledSchema($database);
            }
            if ($databaseCreated) {
                $this->dropDatabaseAfterFailure($input);
            }
            throw new InstallerException(
                'Instalaci se nepodařilo dokončit. Zkontrolujte serverový log.',
                previous: $exception
            );
        } finally {
            if (is_string($temporaryConfig) && is_file($temporaryConfig)) {
                @unlink($temporaryConfig);
            }
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($this->lockPath);
        }
    }

    /**
     * Splits the project's plain DDL file without breaking quoted semicolons.
     *
     * @return list<string>
     */
    public static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                    $buffer .= $character;
                }
                continue;
            }
            if ($blockComment) {
                if ($character === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote === null && $character === '-' && $next === '-'
                && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) {
                $lineComment = true;
                $index++;
                continue;
            }
            if ($quote === null && $character === '#') {
                $lineComment = true;
                continue;
            }
            if ($quote === null && $character === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }

            if ($quote !== null) {
                $buffer .= $character;
                if ($character === '\\' && $quote !== '`' && $next !== '') {
                    $buffer .= $next;
                    $index++;
                    continue;
                }
                if ($character === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $buffer .= $character;
                continue;
            }
            if ($character === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $character;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    /** @param array<string, mixed> $input */
    private function validateInput(array $input): array
    {
        $dbHost = $this->host($input['db_host'] ?? null, 'Host databáze');
        $dbPort = $this->port($input['db_port'] ?? null, 'Port databáze');
        $dbName = trim(is_string($input['db_name'] ?? null) ? $input['db_name'] : '');
        if (!preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/D', $dbName)) {
            throw new InstallerException('Název databáze může obsahovat pouze písmena, čísla, _, $ a -.');
        }
        $dbUser = trim(is_string($input['db_user'] ?? null) ? $input['db_user'] : '');
        if ($dbUser === '' || strlen($dbUser) > 128 || str_contains($dbUser, "\0")) {
            throw new InstallerException('Uživatel databáze není platný.');
        }
        $dbPassword = is_string($input['db_pass'] ?? null) ? $input['db_pass'] : '';
        if (str_contains($dbPassword, "\0")) {
            throw new InstallerException('Heslo databáze není platné.');
        }

        $adminEmail = strtolower(trim(
            is_string($input['admin_email'] ?? null) ? $input['admin_email'] : ''
        ));
        if (strlen($adminEmail) > 254 || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InstallerException('E-mail administrátora není platný.');
        }
        $adminPassword = is_string($input['admin_password'] ?? null)
            ? $input['admin_password']
            : '';
        if (strlen($adminPassword) < 12 || strlen($adminPassword) > 72) {
            throw new InstallerException('Heslo administrátora musí mít 12 až 72 znaků.');
        }
        $adminPasswordConfirm = is_string($input['admin_password_confirm'] ?? null)
            ? $input['admin_password_confirm']
            : '';
        if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
            throw new InstallerException('Hesla administrátora se neshodují.');
        }

        $appUrl = $this->appUrl($input['app_url'] ?? null);
        $rpcHost = $this->host($input['rpc_host'] ?? '127.0.0.1', 'Host Electrum RPC');
        $rpcPort = $this->port($input['rpc_port'] ?? 7777, 'Port Electrum RPC');
        $passwordResetFrom = trim(
            is_string($input['password_reset_from'] ?? null) ? $input['password_reset_from'] : ''
        );
        if ($passwordResetFrom !== ''
            && filter_var($passwordResetFrom, FILTER_VALIDATE_EMAIL) === false) {
            throw new InstallerException('Adresa odesílatele pro obnovu hesla není platná.');
        }

        return [
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_pass' => $dbPassword,
            'create_database' => ($input['create_database'] ?? null) === '1'
                || ($input['create_database'] ?? null) === true,
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'app_url' => $appUrl,
            'rpc_host' => $rpcHost,
            'rpc_port' => $rpcPort,
            'rpc_user' => is_string($input['rpc_user'] ?? null) ? trim($input['rpc_user']) : '',
            'rpc_pass' => is_string($input['rpc_pass'] ?? null) ? $input['rpc_pass'] : '',
            'password_reset_from' => $passwordResetFrom,
            'wallet_path' => $this->absolutePath($input['wallet_path'] ?? null, 'Admin peněženka'),
            'electrum_cli_path' => $this->absolutePath(
                $input['electrum_cli_path'] ?? null,
                'Electrum CLI'
            ),
            'electrum_data_dir' => $this->absolutePath(
                $input['electrum_data_dir'] ?? null,
                'Datový adresář Electrum'
            ),
            'store_wallets_dir' => $this->absolutePath(
                $input['store_wallets_dir'] ?? null,
                'Adresář peněženek obchodů'
            ),
        ];
    }

    /** @param array<string, mixed> $values */
    private function buildConfig(array $values): array
    {
        return [
            'rpc_host' => $values['rpc_host'],
            'rpc_port' => $values['rpc_port'],
            'rpc_user' => $values['rpc_user'],
            'rpc_pass' => $values['rpc_pass'],
            'db_host' => $values['db_host'],
            'db_port' => $values['db_port'],
            'db_name' => $values['db_name'],
            'db_user' => $values['db_user'],
            'db_pass' => $values['db_pass'],
            'admin_api_key' => bin2hex(random_bytes(32)),
            'secret_key' => bin2hex(random_bytes(32)),
            'cron_key' => bin2hex(random_bytes(32)),
            'app_url' => $values['app_url'],
            'password_reset_from' => $values['password_reset_from'],
            'wallet_path' => $values['wallet_path'],
            'electrum_cli_path' => $values['electrum_cli_path'],
            'electrum_data_dir' => $values['electrum_data_dir'],
            'store_wallets_dir' => $values['store_wallets_dir'],
            'allow_local_webhooks' => false,
            'exchange_fee_bps' => 200,
            'payout_api_enabled' => false,
            'payout_api_keys' => [],
            'payout_wallet_passwords' => [],
            'payout_max_btc' => '0.01000000',
            'payout_daily_limit_btc' => '0.05000000',
            'api_clients' => [],
        ];
    }

    private function prepareConfigFile(array $config): string
    {
        $path = tempnam($this->rootDirectory, '.btcpay-config-');
        if ($path === false) {
            throw new InstallerException('Do adresáře aplikace nelze zapsat dočasnou konfiguraci.');
        }
        $content = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Generated by the BTCPay Server Lite installer. Keep this file private.\n"
            . 'return ' . var_export($config, true) . ";\n";
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            @unlink($path);
            throw new InstallerException('Dočasnou konfiguraci se nepodařilo zapsat.');
        }
        @chmod($path, 0600);

        return $path;
    }

    /** @param array<string, mixed> $values */
    private function connectDatabaseServer(array $values): PDO
    {
        return $this->createPdo(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $values['db_host'], $values['db_port']),
            $values['db_user'],
            $values['db_pass']
        );
    }

    /** @param array<string, mixed> $values */
    private function connectTargetDatabase(array $values): PDO
    {
        return $this->createPdo(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $values['db_host'],
                $values['db_port'],
                $values['db_name']
            ),
            $values['db_user'],
            $values['db_pass']
        );
    }

    private function createPdo(string $dsn, string $user, string $password): PDO
    {
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    private function databaseExists(PDO $server, string $databaseName): bool
    {
        $statement = $server->prepare(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
        );
        $statement->execute([$databaseName]);

        return $statement->fetchColumn() !== false;
    }

    private function databaseIsEmpty(PDO $database): bool
    {
        $statement = $database->query('SHOW TABLES');

        return $statement->fetchColumn() === false;
    }

    private function removeInstalledSchema(PDO $database): void
    {
        try {
            $database->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ([
                'store_integrations', 'api_request_log', 'webhook_deliveries', 'webhooks',
                'payouts', 'invoices', 'password_reset_tokens', 'client_wallets', 'stores',
                'auth_attempts', 'app_settings', 'users',
            ] as $table) {
                $database->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
            $database->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $cleanupFailure) {
            error_log('Installer schema cleanup failed: ' . $cleanupFailure->getMessage());
        }
    }

    /** @param array<string, mixed> $input */
    private function dropDatabaseAfterFailure(array $input): void
    {
        try {
            $values = $this->validateDatabaseConnectionInput($input);
            $server = $this->connectDatabaseServer($values);
            $server->exec('DROP DATABASE `' . str_replace('`', '``', $values['db_name']) . '`');
        } catch (Throwable $cleanupFailure) {
            error_log('Installer database cleanup failed: ' . $cleanupFailure->getMessage());
        }
    }

    /** @param array<string, mixed> $input */
    private function validateDatabaseConnectionInput(array $input): array
    {
        $databaseName = trim(is_string($input['db_name'] ?? null) ? $input['db_name'] : '');
        if (!preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/D', $databaseName)) {
            throw new InstallerException('Název databáze není platný.');
        }

        return [
            'db_host' => $this->host($input['db_host'] ?? null, 'Host databáze'),
            'db_port' => $this->port($input['db_port'] ?? null, 'Port databáze'),
            'db_name' => $databaseName,
            'db_user' => is_string($input['db_user'] ?? null) ? trim($input['db_user']) : '',
            'db_pass' => is_string($input['db_pass'] ?? null) ? $input['db_pass'] : '',
        ];
    }

    private function host(mixed $value, string $label): string
    {
        $host = trim(is_string($value) ? $value : '');
        if ($host === '' || strlen($host) > 253
            || !preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $host)) {
            throw new InstallerException($label . ' není platný.');
        }

        return $host;
    }

    private function port(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $port = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $port = (int) $value;
        } else {
            throw new InstallerException($label . ' není platný.');
        }
        if ($port < 1 || $port > 65_535) {
            throw new InstallerException($label . ' musí být v rozsahu 1 až 65535.');
        }

        return $port;
    }

    private function appUrl(mixed $value): string
    {
        $url = rtrim(trim(is_string($value) ? $value : ''), '/');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InstallerException('Veřejná URL aplikace není platná.');
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InstallerException('Veřejná URL smí obsahovat jen HTTP(S) origin a podadresář.');
        }

        return $url;
    }

    private function absolutePath(mixed $value, string $label): string
    {
        $path = trim(is_string($value) ? $value : '');
        $windowsAbsolute = preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1;
        if ($path === '' || ($path[0] !== '/' && !$windowsAbsolute) || str_contains($path, "\0")) {
            throw new InstallerException($label . ' musí být absolutní cesta.');
        }

        return rtrim($path, '/\\');
    }
}
