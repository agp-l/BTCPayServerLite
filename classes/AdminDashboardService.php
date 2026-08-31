<?php

declare(strict_types=1);

namespace BtcPayLite;

final class AdminDashboardService
{
    private const RECENT_INVOICE_LIMIT = 20;

    private AdminDashboardRepository $repository;

    public function __construct(AdminDashboardRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array{
     *   summary:array{total_stores:int,total_invoices:int,settled_invoices:int,total_btc_volume:string,settlement_rate:int},
     *   invoices:list<array{id:string,store_id:string,store_name:string,amount:string,status:string,created_at:int}>
     * }
     */
    public function load(): array
    {
        $summary = $this->repository->fetchSummary();
        $summary['settlement_rate'] = $summary['total_invoices'] === 0
            ? 0
            : (int) round(($summary['settled_invoices'] / $summary['total_invoices']) * 100);

        return [
            'summary' => $summary,
            'invoices' => $this->repository->fetchRecentInvoices(self::RECENT_INVOICE_LIMIT),
        ];
    }
}
