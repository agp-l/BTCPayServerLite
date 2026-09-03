<?php

declare(strict_types=1);

namespace BtcPayLite;

use Throwable;

final class WalletBalanceError
{
    public static function message(Throwable $exception): string
    {
        if ($exception instanceof ElectrumRPCException) {
            return match ($exception->getType()) {
                ElectrumRPCException::TYPE_TRANSPORT => 'Electrum RPC is unavailable. Please verify that the daemon is running and reachable on configured host and port.',
                ElectrumRPCException::TYPE_AUTHENTICATION => 'Electrum RPC authentication failed. Check the RPC username and password in configuration.',
                ElectrumRPCException::TYPE_REMOTE => $exception->getRpcMethod() === 'load_wallet'
                    ? 'Electrum cannot open the specified wallet file. Verify the file path and file permissions for the Electrum process.'
                    : 'Electrum rejected the balance request.',
                default => 'Electrum returned an invalid response while fetching balance.',
            };
        }
        if ($exception instanceof ElectrumWalletException && $exception->getOperation() === 'load_wallet') {
            return 'Failed to load assigned wallet in Electrum. Verify the wallet file path.';
        }
        return 'Wallet balance cannot be retrieved at this moment. Details have been logged.';
    }

    public static function log(Throwable $exception, string $walletPath): void
    {
        $context = $exception::class;
        if ($exception instanceof ElectrumRPCException) {
            $context .= sprintf(
                ' type=%s method=%s http=%s rpc=%s curl=%s',
                $exception->getType(),
                $exception->getRpcMethod(),
                $exception->getHttpStatus() ?? '-',
                $exception->getRpcCode() ?? '-',
                $exception->getCurlCode() ?? '-'
            );
        }
        error_log(sprintf(
            'Wallet balance load failed: wallet=%s error=%s',
            substr(hash('sha256', $walletPath), 0, 12),
            $context
        ));
    }
}
