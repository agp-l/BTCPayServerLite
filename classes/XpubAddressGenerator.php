<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use RuntimeException;

/**
 * Derives Bitcoin payment addresses locally from an extended public key (XPUB/YPUB/ZPUB)
 * or output descriptor without any Electrum RPC calls.
 *
 * Defaults to Native SegWit (BIP84, P2WPKH, bc1q...).
 */
class XpubAddressGenerator implements AddressGeneratorInterface
{
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    private string $extendedPublicKey;
    private string $scriptType; // 'p2wpkh' (default), 'p2sh-p2wpkh', 'p2pkh'
    private string $network;    // 'mainnet', 'testnet'
    private int $change;        // 0 for receive, 1 for internal change
    private AddressIndexStoreInterface $indexStore;
    private string $generatorId;

    public function __construct(
        string $extendedPublicKey,
        AddressIndexStoreInterface $indexStore,
        string $generatorId,
        string $scriptType = 'p2wpkh',
        string $network = 'mainnet',
        int $change = 0
    ) {
        $cleanKey = self::cleanExtendedKey($extendedPublicKey);
        if (!self::isValidKeyFormat($cleanKey)) {
            throw new InvalidArgumentException('Invalid extended public key format.');
        }

        $this->extendedPublicKey = $cleanKey;
        $this->indexStore = $indexStore;
        $this->generatorId = $generatorId;
        $this->scriptType = strtolower(trim($scriptType));
        $this->network = strtolower(trim($network));
        $this->change = $change;
    }

    public function getSource(): string
    {
        return 'xpub';
    }

    public function getExtendedPublicKey(): string
    {
        return $this->extendedPublicKey;
    }

    public function generate(AddressGenerationContext $context): GeneratedAddress
    {
        // 1. Atomically reserve next address index
        $index = $this->indexStore->reserveNextIndex($this->generatorId);

        // 2. Derive Bitcoin address locally outside lock
        $address = $this->deriveAddress($this->change, $index);
        $derivationPath = $this->change . '/' . $index;

        return new GeneratedAddress(
            address: $address,
            source: 'xpub',
            index: $index,
            derivationPath: $derivationPath
        );
    }

    /**
     * Derives an address at a specific change branch and index without touching the index store.
     */
    public function deriveAddress(int $change, int $index): string
    {
        if ($index < 0 || $index >= 0x80000000) {
            throw new InvalidArgumentException('Child index must be non-hardened (< 2^31).');
        }

        // Try Node.js CLI if available for ultra-fast audited crypto derivation
        $cliAddress = $this->deriveViaNodeCli($this->extendedPublicKey, $change, $index);
        if ($cliAddress !== null) {
            return $cliAddress;
        }

        // Fallback to pure-PHP BIP32 derivation
        return $this->derivePurePhp($this->extendedPublicKey, $change, $index);
    }

    public static function cleanExtendedKey(string $key): string
    {
        $key = trim($key);
        // Handle output descriptor formats like wpkh([fingerprint/84'/0'/0']xpub.../0/*)
        if (preg_match('/([xXyYzZtTuUvV]pub[1-9A-HJ-NP-Za-km-z]{100,115})/', $key, $matches)) {
            return $matches[1];
        }
        return $key;
    }

    public static function isValidKeyFormat(string $key): bool
    {
        $key = self::cleanExtendedKey($key);
        return (bool) preg_match('/^(xpub|ypub|zpub|tpub|upub|vpub)[1-9A-HJ-NP-Za-km-z]{100,115}$/', $key);
    }

    private function deriveViaNodeCli(string $xpub, int $change, int $index): ?string
    {
        $nodeBin = null;
        foreach (['node', '/usr/local/bin/node', '/usr/bin/node'] as $candidate) {
            if (@is_executable($candidate)) {
                $nodeBin = $candidate;
                break;
            }
        }
        if ($nodeBin === null) {
            $which = @exec('which node 2>/dev/null');
            if ($which !== false && trim($which) !== '') {
                $nodeBin = trim($which);
            }
        }

        if ($nodeBin === null) {
            return null;
        }

        $appDir = dirname(__DIR__);
        $script = escapeshellarg(
            "import { HDKey } from '@scure/bip32';
             import * as btc from '@scure/btc-signer';
             try {
                 const xpub = '{$xpub}';
                 const hd = HDKey.fromExtendedKey(xpub);
                 const child = hd.deriveChild({$change}).deriveChild({$index});
                 const addr = btc.p2wpkh(child.publicKey, btc.NETWORK).address;
                 process.stdout.write(addr);
             } catch (e) {
                 process.exit(1);
             }"
        );

        $cmd = "cd " . escapeshellarg($appDir) . " && {$nodeBin} --input-type=module -e {$script} 2>/dev/null";
        $output = @shell_exec($cmd);

        if ($output !== null && preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/', trim($output))) {
            return trim($output);
        }

        return null;
    }

    private function derivePurePhp(string $xpub, int $change, int $index): string
    {
        // Decode base58check
        $payload = self::base58CheckDecode($xpub);
        if (strlen($payload) !== 78) {
            throw new RuntimeException('Invalid extended public key payload length.');
        }

        // 4 bytes: version, 1 byte: depth, 4 bytes: fingerprint, 4 bytes: child number, 32 bytes: chaincode, 33 bytes: pubkey
        $chainCode = substr($payload, 13, 32);
        $pubKey = substr($payload, 45, 33);

        // Derive change child
        [$childPubKey1, $childChainCode1] = self::bip32DeriveChild($pubKey, $chainCode, $change);

        // Derive address index child
        [$childPubKey2] = self::bip32DeriveChild($childPubKey1, $childChainCode1, $index);

        // P2WPKH: Bech32 encoding of HASH160(pubkey) with witness version 0
        $hash160 = hash('ripemd160', hash('sha256', $childPubKey2, true), true);
        return self::encodeSegwitAddress('bc', 0, $hash160);
    }

    private static function bip32DeriveChild(string $pubKey, string $chainCode, int $index): array
    {
        // Unhardened derivation: HMAC-SHA512(chainCode, pubKey || ser32(index))
        $data = $pubKey . pack('N', $index);
        $i = hash_hmac('sha512', $data, $chainCode, true);
        $il = substr($i, 0, 32);
        $ir = substr($i, 32, 32);

        // Point addition: childPubKey = pubKey + IL * G
        // Use openssl / elliptic curve math
        $childPubKey = self::secp256k1AddPoint($pubKey, $il);

        return [$childPubKey, $ir];
    }

    private static function secp256k1AddPoint(string $compressedPubKey, string $tweak): string
    {
        // P = 2^256 - 2^32 - 977
        $p = gmp_init('0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16);
        $n = gmp_init('0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BB5BF5CA4BDB57C03', 16);
        $gx = gmp_init('0x79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798', 16);
        $gy = gmp_init('0x483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8', 16);

        // Decompress public key
        $prefix = ord($compressedPubKey[0]);
        $x = gmp_import(substr($compressedPubKey, 1, 32));

        // y^2 = x^3 + 7 mod p
        $y2 = gmp_mod(gmp_add(gmp_powm($x, gmp_init(3), $p), gmp_init(7)), $p);
        // y = y2^((p+1)/4) mod p
        $exp = gmp_div(gmp_add($p, gmp_init(1)), gmp_init(4));
        $y = gmp_powm($y2, $exp, $p);

        if (($prefix === 0x02 && gmp_strval(gmp_mod($y, gmp_init(2))) !== '0') ||
            ($prefix === 0x03 && gmp_strval(gmp_mod($y, gmp_init(2))) === '0')) {
            $y = gmp_sub($p, $y);
        }

        // Tweak point T = tweak * G
        $tweakInt = gmp_import($tweak);
        if (gmp_cmp($tweakInt, $n) >= 0) {
            throw new RuntimeException('Tweak out of range.');
        }

        // Compute T = tweak * G
        [$tx, $ty] = self::ecMul($gx, $gy, $tweakInt, $p);

        // Point addition (x, y) + (tx, ty)
        [$rx, $ry] = self::ecAdd($x, $y, $tx, $ty, $p);

        $outPrefix = gmp_strval(gmp_mod($ry, gmp_init(2))) === '0' ? "\x02" : "\x03";
        $xBin = str_pad(gmp_export($rx), 32, "\x00", STR_PAD_LEFT);

        return $outPrefix . $xBin;
    }

    private static function ecAdd(\GMP $x1, \GMP $y1, \GMP $x2, \GMP $y2, \GMP $p): array
    {
        if (gmp_cmp($x1, $x2) === 0) {
            if (gmp_cmp($y1, $y2) === 0) {
                // Point doubling: m = (3*x1^2) / (2*y1) mod p
                $num = gmp_mul(gmp_init(3), gmp_powm($x1, gmp_init(2), $p));
                $den = gmp_invert(gmp_mul(gmp_init(2), $y1), $p);
                $m = gmp_mod(gmp_mul($num, $den), $p);
            } else {
                return [gmp_init(0), gmp_init(0)];
            }
        } else {
            // m = (y2 - y1) / (x2 - x1) mod p
            $num = gmp_mod(gmp_sub($y2, $y1), $p);
            $den = gmp_invert(gmp_mod(gmp_sub($x2, $x1), $p), $p);
            $m = gmp_mod(gmp_mul($num, $den), $p);
        }

        // x3 = m^2 - x1 - x2 mod p
        $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_powm($m, gmp_init(2), $p), $x1), $x2), $p);
        // y3 = m*(x1 - x3) - y1 mod p
        $y3 = gmp_mod(gmp_sub(gmp_mul($m, gmp_sub($x1, $x3)), $y1), $p);

        return [$x3, $y3];
    }

    private static function ecMul(\GMP $gx, \GMP $gy, \GMP $k, \GMP $p): array
    {
        $rx = null;
        $ry = null;
        $qx = $gx;
        $qy = $gy;

        $kBin = gmp_strval($k, 2);
        for ($i = strlen($kBin) - 1; $i >= 0; $i--) {
            if ($kBin[$i] === '1') {
                if ($rx === null) {
                    $rx = $qx;
                    $ry = $qy;
                } else {
                    [$rx, $ry] = self::ecAdd($rx, $ry, $qx, $qy, $p);
                }
            }
            [$qx, $qy] = self::ecAdd($qx, $qy, $qx, $qy, $p);
        }

        return [$rx ?? gmp_init(0), $ry ?? gmp_init(0)];
    }

    public static function encodeSegwitAddress(string $hrp, int $witnessVersion, string $witnessProgram): string
    {
        $data = [ $witnessVersion ];
        // convert 8-bit bytes to 5-bit groups
        $bits = 0;
        $value = 0;
        for ($i = 0; $i < strlen($witnessProgram); $i++) {
            $value = ($value << 8) | ord($witnessProgram[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $data[] = ($value >> $bits) & 0x1f;
            }
        }
        if ($bits > 0) {
            $data[] = ($value << (5 - $bits)) & 0x1f;
        }

        return self::bech32Encode($hrp, $data);
    }

    private static function bech32Encode(string $hrp, array $data): string
    {
        $chk = self::bech32Polymod(array_merge(self::bech32HrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0])) ^ 1;
        $combined = array_merge($data, [
            ($chk >> 25) & 0x1f,
            ($chk >> 20) & 0x1f,
            ($chk >> 15) & 0x1f,
            ($chk >> 10) & 0x1f,
            ($chk >> 5) & 0x1f,
            $chk & 0x1f
        ]);

        $result = $hrp . '1';
        foreach ($combined as $val) {
            $result .= self::BECH32_CHARSET[$val];
        }
        return $result;
    }

    private static function bech32Polymod(array $values): int
    {
        $chk = 1;
        $generator = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= $generator[$i];
                }
            }
        }
        return $chk;
    }

    private static function bech32HrpExpand(string $hrp): array
    {
        $res = [];
        for ($i = 0; $i < strlen($hrp); $i++) {
            $res[] = ord($hrp[$i]) >> 5;
        }
        $res[] = 0;
        for ($i = 0; $i < strlen($hrp); $i++) {
            $res[] = ord($hrp[$i]) & 0x1f;
        }
        return $res;
    }

    private static function base58CheckDecode(string $b58): string
    {
        $num = gmp_init(0);
        for ($i = 0; $i < strlen($b58); $i++) {
            $pos = strpos(self::BASE58_ALPHABET, $b58[$i]);
            if ($pos === false) {
                throw new InvalidArgumentException("Invalid Base58 char: {$b58[$i]}");
            }
            $num = gmp_add(gmp_mul($num, gmp_init(58)), gmp_init($pos));
        }

        $raw = gmp_export($num);
        // add leading zero bytes for leading '1's
        for ($i = 0; $i < strlen($b58) && $b58[$i] === '1'; $i++) {
            $raw = "\x00" . $raw;
        }

        if (strlen($raw) < 4) {
            throw new InvalidArgumentException('Invalid Base58Check data.');
        }

        $payload = substr($raw, 0, -4);
        $checksum = substr($raw, -4);
        $expectedChecksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        if (!hash_equals($expectedChecksum, $checksum)) {
            throw new InvalidArgumentException('Invalid Base58Check checksum.');
        }

        return $payload;
    }
}
