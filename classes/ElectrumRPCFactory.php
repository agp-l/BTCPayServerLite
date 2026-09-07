<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

/** One transport/dialect configuration for HTTP, CLI and standalone integrations. */
final class ElectrumRPCFactory
{
    public static function fromConfig(array $config): ElectrumRPC
    {
        $rpc = new ElectrumRPC(
            (string) ($config['rpc_host'] ?? ''),
            (int) ($config['rpc_port'] ?? 0),
            $config['rpc_user'] ?? null,
            $config['rpc_pass'] ?? null,
            (int) ($config['rpc_timeout'] ?? 30),
            (int) ($config['rpc_connect_timeout'] ?? 5),
            (string) ($config['rpc_scheme'] ?? 'http')
        );
        // Upstream Electrum command wrapper accepts wallet_path; custom adapters
        // using wallet must explicitly configure it. Never retry a mutation to probe.
        $key = $config['rpc_wallet_param_key'] ?? 'wallet_path';
        if (!is_string($key)) {
            throw new InvalidArgumentException('rpc_wallet_param_key must be wallet_path or wallet.');
        }
        $rpc->setWalletParamKey($key);
        return $rpc;
    }
}
