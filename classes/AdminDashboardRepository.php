<?php

declare(strict_types=1);

namespace BtcPayLite;

interface AdminDashboardRepository
{
    /**
     * @return array{total_stores:int,total_invoices:int,settled_invoices:int,total_btc_volume:string}
     */
    public function fetchSummary(): array;

    /**
     * @return list<array{id:string,store_id:string,store_name:string,amount:string,status:string,created_at:int}>
     */
    public function fetchRecentInvoices(int $limit): array;
}
