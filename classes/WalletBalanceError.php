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
                ElectrumRPCException::TYPE_TRANSPORT => 'Electrum RPC není dostupné. Zkontrolujte, zda daemon běží a odpovídá na nastaveném hostu a portu.',
                ElectrumRPCException::TYPE_AUTHENTICATION => 'Electrum RPC odmítlo přihlášení. Zkontrolujte RPC uživatele a heslo v konfiguraci.',
                ElectrumRPCException::TYPE_REMOTE => $exception->getRpcMethod() === 'load_wallet'
                    ? 'Electrum nemůže otevřít přiřazený soubor peněženky. Ověřte jeho cestu a oprávnění procesu Electrum.'
                    : 'Electrum odmítlo požadavek na načtení zůstatku.',
                default => 'Electrum vrátilo neplatnou odpověď při načítání zůstatku.',
            };
        }
        if ($exception instanceof ElectrumWalletException && $exception->getOperation() === 'load_wallet') {
            return 'Přiřazenou peněženku se nepodařilo v Electrum načíst. Ověřte cestu k souboru.';
        }
        return 'Zůstatek peněženky nyní nelze načíst. Podrobnost je zaznamenaná v serverovém logu.';
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
