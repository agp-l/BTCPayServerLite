<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BtcPayLite\AddressGenerationContext;
use BtcPayLite\AddressIndexStoreInterface;
use BtcPayLite\FileAddressIndexStore;
use BtcPayLite\GeneratedAddress;
use BtcPayLite\XpubAddressGenerator;

class MockMemoryIndexStore implements AddressIndexStoreInterface
{
    private int $counter = 0;

    public function reserveNextIndex(string $storeId): int
    {
        $idx = $this->counter;
        $this->counter++;
        return $idx;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }
}

// BIP32 Test Vector 1 xpub
$testXpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';

// 1. Test P2WPKH derivation from xpub
$indexStore = new MockMemoryIndexStore();
$generator = new XpubAddressGenerator($testXpub, $indexStore, 'p2wpkh');

$context = new AddressGenerationContext('store-test-1');
$addr0 = $generator->generateAddress($context);
$addr1 = $generator->generateAddress($context);

if ($addr0->getSource() !== GeneratedAddress::SOURCE_XPUB) {
    throw new RuntimeException("Expected source 'xpub', got {$addr0->getSource()}");
}
if ($addr0->getIndex() !== 0) {
    throw new RuntimeException("Expected index 0, got {$addr0->getIndex()}");
}
if ($addr0->getDerivationPath() !== '0/0') {
    throw new RuntimeException("Expected path '0/0', got {$addr0->getDerivationPath()}");
}
if (!str_starts_with($addr0->getAddress(), 'bc1q')) {
    throw new RuntimeException("Expected Segwit address starting with bc1q, got {$addr0->getAddress()}");
}

if ($addr1->getIndex() !== 1) {
    throw new RuntimeException("Expected index 1, got {$addr1->getIndex()}");
}
if ($addr1->getDerivationPath() !== '0/1') {
    throw new RuntimeException("Expected path '0/1', got {$addr1->getDerivationPath()}");
}
if ($addr0->getAddress() === $addr1->getAddress()) {
    throw new RuntimeException("Addresses for consecutive indices must be distinct");
}

// 2. Test P2PKH (legacy) derivation
$indexStoreLegacy = new MockMemoryIndexStore();
$legacyGen = new XpubAddressGenerator($testXpub, $indexStoreLegacy, 'p2pkh');
$legacyAddr = $legacyGen->generateAddress($context);
if (!str_starts_with($legacyAddr->getAddress(), '1')) {
    throw new RuntimeException("Expected legacy address starting with 1, got {$legacyAddr->getAddress()}");
}

// 3. Test P2SH-P2WPKH (nested segwit) derivation
$indexStoreNested = new MockMemoryIndexStore();
$nestedGen = new XpubAddressGenerator($testXpub, $indexStoreNested, 'p2sh-p2wpkh');
$nestedAddr = $nestedGen->generateAddress($context);
if (!str_starts_with($nestedAddr->getAddress(), '3')) {
    throw new RuntimeException("Expected nested segwit address starting with 3, got {$nestedAddr->getAddress()}");
}

// 4. Test 100 sequential unique indices
$bulkStore = new MockMemoryIndexStore();
$bulkGen = new XpubAddressGenerator($testXpub, $bulkStore, 'p2wpkh');
$generated = [];
for ($i = 0; $i < 100; $i++) {
    $res = $bulkGen->generateAddress($context);
    if (isset($generated[$res->getAddress()])) {
        throw new RuntimeException("Collision detected at index {$i}: {$res->getAddress()}");
    }
    $generated[$res->getAddress()] = $res->getIndex();
    if ($res->getIndex() !== $i) {
        throw new RuntimeException("Index mismatch: expected {$i}, got {$res->getIndex()}");
    }
}
if (count($generated) !== 100) {
    throw new RuntimeException("Expected 100 unique addresses, got " . count($generated));
}

echo "XpubAddressGeneratorTest passed.\n";
