<?php

declare(strict_types=1);

namespace BtcPayLite;

use BtcPayLite\Crypto\Bip32;
use InvalidArgumentException;
use Throwable;

/**
 * Generates Bitcoin addresses deterministically in-process using BIP32/BIP84/BIP44 derivation.
 * Eliminates all RPC overhead and external dependencies for address creation.
 */
class XpubAddressGenerator implements AddressGeneratorInterface
{
    private Bip32 $masterKey;
    private AddressIndexStoreInterface $indexStore;
    private string $scriptType;
    private int $changeBranch; // 0 for external receive chain, 1 for internal change chain

    public function __construct(
        string|Bip32 $xpubOrKey,
        AddressIndexStoreInterface $indexStore,
        ?string $scriptType = null,
        int $changeBranch = 0
    ) {
        $this->indexStore = $indexStore;
        $this->changeBranch = $changeBranch;

        if ($xpubOrKey instanceof Bip32) {
            $this->masterKey = $xpubOrKey;
        } else {
            try {
                $this->masterKey = Bip32::fromExtendedKey($xpubOrKey);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Invalid extended public key: ' . $e->getMessage(), 0, $e);
            }
        }

        $this->scriptType = $scriptType !== null && trim($scriptType) !== ''
            ? strtolower(trim($scriptType))
            : $this->masterKey->inferScriptType();
    }

    public function getMasterKey(): Bip32
    {
        return $this->masterKey;
    }

    public function getScriptType(): string
    {
        return $this->scriptType;
    }

    public function generateAddress(AddressGenerationContext $context): GeneratedAddress
    {
        try {
            $index = $this->indexStore->reserveNextIndex($context->getStoreId());
            $derivationPath = "{$this->changeBranch}/{$index}";

            // Derive receive chain first (usually 0), then the specific index
            $branchKey = $this->masterKey->deriveChild($this->changeBranch);
            $childKey = $branchKey->deriveChild($index);

            $address = $childKey->getAddress($this->scriptType, $context->getNetwork());

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
}
