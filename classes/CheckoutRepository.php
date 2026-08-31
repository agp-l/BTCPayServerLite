<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Read-only persistence boundary used by the public database checkout.
 */
interface CheckoutRepository
{
    /**
     * @return array{id:string,store_id:string,wallet_path:string}|null
     */
    public function findInvoiceWallet(string $invoiceId): ?array;
}
