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
            throw new AuthException('Client is invalid.');
        }
        $client = $this->users->findClient($userId);
        if ($client === null) {
            throw new AuthException('Client was not found.');
        }

        $walletBalance = null;
        $walletError = null;
        if ($client['wallet_path'] !== null && $this->balanceLoader !== null) {
            try {
                $walletBalance = ($this->balanceLoader)($client['wallet_path']);
            } catch (\Throwable $exception) {
                WalletBalanceError::log($exception, $client['wallet_path']);
                $walletError = WalletBalanceError::message($exception);
            }
        } elseif ($client['wallet_count'] > 1) {
            $walletError = 'Client has multiple historical wallets; assignment must be resolved manually.';
        } else {
            $walletError = 'Client does not yet have an unambiguously assigned wallet.';
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
            throw new AuthException('Client status change is invalid.');
        }
        if (!$this->users->setClientStatus($userId, $status)) {
            throw new AuthException('Client was not found.');
        }
    }

    public function updateEmail(int $userId, string $email): void
    {
        $email = strtolower(trim($email));
        if (
            $userId < 1
            || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new AuthException('Client email is invalid.');
        }
        try {
            if (!$this->users->updateClientEmail($userId, $email)) {
                throw new AuthException('Client was not found.');
            }
        } catch (AuthException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new AuthException('Email is already in use by another account or cannot be saved at this time.', 0, $exception);
        }
    }

    public function revokeSessions(int $userId): void
    {
        if ($userId < 1 || !$this->users->revokeClientSessions($userId)) {
            throw new AuthException('Client was not found.');
        }
    }

    public function adoptSingleWallet(int $userId): void
    {
        if ($userId < 1 || !$this->users->adoptSingleWallet($userId, time())) {
            throw new AuthException(
                'Wallet cannot be assigned automatically. Client must have exactly one historical wallet.'
            );
        }
    }

    public function setWallet(int $userId, string $walletPath): void
    {
        $walletPath = trim($walletPath);
        if ($userId < 1 || $walletPath === '' || strlen($walletPath) > 255 || str_contains($walletPath, "\0")) {
            throw new AuthException('Wallet assignment is invalid.');
        }
        if (!$this->users->setClientWallet($userId, $walletPath, time())) {
            throw new AuthException('Selected wallet does not belong to any store of this client.');
        }
    }
}
