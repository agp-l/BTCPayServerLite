<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/AuthException.php';
require_once __DIR__ . '/../classes/AdminUserRepository.php';
require_once __DIR__ . '/../classes/AdminUserService.php';
require_once __DIR__ . '/../classes/WalletBalanceError.php';

use BtcPayLite\AdminUserRepository;
use BtcPayLite\AdminUserService;

final class AdminUserRepositoryFixture implements AdminUserRepository
{
    public string $status = 'active';
    public string $email = 'client@example.test';
    public ?string $assignedWallet = null;
    public bool $sessionsRevoked = false;
    public function listClients(int $limit): array { return [$this->client()]; }
    public function findClient(int $userId): ?array { return $userId === 7 ? $this->client() : null; }
    public function listStores(int $userId): array { return [['id' => 'store_1']]; }
    public function listIntegrations(int $userId): array { return [['name' => 'WooCommerce']]; }
    public function listRequests(int $userId, int $limit): array { return [['http_status' => 200]]; }
    public function listPayouts(int $userId, int $limit): array { return [['state' => 'Completed']]; }
    public function setClientStatus(int $userId, string $status): bool
    {
        if ($userId !== 7) return false;
        $this->status = $status;
        return true;
    }
    public function updateClientEmail(int $userId, string $email): bool
    {
        if ($userId !== 7) return false;
        $this->email = $email;
        return true;
    }
    public function revokeClientSessions(int $userId): bool
    {
        if ($userId !== 7) return false;
        $this->sessionsRevoked = true;
        return true;
    }
    public function adoptSingleWallet(int $userId, int $assignedAt): bool { return $userId === 7; }
    public function setClientWallet(int $userId, string $walletPath, int $assignedAt): bool
    {
        if ($userId !== 7 || $walletPath !== '/wallets/client') return false;
        $this->assignedWallet = $walletPath;
        return true;
    }
    private function client(): array
    {
        return [
            'id' => 7,
            'email' => $this->email,
            'status' => $this->status,
            'wallet_path' => '/wallets/client',
            'wallet_count' => 1,
        ];
    }
}

$repository = new AdminUserRepositoryFixture();
$loadedWallet = null;
$service = new AdminUserService(
    $repository,
    static function (string $walletPath) use (&$loadedWallet): array {
        $loadedWallet = $walletPath;
        return ['confirmed' => 1.25, 'unconfirmed' => 0.1];
    }
);
$detail = $service->detail(7);
if (
    $loadedWallet !== '/wallets/client'
    || $detail['wallet_balance']['confirmed'] !== 1.25
    || count($detail['requests']) !== 1
) {
    throw new RuntimeException('Admin detail did not compose scoped client data.');
}
$service->setStatus(7, 'suspended');
if ($repository->status !== 'suspended') {
    throw new RuntimeException('Client status was not changed.');
}
$service->updateEmail(7, ' New.Client@Example.Test ');
$service->revokeSessions(7);
if ($repository->email !== 'new.client@example.test' || !$repository->sessionsRevoked) {
    throw new RuntimeException('Client account access was not managed.');
}
$service->adoptSingleWallet(7);
$service->setWallet(7, '/wallets/client');
if ($repository->assignedWallet !== '/wallets/client') {
    throw new RuntimeException('Explicit client wallet assignment failed.');
}

echo '[PASS] composes client operations and live wallet balance' . PHP_EOL;
echo '[PASS] changes only a validated client status' . PHP_EOL;
echo '[PASS] updates a validated email and revokes sessions' . PHP_EOL;
echo '[PASS] adopts only an unambiguous historical wallet' . PHP_EOL;
echo '[PASS] assigns only a wallet owned by the client' . PHP_EOL;
echo '5 AdminUserService tests passed.' . PHP_EOL;
