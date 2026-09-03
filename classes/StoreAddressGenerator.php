<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Resolves store configuration and delegates address generation to the appropriate
 * generator (XPUB or Electrum) via AddressGeneratorFactory.
 */
final class StoreAddressGenerator implements AddressGeneratorInterface
{
    private Database $database;
    private AddressGeneratorFactory $factory;
    private ?string $defaultXpub;
    private ?string $defaultWalletPath;

    public function __construct(
        Database $database,
        AddressGeneratorFactory $factory,
        ?string $defaultXpub = null,
        ?string $defaultWalletPath = null
    ) {
        $this->database = $database;
        $this->factory = $factory;
        $this->defaultXpub = $defaultXpub;
        $this->defaultWalletPath = $defaultWalletPath;
    }

    public function getSource(): string
    {
        return 'store_router';
    }

    public function generate(AddressGenerationContext $context): GeneratedAddress
    {
        $storeId = $context->getStoreId();

        if ($storeId === 'stateless') {
            $store = [
                'id' => 'stateless',
                'address_source' => $context->getPreferredSource() ?? ($this->defaultXpub !== null && $this->defaultXpub !== '' ? 'xpub' : 'electrum'),
                'xpub' => $this->defaultXpub,
                'wallet_path' => $this->defaultWalletPath ?? '',
            ];
            $generator = $this->factory->forStore($store, $context->getPreferredSource());
            return $generator->generate($context);
        }

        $statement = $this->database->getPdo()->prepare(
            'SELECT id, name, wallet_path, address_source, xpub, derivation_path FROM stores WHERE id = ? LIMIT 1'
        );
        $statement->execute([$storeId]);
        $store = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($store)) {
            throw new InvalidArgumentException("Store '{$storeId}' not found for address generation.");
        }

        $generator = $this->factory->forStore($store, $context->getPreferredSource());
        return $generator->generate($context);
    }
}
