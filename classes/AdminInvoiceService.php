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
            throw new AdminOperationsException('Částka musí být platný kladný BTC řetězec s nejvýše 8 desetinnými místy.');
        }
        if (!$exactAmount->isPositive()) {
            throw new AdminOperationsException('Částka musí být větší než nula.');
        }

        $description = trim($description);
        $orderId = trim($orderId);
        if (
            $description === ''
            || strlen($description) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $description)
        ) {
            throw new AdminOperationsException('Popis musí obsahovat 1 až 200 platných znaků.');
        }
        if (strlen($orderId) > 100 || preg_match('/[\x00-\x1F\x7F]/', $orderId)) {
            throw new AdminOperationsException('ID objednávky může mít nejvýše 100 platných znaků.');
        }

        $storeId = $storeId !== null ? trim($storeId) : null;
        if ($storeId !== null && !preg_match('/\Astore_[a-f0-9]{32}\z/D', $storeId)) {
            throw new AdminOperationsException('Vybraný obchod má neplatný identifikátor.');
        }

        $store = $storeId === null
            ? $this->repository->fetchDefaultStore()
            : $this->repository->fetchStore($storeId);
        if ($store === null) {
            throw new AdminOperationsException(
                $storeId === null ? 'Nejprve vytvořte alespoň jeden obchod.' : 'Vybraný obchod neexistuje.',
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
            throw new AdminOperationsException('Fakturu se nyní nepodařilo vytvořit.', 503, $exception);
        }

        $id = $invoice['id'] ?? null;
        $createdAt = $invoice['created_at'] ?? null;
        if (!is_string($id) || !preg_match('/\Ainv_[a-f0-9]{32}\z/D', $id)) {
            throw new AdminOperationsException('Faktura vrátila neplatný identifikátor.', 500);
        }
        if (is_string($createdAt) && ctype_digit($createdAt)) {
            $createdAt = (int) $createdAt;
        }
        if (!is_int($createdAt) || $createdAt < 1) {
            throw new AdminOperationsException('Faktura vrátila neplatný čas vytvoření.', 500);
        }

        return [
            'id' => $id,
            'amount' => $exactAmount->toBtcString(),
            'created_at' => $createdAt,
            'description' => $description,
        ];
    }
}
