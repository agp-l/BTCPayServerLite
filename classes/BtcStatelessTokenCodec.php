<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use JsonException;

/**
 * Authenticates and validates the compact payload used by stateless invoices.
 */
final class BtcStatelessTokenCodec
{
    private const MIN_SECRET_BYTES = 16;
    private const MAX_EXPIRATION_SECONDS = 2_592_000;
    private const MAX_JSON_BYTES = 1_024;
    private const MAX_TOKEN_BYTES = 4_096;
    private const MAX_DESCRIPTION_BYTES = 255;

    private string $secretKey;

    public function __construct(string $secretKey)
    {
        if (strlen($secretKey) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException(
                'Invoice secret key must contain at least 16 bytes.'
            );
        }

        $this->secretKey = $secretKey;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        $payload = $this->normalizePayload($payload);

        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new BtcInvoiceManagerException(
                'Unable to encode stateless invoice.',
                'encode_token',
                400,
                $exception
            );
        }

        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new BtcInvoiceManagerException(
                'Stateless invoice data is too large.',
                'encode_token',
                400
            );
        }

        $encodedPayload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $this->secretKey);

        return $encodedPayload . '.' . $signature;
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES) {
            throw $this->invalidToken('Invalid invoice token.');
        }

        $parts = explode('.', $token, 3);
        if (count($parts) !== 2) {
            throw $this->invalidToken('Invalid invoice token format.');
        }

        [$encodedPayload, $signature] = $parts;
        if (
            !preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/D', $encodedPayload)
            || !preg_match('/\A[0-9a-f]{64}\z/D', $signature)
        ) {
            throw $this->invalidToken('Invalid invoice token encoding.');
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->secretKey);
        if (!hash_equals($expectedSignature, $signature)) {
            throw $this->invalidToken('Invoice signature verification failed.');
        }

        $base64 = strtr($encodedPayload, '-_', '+/');
        $paddingLength = (4 - (strlen($base64) % 4)) % 4;
        $json = base64_decode($base64 . str_repeat('=', $paddingLength), true);
        if ($json === false) {
            throw $this->invalidToken('Invalid invoice token payload.');
        }

        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BtcInvoiceManagerException(
                'Invalid invoice token JSON.',
                'decode_token',
                400,
                $exception
            );
        }

        if (!is_array($payload)) {
            throw $this->invalidToken('Invalid invoice token data.');
        }

        return $this->normalizePayload($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $address = $this->requireString($payload['a'] ?? null, 'address', 100);
        $amount = $this->requireAmount($payload['v'] ?? null);
        $description = $this->requireString(
            $payload['d'] ?? '',
            'description',
            self::MAX_DESCRIPTION_BYTES,
            true
        );
        $customData = $payload['p'] ?? [];
        $createdAt = $payload['t'] ?? null;
        $expiresAt = $payload['e'] ?? null;

        if (
            !is_array($customData)
            || !is_int($createdAt)
            || !is_int($expiresAt)
            || $createdAt < 1
            || $expiresAt <= $createdAt
            || ($expiresAt - $createdAt) > self::MAX_EXPIRATION_SECONDS
        ) {
            throw $this->invalidToken('Invalid invoice token fields.');
        }

        $version = 1;
        if (array_key_exists('ver', $payload)) {
            if (!is_int($payload['ver']) || !in_array($payload['ver'], [1, 2], true)) {
                throw $this->invalidToken('Unsupported invoice token version.');
            }
            $version = $payload['ver'];
        }

        $requestId = null;
        if (array_key_exists('r', $payload)) {
            $requestId = $this->requireString($payload['r'], 'request ID', 128);
        } elseif ($version >= 2) {
            throw $this->invalidToken('Invoice token is missing its payment request ID.');
        }

        $payload['a'] = $address;
        $payload['v'] = $amount->toBtcString();
        $payload['d'] = $description;
        $payload['p'] = $customData;
        $payload['t'] = $createdAt;
        $payload['e'] = $expiresAt;
        if ($requestId !== null) {
            $payload['r'] = $requestId;
        }

        return $payload;
    }

    private function requireAmount(mixed $amount): BitcoinAmount
    {
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            throw $this->invalidToken('Invalid invoice token amount.');
        }

        try {
            $bitcoinAmount = BitcoinAmount::fromBtc($amount);
        } catch (InvalidArgumentException $exception) {
            throw new BtcInvoiceManagerException(
                'Invalid invoice token amount.',
                'decode_token',
                400,
                $exception
            );
        }

        if (!$bitcoinAmount->isPositive()) {
            throw $this->invalidToken('Invalid invoice token amount.');
        }

        return $bitcoinAmount;
    }

    private function requireString(
        mixed $value,
        string $field,
        int $maxBytes,
        bool $allowEmpty = false
    ): string {
        if (!is_string($value) || str_contains($value, "\0") || strlen($value) > $maxBytes) {
            throw $this->invalidToken("Invalid invoice token {$field}.");
        }

        $value = trim($value);
        if (!$allowEmpty && $value === '') {
            throw $this->invalidToken("Invalid invoice token {$field}.");
        }

        return $value;
    }

    private function invalidToken(string $message): BtcInvoiceManagerException
    {
        return new BtcInvoiceManagerException($message, 'decode_token', 400);
    }
}
