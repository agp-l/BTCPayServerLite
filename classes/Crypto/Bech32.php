<?php

declare(strict_types=1);

namespace BtcPayLite\Crypto;

use InvalidArgumentException;

/**
 * Bech32 and Bech32m encoder/decoder complying with BIP173 and BIP350.
 */
class Bech32
{
    public const SPEC_BECH32 = 1;
    public const SPEC_BECH32M = 2;

    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

    /**
     * Encodes a SegWit address (e.g. bc1q... or tb1q...).
     */
    public static function encodeWitness(string $hrp, int $witnessVersion, string $witnessProgram): string
    {
        $hrp = strtolower($hrp);
        $programBytes = array_values(unpack('C*', $witnessProgram));
        $data5bit = self::convertBits($programBytes, 8, 5, true);

        array_unshift($data5bit, $witnessVersion);

        $spec = ($witnessVersion === 0) ? self::SPEC_BECH32 : self::SPEC_BECH32M;

        return self::encode($hrp, $data5bit, $spec);
    }

    /**
     * @param list<int> $values 5-bit integers
     */
    public static function encode(string $hrp, array $values, int $spec = self::SPEC_BECH32): string
    {
        $combined = array_merge($values, self::createChecksum($hrp, $values, $spec));
        $encoded = '';
        foreach ($combined as $v) {
            $encoded .= self::CHARSET[$v];
        }

        return strtolower($hrp) . '1' . $encoded;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    public static function convertBits(array $data, int $fromBits, int $toBits, bool $pad = true): array
    {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $toBits) - 1;
        $maxAcc = (1 << ($fromBits + $toBits - 1)) - 1;

        foreach ($data as $value) {
            if ($value < 0 || ($value >> $fromBits) !== 0) {
                throw new InvalidArgumentException('Invalid bit conversion value.');
            }
            $acc = (($acc << $fromBits) | $value) & $maxAcc;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }

        if ($pad) {
            if ($bits > 0) {
                $ret[] = ($acc << ($toBits - $bits)) & $maxv;
            }
        } elseif ($bits >= $fromBits || ((($acc << ($toBits - $bits)) & $maxv) !== 0)) {
            throw new InvalidArgumentException('Invalid padding in bit conversion.');
        }

        return $ret;
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private static function createChecksum(string $hrp, array $values, int $spec): array
    {
        $values = array_merge(self::hrpExpand($hrp), $values);
        $const = ($spec === self::SPEC_BECH32M) ? 0x2bc830a3 : 1;
        $extended = array_merge($values, [0, 0, 0, 0, 0, 0]);
        $polymod = self::polymod($extended) ^ $const;

        $ret = [];
        for ($i = 0; $i < 6; $i++) {
            $ret[] = ($polymod >> (5 * (5 - $i))) & 31;
        }

        return $ret;
    }

    /**
     * @param list<int> $values
     */
    private static function polymod(array $values): int
    {
        $chk = 1;
        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= self::GENERATOR[$i];
                }
            }
        }

        return $chk;
    }

    /**
     * @return list<int>
     */
    private static function hrpExpand(string $hrp): array
    {
        $ret = [];
        $len = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $ret[] = ord($hrp[$i]) >> 5;
        }
        $ret[] = 0;
        for ($i = 0; $i < $len; $i++) {
            $ret[] = ord($hrp[$i]) & 31;
        }

        return $ret;
    }
}
