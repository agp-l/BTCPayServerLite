<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;

final class AdminUserService
{
    private AdminUserRepository $users;
    private ?Closure $balanceLoader;

    /** @param callable(string):array{confirmed:float,unconfirmed:float}|null $balanceLoader */
    public function __construct(AdminUserRepository $users, ?callable $balanceLoader = null)
    {
        $this->users = $users;
        $this->balanceLoader = $balanceLoader === null ? null : Closure::fromCallable($balanceLoader);
    }

    /** @return list<array<string,mixed>> */
    public function listClients(): array
    {
        return $this->users->listClients(100);
    }

    /** @return array<string,mixed> */
    public function detail(int $userId): array
    {
        if ($userId < 1) {
            throw new AuthException('Klient není platný.');
        }
        $client = $this->users->findClient($userId);
        if ($client === null) {
            throw new AuthException('Klient nebyl nalezen.');
        }

        $walletBalance = null;
        $walletError = null;
        if ($client['wallet_path'] !== null && $this->balanceLoader !== null) {
            try {
                $walletBalance = ($this->balanceLoader)($client['wallet_path']);
            } catch (\Throwable $exception) {
                $walletError = 'Zůstatek peněženky nyní nelze načíst.';
            }
        } elseif ($client['wallet_count'] > 1) {
            $walletError = 'Klient má více historických peněženek; přiřazení je nutné vyřešit ručně.';
        } else {
            $walletError = 'Klient zatím nemá jednoznačně přiřazenou peněženku.';
        }

        return [
            'client' => $client,
            'stores' => $this->users->listStores($userId),
            'integrations' => $this->users->listIntegrations($userId),
            'requests' => $this->users->listRequests($userId, 100),
            'payouts' => $this->users->listPayouts($userId, 50),
            'wallet_balance' => $walletBalance,
            'wallet_error' => $walletError,
        ];
    }

    public function setStatus(int $userId, string $status): void
    {
        if ($userId < 1 || !in_array($status, ['active', 'suspended'], true)) {
            throw new AuthException('Změna stavu klienta není platná.');
        }
        if (!$this->users->setClientStatus($userId, $status)) {
            throw new AuthException('Klient nebyl nalezen.');
        }
    }

    public function adoptSingleWallet(int $userId): void
    {
        if ($userId < 1 || !$this->users->adoptSingleWallet($userId, time())) {
            throw new AuthException(
                'Peněženku nelze přiřadit automaticky. Klient musí mít právě jednu historickou peněženku.'
            );
        }
    }
}
