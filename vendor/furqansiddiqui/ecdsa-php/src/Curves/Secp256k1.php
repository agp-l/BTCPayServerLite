<?php
/*
 * This file is a part of "furqansiddiqui/ecdsa-php" package.
 * https://github.com/furqansiddiqui/ecdsa-php
 *
 * Copyright (c) Furqan A. Siddiqui <hello@furqansiddiqui.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code or visit following link:
 * https://github.com/furqansiddiqui/ecdsa-php/blob/master/LICENSE
 */

declare(strict_types=1);

namespace FurqanSiddiqui\ECDSA\Curves;

use Comely\Buffer\AbstractByteArray;
use Comely\Buffer\BigInteger;
use Comely\Buffer\Buffer;
use FurqanSiddiqui\ECDSA\ECC\AbstractGMPCurve;
use FurqanSiddiqui\ECDSA\ECC\Math;
use FurqanSiddiqui\ECDSA\ECC\PublicKey;
use FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception;
use FurqanSiddiqui\ECDSA\Exception\SignatureException;
use FurqanSiddiqui\ECDSA\Signature\Rfc6979;
use FurqanSiddiqui\ECDSA\Signature\Signature;
use FurqanSiddiqui\ECDSA\Signature\SignatureInterface;

/**
 * Class Secp256k1
 * @package FurqanSiddiqui\ECDSA\Curves
 */
class Secp256k1 extends AbstractGMPCurve
{
    public const OID = "1.3.132.0.10";

    public const A = "0";
    public const B = "7";
    public const PRIME = "115792089237316195423570985008687907853269984665640564039457584007908834671663";
    public const ORDER = "115792089237316195423570985008687907852837564279074904382605163141518161494337";
    public const Gx = "55066263022277343669578718895168534326250603453777594175500187360389116729240";
    public const Gy = "32670510020758816978083085130507043184471273380659243275938904335757337482424";

    /**
     * @param \Comely\Buffer\AbstractByteArray $privateKey
     * @return bool
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     */
    public function validatePrivateKey(AbstractByteArray $privateKey): bool
    {
        if ($privateKey->len() !== 32) {
            throw new ECDSA_Exception('Private key for Secp256k1 must be precisely 32 bytes');
        }

        $prvInteger = new BigInteger($privateKey);
        if ($prvInteger->cmp(1) < 0) {
            throw new ECDSA_Exception('Private key integer value is not positive');
        }

        if ($prvInteger->cmp($this->order) >= 0) {
            throw new ECDSA_Exception('Private key integer value exceeds Secp256k1::ORDER');
        }

        return true;
    }

    /**
     * @param \Comely\Buffer\AbstractByteArray $privateKey
     * @return \FurqanSiddiqui\ECDSA\ECC\PublicKey
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     */
    public function generatePublicKey(AbstractByteArray $privateKey): PublicKey
    {
        if ($privateKey->len() !== 32) {
            throw new ECDSA_Exception('Private key must be exactly 32 bytes long');
        }

        $pub = $this->generator()->mul(gmp_init($privateKey->toBase16(false), 16));
        return new PublicKey(
            str_pad(gmp_strval($pub->x, 16), 64, "0", STR_PAD_LEFT),
            str_pad(gmp_strval($pub->y, 16), 64, "0", STR_PAD_LEFT)
        );
    }

    /**
     * @param \Comely\Buffer\AbstractByteArray $compressed
     * @return \FurqanSiddiqui\ECDSA\ECC\PublicKey
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     */
    public function getPublicKeyFromCompressed(AbstractByteArray $compressed): PublicKey
    {
        $x = $compressed->toBase16(false);
        if (strlen($x) !== 66) {
            throw new ECDSA_Exception('Invalid public key length');
        }

        $evenOrOddPrefix = substr($x, 0, 2);
        $x = gmp_init(substr($x, 2), 16);
        $y2 = gmp_mod(gmp_add(gmp_add(gmp_powm($x, gmp_init(3, 10), $this->prime), gmp_mul($this->a, $x)), $this->b), $this->prime);
        $y = Math::gmpSqrt($y2, $this->prime);
        if (!$y) {
            throw new ECDSA_Exception('Failed to calculate point Y');
        }

        $resY = null;
        if ($evenOrOddPrefix === "02") {
            if (gmp_strval(gmp_mod($y[0], gmp_init(2, 10)), 10) === "0") {
                $resY = gmp_strval($y[0], 16);
            }

            if (gmp_strval(gmp_mod($y[1], gmp_init(2, 10)), 10) === "0") {
                $resY = gmp_strval($y[1], 16);
            }
        } elseif ($evenOrOddPrefix === "03") {
            if (gmp_strval(gmp_mod($y[0], gmp_init(2, 10)), 10) === "1") {
                $resY = gmp_strval($y[0], 16);
            }

            if (gmp_strval(gmp_mod($y[1], gmp_init(2, 10)), 10) === "1") {
                $resY = gmp_strval($y[1], 16);
            }
        } else {
            throw new ECDSA_Exception('Invalid compressed public key prefix');
        }

        if (!$resY) {
            throw new ECDSA_Exception('Could not find public key point Y');
        }

        return new PublicKey(gmp_strval($x, 16), str_pad($resY, 64, "0", STR_PAD_LEFT));
    }

    /**
     * @param \Comely\Buffer\AbstractByteArray $privateKey
     * @param \Comely\Buffer\AbstractByteArray $msgHash
     * @param \Comely\Buffer\AbstractByteArray|null $randomK
     * @return \FurqanSiddiqui\ECDSA\Signature\Signature
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     */
    public function sign(AbstractByteArray $privateKey, AbstractByteArray $msgHash, ?AbstractByteArray $randomK = null): Signature
    {
        if ($privateKey->len() !== 32) {
            throw new SignatureException('Private key must be of 32 bytes');
        } elseif ($msgHash->len() !== 32) {
            throw new SignatureException('Message hash must be 32 bytes');
        }

        $privateKeyInt = gmp_init($privateKey->toBase16(false), 16);
        $msgHashInt = gmp_init($msgHash->toBase16(false), 16);

        // use specified K or use Deterministic RNG
        if (!$randomK) {
            // RFC6979
            $rfc6979 = new Rfc6979("sha256", $msgHashInt, $privateKeyInt);
            $randomK = $rfc6979->generateK($this->order);
        }

        $randomKInt = gmp_init($randomK->toBase16(false), 16);
        $n = $this->order;
        $generator = $this->generator();
        $ptR = $generator->mul($randomKInt);
        if (gmp_cmp($ptR->x, 0) === 0) {
            throw new SignatureException('Signature r (Point.x) === 0');
        }

        // Second part of the signature (S).
        $s = gmp_mod(gmp_mul(gmp_invert($randomKInt, $n), gmp_add($msgHashInt, gmp_mul($privateKeyInt, $ptR->x))), $n);
        if (gmp_cmp($s, 0) === 0) {
            throw new SignatureException('Signature s === 0');
        }

        // BIP 62, make sure we use the low-s value
        if (gmp_cmp($s, gmp_div($n, 2)) === 1) {
            $s = gmp_sub($n, $s);
        }

        $r = str_pad(gmp_strval($ptR->x, 16), 64, "0", STR_PAD_LEFT);
        $s = str_pad(gmp_strval($s, 16), 64, "0", STR_PAD_LEFT);
        return new Signature(Buffer::fromBase16($r), Buffer::fromBase16($s), -1);
    }

    /**
     * @param \FurqanSiddiqui\ECDSA\ECC\PublicKey $publicKey
     * @param \FurqanSiddiqui\ECDSA\Signature\SignatureInterface $signature
     * @param \Comely\Buffer\AbstractByteArray $msgHash
     * @return bool
     */
    public function verify(PublicKey $publicKey, SignatureInterface $signature, AbstractByteArray $msgHash): bool
    {
        $G = $this->generator();
        $R = $signature->r->toBase16(false);
        $S = $signature->s->toBase16(false);
        $hash = $msgHash->toBase16(false);

        $exp1 = gmp_mul(gmp_invert(gmp_init($S, 16), $this->order), gmp_init($hash, 16));
        $exp1Pt = $G->mul($exp1);
        $exp2 = gmp_mul(gmp_invert(gmp_init($S, 16), $this->order), gmp_init($R, 16));

        $pubKeyPt = $this->getPoint(gmp_init($publicKey->x, 16), gmp_init($publicKey->y, 16));
        $exp2Pt = $pubKeyPt->mul($exp2);
        $resultPt = $exp1Pt->add($exp2Pt);
        $resultX = str_pad(gmp_strval($resultPt->x, 16), 64, "0", STR_PAD_LEFT);
        return hex2bin($resultX) === hex2bin($R);
    }

    /**
     * @param \FurqanSiddiqui\ECDSA\Signature\SignatureInterface $signature
     * @param \Comely\Buffer\AbstractByteArray $msgHash
     * @param int|null $recId
     * @return \FurqanSiddiqui\ECDSA\ECC\PublicKey
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     */
    public function recoverPublicKeyFromSignature(SignatureInterface $signature, AbstractByteArray $msgHash, ?int $recId = null): PublicKey
    {
        $r = $signature->r->toBase16(false);
        $s = $signature->s->toBase16(false);
        $recId = is_int($recId) && $recId >= 0 ? $recId : $signature->recoveryId;
        if ($recId < 0 || $recId > 3) {
            throw new SignatureException('Signature does not have a recovery Id');
        }

        $msgHashInt = gmp_init($msgHash->toBase16(false), 16);

        // Step 1.1
        $x = gmp_add(gmp_init($r, 16), gmp_mul($this->order, gmp_div_q(gmp_init($recId, 10), gmp_init(2, 10))));

        // Step 1.3
        try {
            $yPubKey = $this->getPublicKeyFromCompressed(
                Buffer::fromBase16(str_pad(gmp_strval($x, 16), 64, "0", STR_PAD_LEFT))->prepend($recId % 2 !== 1 ? "\x02" : "\x03")
            );

            $y = gmp_init($yPubKey->y, 16);
        } catch (\Exception) {
            throw new ECDSA_Exception('Could not retrieve public key Y');
        }

        $ptR = $this->getPoint($x, $y);

        // Step 1.6.1
        $eG = $this->generator()->mul($msgHashInt);
        $eGY = gmp_mod(gmp_neg($eG->y), $this->prime);
        $eG = $this->getPoint($eG->x, $eGY);
        $SR = $ptR->mul(gmp_init($s, 16));
        $SReG = $SR->add($eG);
        $pubKey = $SReG->mul(gmp_invert(gmp_init($r, 16), $this->order));
        $pubKeyX = str_pad(gmp_strval($pubKey->x, 16), 64, "0", STR_PAD_LEFT);
        $pubKeyY = str_pad(gmp_strval($pubKey->y, 16), 64, "0", STR_PAD_LEFT);
        $publicKey = new PublicKey($pubKeyX, $pubKeyY);
        if ($this->verify($publicKey, $signature, $msgHash)) {
            return $publicKey;
        }

        throw new ECDSA_Exception('Public key cannot be recovered with given arguments');
    }
}
