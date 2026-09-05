<?php

declare(strict_types=1);

namespace BtcPayLite\Crypto;

use InvalidArgumentException;

class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        // Count leading zero bytes
        $leadingZeros = 0;
        $length = strlen($data);
        while ($leadingZeros < $length && $data[$leadingZeros] === "\x00") {
            $leadingZeros++;
        }

        // Convert byte string to big integer and divide by 58
        $bytes = array_values(unpack('C*', $data));
        $encoded = '';

        while (count($bytes) > 0) {
            $remainder = 0;
            $newBytes = [];
            foreach ($bytes as $byte) {
                $accumulator = ($remainder << 8) + $byte;
                $digit = intdiv($accumulator, 58);
                $remainder = $accumulator % 58;
                if (count($newBytes) > 0 || $digit > 0) {
                    $newBytes[] = $digit;
                }
            }
            $bytes = $newBytes;
            $encoded = self::ALPHABET[$remainder] . $encoded;
        }

        return str_repeat('1', $leadingZeros) . $encoded;
    }

    public static function encodeCheck(string $payload): string
    {
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        return self::encode($payload . $checksum);
    }

    public static function decode(string $base58): string
    {
        $base58 = trim($base58);
        if ($base58 === '') {
            return '';
        }

        $leadingOnes = 0;
        $length = strlen($base58);
        while ($leadingOnes < $length && $base58[$leadingOnes] === '1') {
            $leadingOnes++;
        }

        $indexes = [];
        for ($i = 0; $i < $length; $i++) {
            $char = $base58[$i];
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                throw new InvalidArgumentException("Invalid Base58 character: '{$char}'");
            }
            $indexes[] = $pos;
        }

        $bytes = [];
        while (count($indexes) > 0) {
            $remainder = 0;
            $newIndexes = [];
            foreach ($indexes as $idx) {
                $accumulator = $remainder * 58 + $idx;
                $digit = $accumulator >> 8;
                $remainder = $accumulator & 0xFF;
                if (count($newIndexes) > 0 || $digit > 0) {
                    $newIndexes[] = $digit;
                }
            }
            $indexes = $newIndexes;
            array_unshift($bytes, $remainder);
        }

        $result = str_repeat("\x00", $leadingOnes) . pack('C*', ...$bytes);
        return $result;
    }

    public static function decodeCheck(string $base58): string
    {
        $data = self::decode($base58);
        if (strlen($data) < 4) {
            throw new InvalidArgumentException('Invalid Base58Check string: data too short.');
        }

        $payload = substr($data, 0, -4);
        $checksum = substr($data, -4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        if (!hash_equals($expected, $checksum)) {
            throw new InvalidArgumentException('Base58Check checksum mismatch.');
        }

        return $payload;
    }
}
