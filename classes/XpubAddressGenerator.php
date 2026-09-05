<?php

declare(strict_types=1);

namespace BtcPayLite;

use BitWasp\Bitcoin\Address\PayToPubKeyHashAddress;
use BitWasp\Bitcoin\Address\ScriptHashAddress;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Script\WitnessProgram;
use BitWasp\Buffertools\Buffer;
use InvalidArgumentException;
use Throwable;

/**
 * Generates Bitcoin addresses deterministically in-process using standard BIP32/BIP84/BIP44 derivation.
 * Eliminates all RPC overhead and external dependencies for address creation.
 */
class XpubAddressGenerator implements AddressGeneratorInterface
{
    // Extended Public Key Version Bytes (SLIP-0132)
    public const VERSION_XPUB = '0488b21e'; // Mainnet P2PKH / generic
    public const VERSION_YPUB = '049d7cb2'; // Mainnet P2SH-P2WPKH
    public const VERSION_ZPUB = '04b24746'; // Mainnet P2WPKH
    public const VERSION_TPUB = '043587cf'; // Testnet P2PKH / generic
    public const VERSION_UPUB = '044a5262'; // Testnet P2SH-P2WPKH
    public const VERSION_VPUB = '045f1cf6'; // Testnet P2WPKH

    private HierarchicalKey $hierarchicalKey;
    private AddressIndexStoreInterface $indexStore;
    private string $scriptType;
    private int $changeBranch;
    private string $keyNetwork; // 'bitcoin' or 'testnet'

    public function __construct(
        string|HierarchicalKey $xpubOrKey,
        AddressIndexStoreInterface $indexStore,
        ?string $scriptType = null,
        int $changeBranch = 0
    ) {
        $this->indexStore = $indexStore;
        $this->changeBranch = $changeBranch;

        if ($xpubOrKey instanceof HierarchicalKey) {
            $this->hierarchicalKey = $xpubOrKey;
            $this->keyNetwork = 'bitcoin';
            $this->scriptType = $scriptType !== null && trim($scriptType) !== ''
                ? strtolower(trim($scriptType))
                : 'p2wpkh';
        } else {
            $parsed = $this->parseExtendedKey(trim($xpubOrKey), $scriptType);
            $this->hierarchicalKey = $parsed['key'];
            $this->keyNetwork = $parsed['network'];
            $this->scriptType = $parsed['script_type'];
        }
    }

    public function getHierarchicalKey(): HierarchicalKey
    {
        return $this->hierarchicalKey;
    }

    public function getScriptType(): string
    {
        return $this->scriptType;
    }

    public function getKeyNetwork(): string
    {
        return $this->keyNetwork;
    }

    public function generateAddress(AddressGenerationContext $context): GeneratedAddress
    {
        try {
            $index = $this->indexStore->reserveNextIndex($context->getStoreId());
            $derivationPath = "{$this->changeBranch}/{$index}";

            // Derive receive chain first (usually 0), then the specific index
            $childKey = $this->hierarchicalKey->derivePath($derivationPath);
            $pubKey = $childKey->getPublicKey();
            $pubKeyHash = $pubKey->getPubKeyHash();

            $contextNetwork = $context->getNetwork();
            $networkName = strtolower(trim($contextNetwork ?? $this->keyNetwork));
            $isTestnet = in_array($networkName, ['testnet', 'testnet3', 'regtest'], true) || $this->keyNetwork === 'testnet';
            $network = $isTestnet ? NetworkFactory::bitcoinTestnet() : NetworkFactory::bitcoin();

            $address = match ($this->scriptType) {
                'p2pkh' => (new PayToPubKeyHashAddress($pubKeyHash))->getAddress($network),
                'p2sh-p2wpkh', 'p2sh_p2wpkh' => (new ScriptHashAddress(WitnessProgram::v0($pubKeyHash)->getScript()->getScriptHash()))->getAddress($network),
                default => (new SegwitAddress(WitnessProgram::v0($pubKeyHash)))->getAddress($network),
            };

            return new GeneratedAddress(
                $address,
                GeneratedAddress::SOURCE_XPUB,
                $index,
                $derivationPath
            );
        } catch (Throwable $e) {
            if ($e instanceof AddressGenerationException) {
                throw $e;
            }
            throw new AddressGenerationException(
                'XPUB address derivation failed: ' . $e->getMessage(),
                GeneratedAddress::SOURCE_XPUB,
                500,
                $e
            );
        }
    }

    /**
     * Parses an extended public key string (xpub/ypub/zpub/tpub/upub/vpub)
     * and normalizes it for BitWasp hierarchical key factory.
     *
     * @return array{key: HierarchicalKey, network: string, script_type: string}
     */
    private function parseExtendedKey(string $key, ?string $overrideScriptType): array
    {
        $buffer = Base58::decodeCheck($key);
        $payload = $buffer->getBinary();
        if (strlen($payload) !== 78) {
            throw new InvalidArgumentException('Extended key must be exactly 78 bytes.');
        }

        $versionHex = strtolower(bin2hex(substr($payload, 0, 4)));

        switch ($versionHex) {
            case self::VERSION_ZPUB:
                $inferredScript = 'p2wpkh';
                $networkName = 'bitcoin';
                $normalizedVersionHex = self::VERSION_XPUB;
                break;
            case self::VERSION_YPUB:
                $inferredScript = 'p2sh-p2wpkh';
                $networkName = 'bitcoin';
                $normalizedVersionHex = self::VERSION_XPUB;
                break;
            case self::VERSION_XPUB:
                $inferredScript = 'p2wpkh';
                $networkName = 'bitcoin';
                $normalizedVersionHex = self::VERSION_XPUB;
                break;
            case self::VERSION_VPUB:
                $inferredScript = 'p2wpkh';
                $networkName = 'testnet';
                $normalizedVersionHex = self::VERSION_TPUB;
                break;
            case self::VERSION_UPUB:
                $inferredScript = 'p2sh-p2wpkh';
                $networkName = 'testnet';
                $normalizedVersionHex = self::VERSION_TPUB;
                break;
            case self::VERSION_TPUB:
                $inferredScript = 'p2wpkh';
                $networkName = 'testnet';
                $normalizedVersionHex = self::VERSION_TPUB;
                break;
            default:
                throw new InvalidArgumentException("Unsupported extended key version prefix: 0x{$versionHex}");
        }

        $effectiveScriptType = $overrideScriptType !== null && trim($overrideScriptType) !== ''
            ? strtolower(trim($overrideScriptType))
            : $inferredScript;

        if ($effectiveScriptType === 'p2sh_p2wpkh') {
            $effectiveScriptType = 'p2sh-p2wpkh';
        }

        $normalizedPayload = hex2bin($normalizedVersionHex) . substr($payload, 4);
        $normalizedKey = Base58::encodeCheck(new Buffer($normalizedPayload));

        $networkObj = $networkName === 'testnet'
            ? NetworkFactory::bitcoinTestnet()
            : NetworkFactory::bitcoin();

        $hdFactory = new HierarchicalKeyFactory();
        $hdKey = $hdFactory->fromExtended($normalizedKey, $networkObj);

        return [
            'key' => $hdKey,
            'network' => $networkName,
            'script_type' => $effectiveScriptType,
        ];
    }
}
