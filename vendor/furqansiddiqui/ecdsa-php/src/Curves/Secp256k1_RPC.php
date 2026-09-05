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
use Comely\Buffer\Buffer;
use FurqanSiddiqui\ECDSA\ECC\EllipticCurveInterface;
use FurqanSiddiqui\ECDSA\ECC\PublicKey;
use FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception;
use FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception;
use FurqanSiddiqui\ECDSA\Exception\SignatureException;
use FurqanSiddiqui\ECDSA\Signature\Signature;

/**
 * Class Secp256k1_RPC
 * Uses bitcoin's original secp256k1 library (written in C) via RPC
 * @package FurqanSiddiqui\ECDSA\Curves
 */
class Secp256k1_RPC implements EllipticCurveInterface
{
    /** @var int */
    public int $timeout = 3;
    /** @var int */
    public int $connectTimeout = 3;

    /**
     * @param string $host
     * @param int $port
     */
    public function __construct(public readonly string $host, public readonly int $port)
    {
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        try {
            return $this->sendCurlRequest("ping") === "pong";
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @return void
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception
     */
    public function testConnection(): void
    {
        if ($this->sendCurlRequest("ping") !== "pong") {
            throw new ECDSA_RPC_Exception('Unexpected response to ping call');
        }
    }

    /**
     * @param \Comely\Buffer\AbstractByteArray $privateKey
     * @return bool
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception
     */
    public function validatePrivateKey(AbstractByteArray $privateKey): bool
    {
        $result = $this->sendCurlRequest("validatePrivateKey", [$privateKey->toBase16(false)]);
        return $result === true;
    }

    /**
     * @param \Comely\Buffer\AbstractByteArray $privateKey
     * @return \FurqanSiddiqui\ECDSA\ECC\PublicKey
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception
     */
    public function generatePublicKey(AbstractByteArray $privateKey): PublicKey
    {
        $result = $this->sendCurlRequest("createPublicKey", [$privateKey->toBase16(false), true]);
        if (!is_array($result) || !isset($result["compressed"]) || !isset($result["unCompressed"])) {
            throw new ECDSA_Exception('Bad response structure from RPC createPublicKey method');
        }

        return new PublicKey(
            substr($result["unCompressed"], 2, 64),
            substr($result["unCompressed"], 66, 128),
            substr($result["compressed"], 0, 2)
        );
    }

    /**
     * @param \Comely\Buffer\AbstractByteArray $privateKey
     * @param \Comely\Buffer\AbstractByteArray $msgHash
     * @param \Comely\Buffer\AbstractByteArray|null $randomK
     * @return \FurqanSiddiqui\ECDSA\Signature\Signature
     * @throws \Comely\Buffer\Exception\ByteReaderUnderflowException
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\SignatureException
     */
    public function sign(AbstractByteArray $privateKey, AbstractByteArray $msgHash, ?AbstractByteArray $randomK = null): Signature
    {
        return Signature::fromCompact($this->ecdsaSign(true, $privateKey, $msgHash, $randomK));
    }

    /**
     * @param bool $recoverable
     * @param \Comely\Buffer\AbstractByteArray $privateKey
     * @param \Comely\Buffer\AbstractByteArray $msgHash
     * @param \Comely\Buffer\AbstractByteArray|null $randomK
     * @return \Comely\Buffer\Buffer
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\SignatureException
     */
    private function ecdsaSign(bool $recoverable, AbstractByteArray $privateKey, AbstractByteArray $msgHash, ?AbstractByteArray $randomK = null): Buffer
    {
        if ($privateKey->len() !== 32) {
            throw new SignatureException('Private key must be of 32 bytes');
        } elseif ($msgHash->len() !== 32) {
            throw new SignatureException('Message hash must be 32 bytes');
        }

        $result = $this->sendCurlRequest("ecdsaSign", [$privateKey->toBase16(false), $msgHash->toBase16(false), $recoverable, $randomK]);
        if (!is_string($result)) {
            throw new ECDSA_RPC_Exception(sprintf('Expected "string" from "ecdsaSign" call, got "%s"', gettype($result)));
        }

        return Buffer::fromBase16($result);
    }

    /**
     * @param \FurqanSiddiqui\ECDSA\ECC\PublicKey $publicKey
     * @param \FurqanSiddiqui\ECDSA\Signature\Signature $signature
     * @param \Comely\Buffer\AbstractByteArray $msgHash
     * @return bool
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\SignatureException
     */
    public function verify(PublicKey $publicKey, Signature $signature, AbstractByteArray $msgHash): bool
    {
        if ($msgHash->len() !== 32) {
            throw new SignatureException('Message hash must be 32 bytes');
        }

        $result = $this->sendCurlRequest("ecdsaVerify", [
            $publicKey->getUnCompressed()->toBase16(),
            $signature->getDER()->toBase16(),
            $msgHash->toBase16(false)
        ]);

        if (!is_bool($result)) {
            throw new ECDSA_RPC_Exception(sprintf('Expected "bool" from "ecdsaSign" call, got "%s"', gettype($result)));
        }

        return $result;
    }

    /**
     * @param \FurqanSiddiqui\ECDSA\Signature\Signature $signature
     * @param \Comely\Buffer\AbstractByteArray $msgHash
     * @param int|null $recId
     * @return \FurqanSiddiqui\ECDSA\ECC\PublicKey
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\SignatureException
     */
    public function recoverPublicKeyFromSignature(Signature $signature, AbstractByteArray $msgHash, ?int $recId = null): PublicKey
    {
        if (is_int($recId) && $signature->recoveryId !== $recId) {
            $signature = new Signature($signature->r, $signature->s, $recId);
        }

        if ($signature->recoveryId < 0 || $signature->recoveryId > 3) {
            throw new SignatureException('Signature does not have recovery id set');
        }

        if ($msgHash->len() !== 32) {
            throw new SignatureException('Message hash must be 32 bytes');
        }

        $result = $this->sendCurlRequest("ecdsaRecover", [$signature->getCompact()->toBase16(false), $msgHash->toBase16(false)]);
        if (!is_array($result) || !isset($result["compressed"]) || !isset($result["unCompressed"])) {
            throw new ECDSA_Exception('Bad response structure from RPC createPublicKey method');
        }

        return new PublicKey(
            substr($result["unCompressed"], 2, 64),
            substr($result["unCompressed"], 66, 128),
            substr($result["compressed"], 0, 2)
        );
    }

    /**
     * @param string $method
     * @param array|null $params
     * @return array|string|bool|int
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_Exception
     * @throws \FurqanSiddiqui\ECDSA\Exception\ECDSA_RPC_Exception
     */
    private function sendCurlRequest(string $method, ?array $params = null): array|string|bool|int
    {
        $payload = [
            "jsonrpc" => "2.0",
            "id" => uniqid($method),
            "method" => $method,
        ];

        if ($params) {
            $payload["params"] = $params;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://" . $this->host . ":" . $this->port);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        if ($this->timeout > 0) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        }

        if ($this->connectTimeout > 0) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        if (false === $response) {
            throw ECDSA_RPC_Exception::CurlError($ch);
        }

        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!str_contains("application/json", $contentType)) {
            throw new ECDSA_RPC_Exception('Expected content type "application/json" from RPC server');
        }

        try {
            $response = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ECDSA_RPC_Exception('Failed to decode JSON response from server');
        }

        if (isset($response["error"])) {
            throw new ECDSA_Exception($response["error"]["message"], $response["error"]["code"]);
        }

        return $response["result"];
    }
}
