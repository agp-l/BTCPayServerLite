<?php

declare(strict_types=1);

use BtcPayLite\AuthManager;
use BtcPayLite\AuthUserRepository;
use BtcPayLite\ClientDashboardRepository;
use BtcPayLite\ClientRegistrationService;
use BtcPayLite\StoreWalletProvisioner;

require_once __DIR__ . '/../vendor/autoload.php';

final class RegistrationUserRepositoryFixture implements AuthUserRepository
{
    public function findByEmail(string $email): ?array { return null; }
    public function createClient(string $email, string $passwordHash): int { return 42; }
    public function updatePasswordHash(int $userId, string $passwordHash): void {}
    public function countRecentAttempts(string $identityHash, int $since): int { return 0; }
    public function recordAttempt(string $identityHash, int $attemptedAt): void {}
    public function clearAttempts(string $identityHash): void {}
}

final class RegistrationStoreRepositoryFixture implements ClientDashboardRepository
{
    public ?array $created = null;
    public ?array $assigned = null;
    public bool $fail = false;
    public function fetchSummary(int $userId): array { return ['total_stores' => 0, 'total_invoices' => 0, 'paid_invoices' => 0]; }
    public function fetchStores(int $userId): array { return []; }
    public function findAssignedWallet(int $userId): ?string { return null; }
    public function assignWallet(int $userId, string $walletPath, int $assignedAt): void
    {
        $this->assigned = compact('userId', 'walletPath', 'assignedAt');
    }
    public function fetchInvoices(int $userId, int $limit): array { return []; }
    public function fetchWebhooks(int $userId): array { return []; }
    public function fetchPayouts(int $userId, int $limit): array { return []; }
    public function fetchIntegrations(int $userId): array { return []; }
    public function fetchRequests(int $userId, int $limit): array { return []; }
    public function createStore(int $userId, string $id, string $name, string $apiKey, string $walletPath): void
    {
        if ($this->fail) throw new RuntimeException('Simulated persistence failure.');
        $this->created = compact('userId', 'id', 'name', 'apiKey', 'walletPath');
    }
    public function ownsStore(int $userId, string $storeId): bool { return false; }
    public function findOrCreateWebhook(string $storeId, string $url, int $createdAt): array { return []; }
    public function deleteWebhook(int $userId, string $webhookId): bool { return false; }
}

final class RegistrationWalletProvisionerFixture implements StoreWalletProvisioner
{
    public ?string $discarded = null;
    public function provision(string $storeId): string { return '/wallets/' . $storeId . '_wallet'; }
    public function discard(string $walletPath): void { $this->discarded = $walletPath; }
}

$stores = new RegistrationStoreRepositoryFixture();
$wallets = new RegistrationWalletProvisionerFixture();
$transactions = 0;
$service = new ClientRegistrationService(
    new AuthManager(new RegistrationUserRepositoryFixture()),
    $stores,
    $wallets,
    static function (callable $callback) use (&$transactions): mixed {
        $transactions++;
        return $callback();
    }
);

$registered = $service->register('User@Example.com', 'correct horse', 'correct horse', '127.0.0.1');
if (
    $registered['user_id'] !== 42
    || $stores->created === null
    || $stores->assigned === null
    || $stores->assigned['walletPath'] !== $stores->created['walletPath']
    || $stores->created['userId'] !== 42
    || strlen($stores->created['apiKey']) !== 64
    || $transactions !== 1
) {
    throw new RuntimeException('Registration did not persist one normalized user and store transaction.');
}
echo "[PASS] registers a client and first store in one database transaction\n";

$stores->fail = true;
try {
    $service->register('second@example.com', 'correct horse', 'correct horse', '127.0.0.2');
    throw new RuntimeException('Persistence failure was accepted.');
} catch (\BtcPayLite\AuthException $exception) {
    if ($wallets->discarded === null || !str_contains($wallets->discarded, 'store_')) {
        throw new RuntimeException('Unused registration wallet was not discarded.');
    }
}
echo "[PASS] discards the provisioned wallet after registration persistence failure\n";

echo "2 client registration service tests passed.\n";
