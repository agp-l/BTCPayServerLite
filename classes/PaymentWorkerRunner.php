<?php

declare(strict_types=1);
namespace BtcPayLite;

use Closure;
use RuntimeException;
use Throwable;

/** Shared bounded entrypoint for CLI and explicit admin POST. No shell execution. */
final class PaymentWorkerRunner
{
    private Closure $factory;
    public function __construct(private Database $database, callable $factory)
    {
        $this->factory = Closure::fromCallable($factory);
    }

    public static function fromConfig(Database $database, array $config, bool $manual = false): self
    {
        // The admin request has a short network bound, without changing the RPC dialect.
        $config['rpc_timeout'] = min(30, max(1, (int) ($config['rpc_timeout'] ?? 30)));
        if ($manual) {
            $config['rpc_timeout'] = min(5, max(1, (int) ($config['rpc_timeout'] ?? 30)));
            $config['rpc_connect_timeout'] = min(3, max(1, (int) ($config['rpc_connect_timeout'] ?? 5)));
        }
        return new self($database, static fn (): PaymentWorker => new PaymentWorker($database,
            new ElectrumBlockchainProvider(ElectrumRPCFactory::fromConfig($config)),
            new WebhookDeliveryRepository($database)));
    }

    public function run(string $source): array
    {
        if (!in_array($source, ['cli', 'manual'], true)) { throw new RuntimeException('Unknown payment run source.'); }
        $pdo = $this->database->getPdo();
        if ($pdo->inTransaction()) { throw new RuntimeException('Payment scan cannot run inside a transaction.'); }
        $monitor = new PaymentWorkerMonitor($pdo);
        $lock = $monitor->lockName();
        $stmt = $pdo->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute([$lock]);
        if ((int) $stmt->fetchColumn() !== 1) { return ['busy'=>true, 'reason'=>'running']; }
        try {
            if ($source === 'manual') {
                $row = $pdo->query("SELECT started_at > UNIX_TIMESTAMP()-15 FROM payment_worker_runtime WHERE source='manual'")->fetchColumn();
                if ((bool) $row) { return ['busy'=>true, 'reason'=>'cooldown']; }
            }
            $token = bin2hex(random_bytes(16));
            $monitor->start($source, $token);
            try {
                $worker = ($this->factory)();
                $stats = $worker->run($source === 'manual' ? 20 : 100, $source === 'manual' ? 12 : 45);
            } catch (Throwable $error) {
                $monitor->finish($source, $token, [], 'worker_exception');
                throw $error;
            }
            $monitor->finish($source, $token, $stats, $stats['failed'] > 0 ? 'observation_failed' : null);
            return ['busy'=>false, 'success'=>$stats['failed'] === 0, 'stats'=>$stats];
        } finally {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$lock]);
        }
    }
}
