<?php

declare(strict_types=1);
namespace BtcPayLite;
use BitWasp\Bitcoin\Base58;
use BitWasp\Buffertools\Buffer;

/** Same key/chain code shares a sequence even when exported with another SLIP-0132 prefix. */
final class XpubDerivationIdentity
{
    public static function describe(string $xpub): array
    {
        $payload = Base58::decodeCheck(trim($xpub))->getBinary();
        if (strlen($payload) !== 78 || !in_array(bin2hex(substr($payload, 0, 4)), [
            XpubAddressGenerator::VERSION_XPUB, XpubAddressGenerator::VERSION_YPUB, XpubAddressGenerator::VERSION_ZPUB,
            XpubAddressGenerator::VERSION_TPUB, XpubAddressGenerator::VERSION_UPUB, XpubAddressGenerator::VERSION_VPUB,
        ], true) || !in_array(ord($payload[45]), [2, 3], true)) {
            throw new \InvalidArgumentException('A supported extended public key is required.');
        }
        $aliases = [];
        foreach (['0488b21e','049d7cb2','04b24746','043587cf','044a5262','045f1cf6'] as $version) {
            $aliases[] = Base58::encodeCheck(new Buffer(hex2bin($version) . substr($payload, 4)));
        }
        return ['id' => hash('sha256', substr($payload, 13)), 'aliases' => $aliases];
    }
}
