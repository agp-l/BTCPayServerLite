<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use Throwable;

final class AdminInvoiceService
{
    private AdminOperationsRepository $repository;
    private Closure $creator;

    /**
     * @param callable(array{id:string,wallet_path:string},string,array{orderId:string,itemDesc:string}):array<string,mixed> $creator
     */
    public function __construct(AdminOperationsRepository $repository, callable $creator)
    {
        $this->repository = $repository;
        $this->creator = Closure::fromCallable($creator);
    }

    /** @return array{id:string,amount:string,created_at:int,description:string} */
    public function create(string $amount, string $description, string $orderId, ?string $storeId = null): array
    {
        try {
            $exactAmount = BitcoinAmount::fromBtc(trim($amount));
        } catch (Throwable $exception) {
            throw new AdminOperationsException('Amount must be a valid positive BTC string with at most 8 decimal places.');
        }
        if (!$exactAmount->isPositive()) {
            throw new AdminOperationsException('Amount must be greater than zero.');
        }

        $description = trim($description);
        $orderId = trim($orderId);
        if (
            $description === ''
            || strlen($description) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $description)
        ) {
            throw new AdminOperationsException('Description must contain 1 to 200 valid characters.');
        }
        if (strlen($orderId) > 100 || preg_match('/[\x00-\x1F\x7F]/', $orderId)) {
            throw new AdminOperationsException('Order ID can have at most 100 valid characters.');
        }

        $storeId = $storeId !== null ? trim($storeId) : null;
        if ($storeId !== null && !preg_match('/\Astore_[a-f0-9]{32}\z/D', $storeId)) {
            throw new AdminOperationsException('Selected store has an invalid identifier.');
        }

        $store = $storeId === null
            ? $this->repository->fetchDefaultStore()
            : $this->repository->fetchStore($storeId);
        if ($store === null) {
            throw new AdminOperationsException(
                $storeId === null ? 'Create at least one store first.' : 'Selected store does not exist.',
                404
            );
        }

        try {
            $invoice = ($this->creator)(
                $store,
                $exactAmount->toBtcString(),
                ['orderId' => $orderId, 'itemDesc' => $description]
            );
        } catch (AdminOperationsException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AdminOperationsException('Could not create invoice at this time.', 503, $exception);
        }

        $id = $invoice['id'] ?? null;
        $createdAt = $invoice['created_at'] ?? null;
        if (!is_string($id) || !preg_match('/\Ainv_[a-f0-9]{32}\z/D', $id)) {
            throw new AdminOperationsException('Invoice returned an invalid identifier.', 500);
        }
        if (is_string($createdAt) && ctype_digit($createdAt)) {
            $createdAt = (int) $createdAt;
        }
        if (!is_int($createdAt) || $createdAt < 1) {
            throw new AdminOperationsException('Invoice returned an invalid creation time.', 500);
        }

        return [
            'id' => $id,
            'amount' => $exactAmount->toBtcString(),
            'created_at' => $createdAt,
            'description' => $description,
        ];
    }
}
