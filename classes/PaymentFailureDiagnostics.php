<?php

declare(strict_types=1);
namespace BtcPayLite;

use PDOException;
use Throwable;

/** Allowlisted diagnostics only: never expose upstream messages, bodies or credentials. */
final class PaymentFailureDiagnostics
{
    public static function code(Throwable $error): string
    {
        for ($i = 0; $error !== null && $i < 8; ++$i, $error = $error->getPrevious()) {
            if ($error instanceof BlockchainProviderException && $error->getReason() !== null) {
                return $error->getReason();
            }
            if ($error instanceof ElectrumRPCException) {
                return match ($error->getType()) {
                    'authentication' => 'rpc_authentication',
                    'transport' => $error->getCurlCode() === 28 ? 'rpc_timeout' : 'rpc_transport',
                    'http' => 'rpc_http',
                    'protocol' => 'rpc_protocol',
                    'remote' => $error->getRpcCode() === -32601 ? 'rpc_method_unavailable' : 'rpc_remote',
                    default => 'rpc_failure',
                };
            }
            if ($error instanceof PDOException) { return 'payment_database'; }
        }
        return 'observation_failed';
    }

    public static function details(Throwable $error): array
    {
        $result = ['reason' => self::code($error)];
        for ($i = 0; $error !== null && $i < 8; ++$i, $error = $error->getPrevious()) {
            if ($error instanceof ElectrumRPCException) {
                $result += ['http_status'=>$error->getHttpStatus(), 'rpc_code'=>$error->getRpcCode(), 'curl_code'=>$error->getCurlCode()];
            }
            if ($error instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/', (string)$error->getCode())) {
                $result['sqlstate'] = (string)$error->getCode();
            }
        }
        return $result;
    }

    public static function hint(?string $code): string
    {
        return match ($code) {
            'cache_directory' => 'Nelze vytvořit adresář blockchain cache. Ověřte práva worker uživatele k var/blockchain nebo BTCPAY_BLOCKCHAIN_CACHE_DIR.',
            'cache_lock_open' => 'Nelze otevřít soubor zámku blockchain cache. Ověřte práva worker i PHP uživatele k adresáři a existujícím souborům.',
            'cache_lock_timeout' => 'Jiný proces drží zámek adresy; nebyla dostupná platná cache.',
            'cache_write' => 'Nelze zapsat blockchain cache. Ověřte oprávnění, volné místo a souborový systém.',
            'upstream_backoff' => 'Krátká prodleva po předchozím selhání RPC; další běh ukáže původní příčinu.',
            'rpc_authentication' => 'Electrum odmítlo RPC přihlášení. Ověřte rpc_user a rpc_pass v konfiguraci.',
            'rpc_transport', 'rpc_timeout' => 'Nelze se spojit s Electrum nebo vypršel čas. Ověřte běh RPC, host a port.',
            'rpc_http', 'rpc_protocol' => 'RPC vrátilo neočekávanou HTTP nebo JSON-RPC odpověď. Ověřte endpoint a kompatibilitu Electrum.',
            'rpc_method_unavailable' => 'RPC nepodporuje getaddressbalance. Ověřte připojení ke správnému Electrum daemonu.',
            'rpc_remote' => 'Electrum odmítlo blockchain příkaz. V journalu je bezpečný číselný RPC kód.',
            'invalid_balance' => 'Electrum vrátilo neplatné údaje zůstatku pro adresu.',
            'payment_database' => 'Selhal databázový zápis kontroly nebo webhook outboxu. Ověřte schéma a SQLSTATE v journalu.',
            'interrupted' => 'Předchozí běh se nedokončil.',
            default => 'Ověření platby selhalo. Podrobnější bezpečný kód je v journalu payment workeru.',
        };
    }
}
