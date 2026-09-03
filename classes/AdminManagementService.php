<?php

declare(strict_types=1);

namespace BtcPayLite;

final class AdminManagementService
{
    private const INVOICE_STATUSES = ['New', 'Processing', 'Settled', 'Expired', 'Invalid'];

    public function __construct(
        private AdminManagementRepository $repository,
        private ?WebhookEndpointPolicy $webhookPolicy = null
    ) {
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
            throw new AdminOperationsException('Selected invoice status is invalid.');
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

    public function renameStore(string $storeId, string $name): void
    {
        $storeId = $this->requiredIdentifier($storeId, 'Store');
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new AdminOperationsException('Store name must contain 1 to 100 valid characters.');
        }
        if (!$this->repository->updateStoreName($storeId, $name)) {
            throw new AdminOperationsException('Store was not found.', 404);
        }
    }

    public function rotateStoreApiKey(string $storeId): void
    {
        $storeId = $this->requiredIdentifier($storeId, 'Store');
        if (!$this->repository->rotateStoreApiKey($storeId, bin2hex(random_bytes(32)))) {
            throw new AdminOperationsException('Store was not found.', 404);
        }
    }

    public function deleteStore(string $storeId): void
    {
        $storeId = $this->requiredIdentifier($storeId, 'Store');
        if (!$this->repository->deleteEmptyStore($storeId)) {
            throw new AdminOperationsException(
                'Store cannot be deleted. It either does not exist or has invoices or payouts that must remain in history.',
                409
            );
        }
    }

    public function changeInvoiceStatus(string $invoiceId, string $status): void
    {
        $invoiceId = $this->requiredIdentifier($invoiceId, 'Invoice');
        if (!in_array($status, self::INVOICE_STATUSES, true) || $status === 'Settled') {
            throw new AdminOperationsException('Manual setting of this invoice status is not permitted.');
        }
        if (!$this->repository->updateInvoiceStatus($invoiceId, $status)) {
            throw new AdminOperationsException(
                'Invoice was not found or is already settled and its status is immutable.',
                409
            );
        }
    }

    public function updateWebhook(string $webhookId, string $url): void
    {
        $webhookId = $this->requiredIdentifier($webhookId, 'Webhook');
        if (!$this->webhookPolicy instanceof WebhookEndpointPolicy) {
            throw new AdminOperationsException('Webhook URL verification is not available.', 500);
        }
        try {
            $endpoint = $this->webhookPolicy->inspect($url);
        } catch (\Throwable $exception) {
            throw new AdminOperationsException('Webhook URL is not secure or cannot be verified.', 400, $exception);
        }
        if (!$this->repository->updateWebhookUrl($webhookId, $endpoint['url'])) {
            throw new AdminOperationsException('Webhook was not found.', 404);
        }
    }

    public function rotateWebhookSecret(string $webhookId): void
    {
        $webhookId = $this->requiredIdentifier($webhookId, 'Webhook');
        if (!$this->repository->rotateWebhookSecret($webhookId, bin2hex(random_bytes(32)))) {
            throw new AdminOperationsException('Webhook was not found.', 404);
        }
    }

    private function userId(?int $userId): ?int
    {
        if ($userId !== null && $userId < 0) {
            throw new AdminOperationsException('Selected client is invalid.');
        }
        return $userId;
    }

    private function identifier(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) > 50 || !preg_match('/\A[a-zA-Z0-9_-]+\z/D', $value)) {
            throw new AdminOperationsException('Selected store is invalid.');
        }
        return $value;
    }

    private function requiredIdentifier(string $value, string $field): string
    {
        $value = trim($value);
        $validated = $this->identifier($value);
        if ($validated === null) {
            throw new AdminOperationsException($field . ' has an invalid identifier.');
        }
        return $validated;
    }
}
