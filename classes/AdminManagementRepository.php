<?php

declare(strict_types=1);

namespace BtcPayLite;

interface AdminManagementRepository
{
    /** @return list<array{id:int,email:string,status:string}> */
    public function fetchClients(): array;

    /** @return array{total_stores:int,total_invoices:int,settled_invoices:int,total_btc_volume:string} */
    public function fetchSummary(?int $userId): array;

    /** @return list<array<string,mixed>> */
    public function fetchInvoices(?int $userId, ?string $storeId, ?string $status, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function fetchStores(?int $userId): array;

    /** @return list<array<string,mixed>> */
    public function fetchWebhooks(?int $userId, ?string $storeId): array;
}
