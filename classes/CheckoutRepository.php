<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Read-only persistence boundary used by the public database checkout.
 */
interface CheckoutRepository
{
    /**
     * @return array<string,mixed>|null
     */
    public function findInvoice(string $invoiceId): ?array;
}
