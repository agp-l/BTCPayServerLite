<?php

declare(strict_types=1);

namespace BtcPayLite\Crypto;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure PHP Secp256k1 elliptic curve point arithmetic for BIP32 derivation.
 * Uses GMP extension if available, with BCMath fallback.
 */
class Secp256k1
{
    // secp256k1 curve parameters
    private const P_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
    private const N_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
    private const GX_HEX = '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';
    private const GY_HEX = '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';

    /**
     * Derives child public key by adding point(scalarIL) to parent public key point.
     *
     * @param string $parentPubkey33 33-byte compressed public key
     * @param string $scalarIL 32-byte big-endian scalar
     * @return string 33-byte compressed child public key
     */
    public static function addPublicKeys(string $parentPubkey33, string $scalarIL): string
    {
        if (strlen($parentPubkey33) !== 33) {
            throw new InvalidArgumentException('Parent public key must be 33 bytes compressed.');
        }
        if (strlen($scalarIL) !== 32) {
            throw new InvalidArgumentException('Scalar IL must be 32 bytes.');
        }

        if (extension_loaded('gmp')) {
            return self::addGmp($parentPubkey33, $scalarIL);
        }

        if (extension_loaded('bcmath')) {
            return self::addBc($parentPubkey33, $scalarIL);
        }

        throw new RuntimeException('Neither GMP nor BCMath extension is available for secp256k1 point derivation.');
    }

    // =========================================================================
    // GMP Implementation
    // =========================================================================

    private static function addGmp(string $parentPubkey, string $scalarIL): string
    {
        $p = gmp_init('0x' . self::P_HEX);
        $n = gmp_init('0x' . self::N_HEX);
        $gx = gmp_init('0x' . self::GX_HEX);
        $gy = gmp_init('0x' . self::GY_HEX);

        $k = gmp_init('0x' . bin2hex($scalarIL));
        if (gmp_cmp($k, $n) >= 0 || gmp_cmp($k, 0) === 0) {
            throw new InvalidArgumentException('Scalar IL is outside valid range [1, n-1].');
        }

        // Point 1: P1 = scalar * G
        [$x1, $y1] = self::multiplyPointGmp($gx, $gy, $k, $p);

        // Point 2: P2 = decompress(parentPubkey)
        [$x2, $y2] = self::decompressGmp($parentPubkey, $p);

        // Point 3: P3 = P1 + P2
        [$x3, $y3] = self::addPointsGmp($x1, $y1, $x2, $y2, $p);

        if ($x3 === null || $y3 === null) {
            throw new RuntimeException('Resulting public key point is at infinity.');
        }

        // Compress P3
        $prefix = gmp_is_odd($y3) ? "\x03" : "\x02";
        $xHex = str_pad(gmp_strval($x3, 16), 64, '0', STR_PAD_LEFT);

        return $prefix . hex2bin($xHex);
    }

    /**
     * @return array{0: \GMP, 1: \GMP}
     */
    private static function decompressGmp(string $pubkey, \GMP $p): array
    {
        $prefix = ord($pubkey[0]);
        if ($prefix !== 2 && $prefix !== 3) {
            throw new InvalidArgumentException('Public key prefix must be 0x02 or 0x03.');
        }

        $x = gmp_init('0x' . bin2hex(substr($pubkey, 1, 32)));

        // y^2 = x^3 + 7 (mod p)
        $y2 = gmp_mod(gmp_add(gmp_powm($x, gmp_init(3), $p), gmp_init(7)), $p);

        // y = (y^2)^((p + 1) / 4) mod p
        $exp = gmp_div_q(gmp_add($p, gmp_init(1)), gmp_init(4));
        $y = gmp_powm($y2, $exp, $p);

        // Verify y^2 mod p == y2
        if (gmp_cmp(gmp_powm($y, gmp_init(2), $p), $y2) !== 0) {
            throw new InvalidArgumentException('Invalid secp256k1 point x coordinate.');
        }

        $isOdd = gmp_is_odd($y);
        $expectedOdd = ($prefix === 3);
        if ($isOdd !== $expectedOdd) {
            $y = gmp_sub($p, $y);
        }

        return [$x, $y];
    }

    /**
     * @return array{0: \GMP|null, 1: \GMP|null}
     */
    private static function addPointsGmp(\GMP $x1, \GMP $y1, \GMP $x2, \GMP $y2, \GMP $p): array
    {
        if (gmp_cmp($x1, $x2) === 0) {
            if (gmp_cmp($y1, $y2) === 0) {
                // Point doubling
                if (gmp_cmp($y1, 0) === 0) {
                    return [null, null];
                }
                // m = (3 * x1^2) / (2 * y1) mod p
                $num = gmp_mul(gmp_init(3), gmp_powm($x1, gmp_init(2), $p));
                $den = gmp_mul(gmp_init(2), $y1);
                $m = gmp_mod(gmp_mul($num, gmp_invert($den, $p)), $p);
            } else {
                return [null, null];
            }
        } else {
            // m = (y2 - y1) / (x2 - x1) mod p
            $num = gmp_mod(gmp_sub($y2, $y1), $p);
            $den = gmp_mod(gmp_sub($x2, $x1), $p);
            $m = gmp_mod(gmp_mul($num, gmp_invert($den, $p)), $p);
        }

        // x3 = m^2 - x1 - x2 mod p
        $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_powm($m, gmp_init(2), $p), $x1), $x2), $p);
        // y3 = m * (x1 - x3) - y1 mod p
        $y3 = gmp_mod(gmp_sub(gmp_mul($m, gmp_sub($x1, $x3)), $y1), $p);

        if (gmp_cmp($x3, 0) < 0) {
            $x3 = gmp_add($x3, $p);
        }
        if (gmp_cmp($y3, 0) < 0) {
            $y3 = gmp_add($y3, $p);
        }

        return [$x3, $y3];
    }

    /**
     * @return array{0: \GMP, 1: \GMP}
     */
    private static function multiplyPointGmp(\GMP $gx, \GMP $gy, \GMP $k, \GMP $p): array
    {
        $rx = null;
        $ry = null;
        $qx = $gx;
        $qy = $gy;

        $kBin = gmp_strval($k, 2);
        $len = strlen($kBin);

        for ($i = $len - 1; $i >= 0; $i--) {
            if ($kBin[$i] === '1') {
                if ($rx === null) {
                    $rx = $qx;
                    $ry = $qy;
                } else {
                    [$rx, $ry] = self::addPointsGmp($rx, $ry, $qx, $qy, $p);
                }
            }
            [$qx, $qy] = self::addPointsGmp($qx, $qy, $qx, $qy, $p);
        }

        if ($rx === null || $ry === null) {
            throw new RuntimeException('Multiplication resulted in point at infinity.');
        }

        return [$rx, $ry];
    }

    // =========================================================================
    // BCMath Fallback Implementation
    // =========================================================================

    private static function addBc(string $parentPubkey, string $scalarIL): string
    {
        $p = self::hexToBc(self::P_HEX);
        $n = self::hexToBc(self::N_HEX);
        $gx = self::hexToBc(self::GX_HEX);
        $gy = self::hexToBc(self::GY_HEX);

        $k = self::hexToBc(bin2hex($scalarIL));
        if (bccomp($k, $n) >= 0 || bccomp($k, '0') === 0) {
            throw new InvalidArgumentException('Scalar IL outside valid range.');
        }

        [$x1, $y1] = self::multiplyPointBc($gx, $gy, $k, $p);
        [$x2, $y2] = self::decompressBc($parentPubkey, $p);
        [$x3, $y3] = self::addPointsBc($x1, $y1, $x2, $y2, $p);

        if ($x3 === null || $y3 === null) {
            throw new RuntimeException('Resulting point at infinity.');
        }

        $isOdd = (int) bcmod($y3, '2') === 1;
        $prefix = $isOdd ? "\x03" : "\x02";
        $xHex = str_pad(self::bcToHex($x3), 64, '0', STR_PAD_LEFT);

        return $prefix . hex2bin($xHex);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function decompressBc(string $pubkey, string $p): array
    {
        $prefix = ord($pubkey[0]);
        $x = self::hexToBc(bin2hex(substr($pubkey, 1, 32)));

        $x3 = bcmul($x, bcmul($x, $x));
        $y2 = bcmod(bcadd($x3, '7'), $p);

        $exp = bcdiv(bcadd($p, '1'), '4', 0);
        $y = bcpowmod($y2, $exp, $p);

        $isOdd = (int) bcmod($y, '2') === 1;
        $expectedOdd = ($prefix === 3);
        if ($isOdd !== $expectedOdd) {
            $y = bcsub($p, $y);
        }

        return [$x, $y];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function addPointsBc(string $x1, string $y1, string $x2, string $y2, string $p): array
    {
        if (bccomp($x1, $x2) === 0) {
            if (bccomp($y1, $y2) === 0) {
                if (bccomp($y1, '0') === 0) {
                    return [null, null];
                }
                $num = bcmul('3', bcpowmod($x1, '2', $p));
                $den = bcmul('2', $y1);
                $m = bcmod(bcmul($num, self::invertBc($den, $p)), $p);
            } else {
                return [null, null];
            }
        } else {
            $num = bcmod(bcsub($y2, $y1), $p);
            $den = bcmod(bcsub($x2, $x1), $p);
            $m = bcmod(bcmul($num, self::invertBc($den, $p)), $p);
        }

        $m2 = bcpowmod($m, '2', $p);
        $x3 = bcmod(bcsub(bcsub($m2, $x1), $x2), $p);
        $y3 = bcmod(bcsub(bcmul($m, bcsub($x1, $x3)), $y1), $p);

        while (bccomp($x3, '0') < 0) {
            $x3 = bcadd($x3, $p);
        }
        while (bccomp($y3, '0') < 0) {
            $y3 = bcadd($y3, $p);
        }

        return [$x3, $y3];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function multiplyPointBc(string $gx, string $gy, string $k, string $p): array
    {
        $rx = null;
        $ry = null;
        $qx = $gx;
        $qy = $gy;

        $tempK = $k;
        while (bccomp($tempK, '0') > 0) {
            if ((int) bcmod($tempK, '2') === 1) {
                if ($rx === null) {
                    $rx = $qx;
                    $ry = $qy;
                } else {
                    [$rx, $ry] = self::addPointsBc($rx, $ry, $qx, $qy, $p);
                }
            }
            [$qx, $qy] = self::addPointsBc($qx, $qy, $qx, $qy, $p);
            $tempK = bcdiv($tempK, '2', 0);
        }

        if ($rx === null || $ry === null) {
            throw new RuntimeException('Multiplication resulted in point at infinity.');
        }

        return [$rx, $ry];
    }

    private static function invertBc(string $a, string $m): string
    {
        // Fermat's Little Theorem: a^(m-2) mod m
        $a = bcmod($a, $m);
        while (bccomp($a, '0') < 0) {
            $a = bcadd($a, $m);
        }
        return bcpowmod($a, bcsub($m, '2'), $m);
    }

    private static function hexToBc(string $hex): string
    {
        $hex = ltrim($hex, '0x');
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($hex[$i]));
        }
        return $dec;
    }

    private static function bcToHex(string $dec): string
    {
        $hex = '';
        while (bccomp($dec, '0') > 0) {
            $rem = (int) bcmod($dec, '16');
            $hex = dechex($rem) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        return $hex === '' ? '0' : $hex;
    }
}
