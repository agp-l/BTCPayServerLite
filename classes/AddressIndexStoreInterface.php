<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Persists and atomically reserves the next child derivation index for an XPUB.
 */
interface AddressIndexStoreInterface
{
    /**
     * Atomically reserves and returns the next unallocated address index.
     */
    public function reserveNextIndex(string $generatorId): int;
}
