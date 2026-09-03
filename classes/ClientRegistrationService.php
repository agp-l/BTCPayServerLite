<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use Throwable;

final class ClientRegistrationService
{
    private AuthManager $auth;
    private ClientDashboardRepository $stores;
    private StoreWalletProvisioner $walletProvisioner;
    private Closure $transactional;

    /** @param callable(callable():mixed):mixed $transactional */
    public function __construct(
        AuthManager $auth,
        ClientDashboardRepository $stores,
        StoreWalletProvisioner $walletProvisioner,
        callable $transactional
    ) {
        $this->auth = $auth;
        $this->stores = $stores;
        $this->walletProvisioner = $walletProvisioner;
        $this->transactional = Closure::fromCallable($transactional);
    }

    /** @return array{user_id:int,store_id:string} */
    public function register(
        string $email,
        string $password,
        string $passwordConfirm,
        string $clientIdentity
    ): array {
        $this->auth->recordRegistrationAttempt($clientIdentity);

        $storeId = 'store_' . bin2hex(random_bytes(16));
        $apiKey = bin2hex(random_bytes(32));
        $walletPath = '';

        try {
            $walletPath = $this->walletProvisioner->provision($storeId);
            $userId = ($this->transactional)(function () use (
                $email,
                $password,
                $passwordConfirm,
                $storeId,
                $apiKey,
                $walletPath
            ): int {
                $userId = $this->auth->registerUser($email, $password, $passwordConfirm);
                $this->stores->assignWallet($userId, $walletPath, time());
                $this->stores->createStore(
                    $userId,
                    $storeId,
                    'My First Store',
                    $apiKey,
                    $walletPath
                );

                return $userId;
            });
            if (!is_int($userId) || $userId < 1) {
                throw new AuthException('Registration cannot be completed at this time. Please try again later.');
            }
        } catch (Throwable $exception) {
            if ($walletPath !== '') {
                try {
                    $this->walletProvisioner->discard($walletPath);
                } catch (Throwable $cleanupException) {
                    error_log('Unused registration wallet cleanup failed: ' . $cleanupException::class);
                }
            }
            if ($exception instanceof AuthException) {
                throw $exception;
            }
            throw new AuthException(
                'Registration cannot be completed at this time. Please try again later.',
                0,
                $exception
            );
        }

        return ['user_id' => $userId, 'store_id' => $storeId];
    }
}
