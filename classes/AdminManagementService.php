<?php

declare(strict_types=1);

namespace BtcPayLite;

final class AdminManagementService
{
    private const INVOICE_STATUSES = ['New', 'Processing', 'Settled', 'Expired', 'Invalid'];

    public function __construct(private AdminManagementRepository $repository)
    {
    }

    /** @return list<array{id:int,email:string,status:string}> */
    public function clients(): array
    {
        return $this->repository->fetchClients();
    }

    /** @return array{summary:array<string,mixed>,invoices:list<array<string,mixed>>} */
    public function dashboard(?int $userId): array
    {
        $userId = $this->userId($userId);
        $summary = $this->repository->fetchSummary($userId);
        $summary['settlement_rate'] = $summary['total_invoices'] === 0
            ? 0
            : (int) round(($summary['settled_invoices'] / $summary['total_invoices']) * 100);

        return [
            'summary' => $summary,
            'invoices' => $this->repository->fetchInvoices($userId, null, null, 20),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function stores(?int $userId): array
    {
        return $this->repository->fetchStores($this->userId($userId));
    }

    /** @return list<array<string,mixed>> */
    public function invoices(?int $userId, ?string $storeId, ?string $status): array
    {
        $storeId = $this->identifier($storeId);
        if ($status !== null && !in_array($status, self::INVOICE_STATUSES, true)) {
            throw new AdminOperationsException('Vybraný stav faktury není platný.');
        }

        return $this->repository->fetchInvoices($this->userId($userId), $storeId, $status, 200);
    }

    /** @return list<array<string,mixed>> */
    public function webhooks(?int $userId, ?string $storeId): array
    {
        return $this->repository->fetchWebhooks(
            $this->userId($userId),
            $this->identifier($storeId)
        );
    }

    /** @return list<string> */
    public function invoiceStatuses(): array
    {
        return self::INVOICE_STATUSES;
    }

    private function userId(?int $userId): ?int
    {
        if ($userId !== null && $userId < 0) {
            throw new AdminOperationsException('Vybraný klient není platný.');
        }
        return $userId;
    }

    private function identifier(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) > 50 || !preg_match('/\A[a-zA-Z0-9_-]+\z/D', $value)) {
            throw new AdminOperationsException('Vybraný obchod není platný.');
        }
        return $value;
    }
}
