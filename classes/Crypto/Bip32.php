<?php

declare(strict_types=1);

namespace BtcPayLite\Crypto;

use InvalidArgumentException;

/**
 * Pure PHP BIP32 Extended Public Key representation and deterministic derivation.
 */
class Bip32
{
    // Extended Public Key Version Bytes
    public const VERSION_XPUB = '0488b21e'; // Mainnet P2PKH / generic
    public const VERSION_YPUB = '049d7cb2'; // Mainnet P2SH-P2WPKH
    public const VERSION_ZPUB = '04b24746'; // Mainnet P2WPKH
    public const VERSION_TPUB = '043587cf'; // Testnet P2PKH / generic
    public const VERSION_UPUB = '044a5262'; // Testnet P2SH-P2WPKH
    public const VERSION_VPUB = '045f1cf6'; // Testnet P2WPKH

    private string $versionHex;
    private int $depth;
    private string $parentFingerprint;
    private int $childNumber;
    private string $chainCode;
    private string $pubkey;

    public function __construct(
        string $versionHex,
        int $depth,
        string $parentFingerprint,
        int $childNumber,
        string $chainCode,
        string $pubkey
    ) {
        $this->versionHex = strtolower($versionHex);
        $this->depth = $depth;
        $this->parentFingerprint = $parentFingerprint;
        $this->childNumber = $childNumber;
        $this->chainCode = $chainCode;
        $this->pubkey = $pubkey;
    }

    /**
     * Parses an extended public key string (xpub/ypub/zpub/tpub/upub/vpub).
     */
    public static function fromExtendedKey(string $key): self
    {
        $payload = Base58::decodeCheck(trim($key));
        if (strlen($payload) !== 78) {
            throw new InvalidArgumentException('Extended key must be exactly 78 bytes.');
        }

        $versionHex = bin2hex(substr($payload, 0, 4));
        $depth = ord($payload[4]);
        $parentFingerprint = substr($payload, 5, 4);
        $childNumber = unpack('N', substr($payload, 9, 4))[1];
        $chainCode = substr($payload, 13, 32);
        $pubkey = substr($payload, 45, 33);

        $prefix = ord($pubkey[0]);
        if ($prefix !== 2 && $prefix !== 3) {
            throw new InvalidArgumentException('Extended key must contain a compressed public key.');
        }

        return new self($versionHex, $depth, $parentFingerprint, $childNumber, $chainCode, $pubkey);
    }

    /**
     * Derives non-hardened child key at given index (0 <= index < 2^31).
     */
    public function deriveChild(int $index): self
    {
        if ($index < 0 || $index >= 0x80000000) {
            throw new InvalidArgumentException('Cannot derive hardened child from extended public key.');
        }

        // HMAC-SHA512(Key = chainCode, Data = pubkey || ser32(index))
        $data = $this->pubkey . pack('N', $index);
        $i = hash_hmac('sha512', $data, $this->chainCode, true);

        $iL = substr($i, 0, 32);
        $iR = substr($i, 32, 32);

        // K_i = point(iL) + K_parent
        $childPubkey = Secp256k1::addPublicKeys($this->pubkey, $iL);
        $childFingerprint = substr(hash('ripemd160', hash('sha256', $this->pubkey, true), true), 0, 4);

        return new self(
            $this->versionHex,
            $this->depth + 1,
            $childFingerprint,
            $index,
            $iR,
            $childPubkey
        );
    }

    /**
     * Derives a relative BIP32 path (e.g. '0/5' or '0/0').
     */
    public function derivePath(string $path): self
    {
        $path = trim($path, " \t\n\r\0\x0B/m");
        if ($path === '') {
            return $this;
        }

        $segments = explode('/', $path);
        $current = $this;

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (str_ends_with($segment, "'") || str_ends_with($segment, 'h') || str_ends_with($segment, 'H')) {
                throw new InvalidArgumentException('Cannot derive hardened path segment from public key.');
            }
            if (!ctype_digit($segment)) {
                throw new InvalidArgumentException("Invalid derivation path segment '{$segment}'.");
            }
            $index = (int) $segment;
            $current = $current->deriveChild($index);
        }

        return $current;
    }

    /**
     * Automatically infers script type from version bytes (zpub -> p2wpkh, ypub -> p2sh_p2wpkh, xpub -> default).
     */
    public function inferScriptType(): string
    {
        return match ($this->versionHex) {
            self::VERSION_ZPUB, self::VERSION_VPUB => 'p2wpkh',
            self::VERSION_YPUB, self::VERSION_UPUB => 'p2sh_p2wpkh',
            default => 'p2wpkh', // Native SegWit is default modern standard
        };
    }

    public function isTestnet(): bool
    {
        return in_array($this->versionHex, [self::VERSION_TPUB, self::VERSION_UPUB, self::VERSION_VPUB], true);
    }

    /**
     * Generates a Bitcoin address from the public key.
     *
     * @param string $scriptType 'p2wpkh' | 'p2sh_p2wpkh' | 'p2pkh'
     * @param string $network 'mainnet' | 'testnet'
     */
    public function getAddress(?string $scriptType = null, ?string $network = null): string
    {
        $scriptType = strtolower(trim($scriptType ?? $this->inferScriptType()));
        $isTestnet = $network !== null ? (strtolower($network) === 'testnet') : $this->isTestnet();

        $pubkeyHash = hash('ripemd160', hash('sha256', $this->pubkey, true), true);

        return match ($scriptType) {
            'p2wpkh' => Bech32::encodeWitness($isTestnet ? 'tb' : 'bc', 0, $pubkeyHash),
            'p2sh_p2wpkh', 'p2sh-p2wpkh' => (function () use ($pubkeyHash, $isTestnet) {
                // BIP141/BIP49: redeemScript = 0x0014 <pubkeyHash>
                $redeemScript = "\x00\x14" . $pubkeyHash;
                $scriptHash = hash('ripemd160', hash('sha256', $redeemScript, true), true);
                $versionPrefix = $isTestnet ? "\xc4" : "\x05";
                return Base58::encodeCheck($versionPrefix . $scriptHash);
            })(),
            'p2pkh' => (function () use ($pubkeyHash, $isTestnet) {
                $versionPrefix = $isTestnet ? "\x6f" : "\x00";
                return Base58::encodeCheck($versionPrefix . $pubkeyHash);
            })(),
            default => throw new InvalidArgumentException("Unsupported script type '{$scriptType}'."),
        };
    }

    public function getPublicKeyHex(): string
    {
        return bin2hex($this->pubkey);
    }
}
