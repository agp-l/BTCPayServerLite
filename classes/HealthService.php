<?php

declare(strict_types=1);

namespace BtcPayLite;

use PDO;
use Throwable;

/**
 * Diagnostic and health-check service for BTC Pay Lite.
 *
 * Checks database connectivity, Electrum RPC daemon reachability,
 * XPUB secp256k1 crypto readiness, and background worker queue metrics.
 */
class HealthService
{
    private Database $database;
    private ElectrumRPC $rpc;

    public function __construct(Database $database, ElectrumRPC $rpc)
    {
        $this->database = $database;
        $this->rpc = $rpc;
    }

    /**
     * Checks all system components and returns a structured diagnostic report.
     *
     * @return array{
     *   status: string,
     *   database: array<string, mixed>,
     *   electrum: array<string, mixed>,
     *   crypto: array<string, mixed>,
     *   queues: array<string, mixed>,
     *   system: array<string, mixed>,
     *   timestamp: int
     * }
     */
    public function check(): array
    {
        $dbHealth = $this->checkDatabase();
        $electrumHealth = $this->checkElectrum();
        $cryptoHealth = $this->checkCrypto();
        $queuesHealth = $this->checkQueues();
        $systemHealth = $this->checkSystem();

        $overallStatus = ($dbHealth['healthy'] && $cryptoHealth['healthy'])
            ? ($electrumHealth['healthy'] ? 'healthy' : 'degraded')
            : 'unhealthy';

        return [
            'status' => $overallStatus,
            'database' => $dbHealth,
            'electrum' => $electrumHealth,
            'crypto' => $cryptoHealth,
            'queues' => $queuesHealth,
            'system' => $systemHealth,
            'timestamp' => time(),
        ];
    }

    /** @return array<string, mixed> */
    private function checkDatabase(): array
    {
        try {
            $pdo = $this->database->getPdo();
            $stmt = $pdo->query('SELECT 1');
            $ok = $stmt !== false && $stmt->fetchColumn() === 1;

            // Check core tables
            $tables = ['stores', 'invoices', 'users', 'webhooks', 'webhook_deliveries', 'api_idempotency_keys'];
            $existing = [];
            foreach ($tables as $table) {
                try {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
                    if ($countStmt !== false) {
                        $existing[$table] = (int) $countStmt->fetchColumn();
                    }
                } catch (Throwable) {
                    $existing[$table] = false;
                }
            }

            return [
                'healthy' => $ok,
                'driver' => (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                'server_version' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                'tables' => $existing,
            ];
        } catch (Throwable $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function checkElectrum(): array
    {
        try {
            $version = $this->rpc->callDaemon('version');
            $isDaemonSynced = true;
            try {
                $status = $this->rpc->callDaemon('daemon_status');
                if (is_array($status) && isset($status['blockchainInfo']['synced'])) {
                    $isDaemonSynced = (bool) $status['blockchainInfo']['synced'];
                }
            } catch (Throwable) {
                // Not all electrum versions expose daemon_status
            }

            return [
                'healthy' => true,
                'version' => is_scalar($version) ? (string) $version : 'connected',
                'synced' => $isDaemonSynced,
                'endpoint' => $this->rpc->getEndpoint(),
            ];
        } catch (Throwable $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
                'endpoint' => $this->rpc->getEndpoint(),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function checkCrypto(): array
    {
        $hasGmp = extension_loaded('gmp');
        $hasBcmath = extension_loaded('bcmath');
        $hasOpenssl = extension_loaded('openssl');

        // Test secp256k1 generator point derivation
        $generatorHealthy = false;
        try {
            $point = Secp256k1::generator();
            $generatorHealthy = $point->getX() !== '0';
        } catch (Throwable) {
            $generatorHealthy = false;
        }

        return [
            'healthy' => $generatorHealthy && ($hasGmp || $hasBcmath),
            'gmp' => $hasGmp,
            'bcmath' => $hasBcmath,
            'openssl' => $hasOpenssl,
            'secp256k1' => $generatorHealthy,
        ];
    }

    /** @return array<string, mixed> */
    private function checkQueues(): array
    {
        try {
            $pdo = $this->database->getPdo();
            $pendingDeliveries = 0;
            $failedDeliveries = 0;

            $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM webhook_deliveries GROUP BY status");
            if ($stmt !== false) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['status'] === 'Pending') {
                        $pendingDeliveries = (int) $row['cnt'];
                    } elseif ($row['status'] === 'Failed') {
                        $failedDeliveries = (int) $row['cnt'];
                    }
                }
            }

            $activeInvoices = 0;
            $stmtInv = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status IN ('New', 'Processing')");
            if ($stmtInv !== false) {
                $activeInvoices = (int) $stmtInv->fetchColumn();
            }

            return [
                'pending_webhook_deliveries' => $pendingDeliveries,
                'failed_webhook_deliveries' => $failedDeliveries,
                'active_monitored_invoices' => $activeInvoices,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function checkSystem(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'disk_free_bytes' => function_exists('disk_free_space') ? @disk_free_space(__DIR__) : null,
        ];
    }
}
