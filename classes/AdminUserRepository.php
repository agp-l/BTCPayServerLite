<?php

declare(strict_types=1);

namespace BtcPayLite;

interface AdminUserRepository
{
    /** @return list<array<string,mixed>> */
    public function listClients(int $limit): array;

    /** @return array<string,mixed>|null */
    public function findClient(int $userId): ?array;

    /** @return list<array<string,mixed>> */
    public function listStores(int $userId): array;

    /** @return list<array<string,mixed>> */
    public function listIntegrations(int $userId): array;

    /** @return list<array<string,mixed>> */
    public function listRequests(int $userId, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function listPayouts(int $userId, int $limit): array;

    public function setClientStatus(int $userId, string $status): bool;

    public function adoptSingleWallet(int $userId, int $assignedAt): bool;

    public function setClientWallet(int $userId, string $walletPath, int $assignedAt): bool;
}
