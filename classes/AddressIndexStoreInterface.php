<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Interface for reserving monotonically increasing derivation indices atomically.
 */
interface AddressIndexStoreInterface
{
    /**
     * Atomically reserves and returns the next unused derivation index for the given store.
     *
     * @throws AddressGenerationException on failure or lock timeout
     */
    public function reserveNextIndex(string $storeId): int;
}
