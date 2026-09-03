<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Encapsulates version-specific JSON-RPC parameter conventions across Electrum versions.
 *
 * Electrum 4.5.x daemons expect 'wallet' containing the loaded wallet path.
 * Newer or custom Electrum daemon builds expect 'wallet_path'.
 */
interface ElectrumRpcDialect
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function walletParams(string $walletPath, array $params): array;

    public function getDialectName(): string;
}

class StandardElectrumRpcDialect implements ElectrumRpcDialect
{
    private string $paramKey;
    private string $dialectName;

    public function __construct(string $paramKey = 'wallet_path', string $dialectName = 'modern')
    {
        $this->paramKey = $paramKey;
        $this->dialectName = $dialectName;
    }

    public static function forVersion(?string $version): self
    {
        if ($version !== null && str_starts_with(trim($version), '4.5.')) {
            return new self('wallet', 'electrum-4.5');
        }

        return new self('wallet_path', 'electrum-modern');
    }

    public function walletParams(string $walletPath, array $params): array
    {
        $params[$this->paramKey] = $walletPath;
        return $params;
    }

    public function getDialectName(): string
    {
        return $this->dialectName;
    }
}
