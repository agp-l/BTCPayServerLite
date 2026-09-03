<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Builds the public view model for one database-backed invoice.
 *
 * The service owns validation and presentation-neutral calculations. It does
 * not know about HTTP, templates, PDO or Electrum transport details.
 */
final class DatabaseCheckoutService
{
    private const STATUSES = ['New', 'Processing', 'Settled', 'Expired'];
    private const ADDITIONAL_STATUSES = ['None', 'PaidPartial'];

    private CheckoutRepository $repository;
    private Closure $paymentStatusLoader;
    private Closure $clock;

    public function __construct(
        CheckoutRepository $repository,
        callable $paymentStatusLoader,
        ?callable $clock = null
    ) {
        $this->repository = $repository;
        $this->paymentStatusLoader = Closure::fromCallable($paymentStatusLoader);
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    /**
     * @return array{
     *   id:string,
     *   store_id:string,
     *   title:string,
     *   status:string,
     *   additional_status:string,
     *   amount:string,
     *   address:string,
     *   bip21_uri:string,
     *   created_at:int,
     *   expires_at:int,
     *   seconds_remaining:int,
     *   total_received:string,
     *   missing_amount:string,
     *   redirect_url:?string,
     *   redirect_automatically:bool
     * }
     */
    public function load(string $invoiceId): array
    {
        $invoiceId = $this->invoiceId($invoiceId);
        $wallet = $this->repository->findInvoiceWallet($invoiceId);
        if ($wallet === null) {
            throw new CheckoutException(
                'Faktura nebyla nalezena.',
                404,
                'find_invoice'
            );
        }
        if ($wallet['id'] !== $invoiceId) {
            throw new CheckoutException(
                'Uložené platební údaje jsou neplatné.',
                500,
                'match_invoice'
            );
        }

        try {
            $result = ($this->paymentStatusLoader)($invoiceId, $wallet['wallet_path']);
        } catch (CheckoutException $exception) {
            throw $exception;
        } catch (BtcInvoiceManagerException $exception) {
            $status = $exception->getCode() === 404 ? 404 : 503;
            throw new CheckoutException(
                $status === 404
                    ? 'Faktura nebyla nalezena.'
                    : 'Stav platby nyní nelze ověřit. Zkuste to prosím znovu.',
                $status,
                'check_payment',
                $exception
            );
        } catch (Throwable $exception) {
            throw new CheckoutException(
                'Stav platby nyní nelze ověřit. Zkuste to prosím znovu.',
                503,
                'check_payment',
                $exception
            );
        }

        return $this->viewModel($invoiceId, $wallet['store_id'], $result);
    }

    private function invoiceId(string $invoiceId): string
    {
        $invoiceId = trim($invoiceId);
        if (!preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $invoiceId)) {
            throw new CheckoutException(
                'ID faktury není platné.',
                400,
                'validate_invoice_id'
            );
        }

        return $invoiceId;
    }

    /**
     * @param mixed $result
     * @return array{
     *   id:string,
     *   store_id:string,
     *   title:string,
     *   status:string,
     *   additional_status:string,
     *   amount:string,
     *   address:string,
     *   bip21_uri:string,
     *   created_at:int,
     *   expires_at:int,
     *   seconds_remaining:int,
     *   total_received:string,
     *   missing_amount:string
     * }
     */
    private function viewModel(string $invoiceId, string $storeId, mixed $result): array
    {
        if (!is_array($result) || !is_array($result['invoice'] ?? null)) {
            throw $this->invalidResult('decode_status');
        }

        $invoice = $result['invoice'];
        $resultId = $this->string($result['id'] ?? null, 'invoice ID', 50);
        $storedId = $this->string($invoice['id'] ?? null, 'invoice ID', 50);
        if ($resultId !== $invoiceId || $storedId !== $invoiceId) {
            throw $this->invalidResult('match_status_invoice');
        }

        $status = $this->enum($result['status'] ?? null, self::STATUSES, 'status');
        $additionalStatus = $this->enum(
            $result['additional_status'] ?? 'None',
            self::ADDITIONAL_STATUSES,
            'additional status'
        );
        $amount = $this->positiveAmount($invoice['amount'] ?? null, 'amount');
        $address = $this->string($invoice['btc_address'] ?? null, 'address', 100);
        if (!preg_match('/\A[A-Za-z0-9]{14,100}\z/D', $address)) {
            throw $this->invalidResult('validate_address');
        }

        $bip21Uri = $this->string($invoice['bip21_uri'] ?? null, 'BIP21 URI', 2048);
        if (!str_starts_with($bip21Uri, 'bitcoin:' . $address . '?')
            || preg_match('/[\x00-\x1F\x7F]/', $bip21Uri) === 1
        ) {
            throw $this->invalidResult('validate_bip21');
        }

        $createdAt = $this->timestamp($invoice['created_at'] ?? null, 'creation time');
        $expiresAt = $this->timestamp($invoice['expires_at'] ?? null, 'expiration time');
        if ($expiresAt <= $createdAt) {
            throw $this->invalidResult('validate_expiration');
        }

        $payment = is_array($result['payment'] ?? null) ? $result['payment'] : [];
        $totalReceived = $this->amount($payment['total_received'] ?? '0', 'received amount');
        $missingAmount = $this->amount($payment['missing_amount'] ?? $amount, 'missing amount');
        $now = ($this->clock)();
        if (!is_int($now) || $now < 1) {
            throw new LogicException('Checkout clock must return a positive Unix timestamp.');
        }

        $title = 'Faktura k úhradě';
        $redirectUrl = null;
        $redirectAutomatically = false;
        $metadata = $invoice['metadata'] ?? [];
        if (is_array($metadata)) {
            $candidateRedirect = $metadata['_btcpaylite_redirect_url'] ?? null;
            if (is_string($candidateRedirect)
                && strlen($candidateRedirect) <= 2_048
                && preg_match('/[\\x00-\\x1F\\x7F]/', $candidateRedirect) !== 1
            ) {
                $redirectParts = parse_url($candidateRedirect);
                if (filter_var($candidateRedirect, FILTER_VALIDATE_URL) !== false
                    && is_array($redirectParts)
                    && in_array(strtolower((string) ($redirectParts['scheme'] ?? '')), ['http', 'https'], true)
                    && is_string($redirectParts['host'] ?? null)
                    && !isset($redirectParts['user'])
                    && !isset($redirectParts['pass'])
                ) {
                    $redirectUrl = $candidateRedirect;
                    $redirectAutomatically = ($metadata['_btcpaylite_redirect_automatic'] ?? false) === true;
                }
            }
            $orderId = $metadata['orderId'] ?? null;
            if (is_int($orderId)) {
                $orderId = (string) $orderId;
            }
            if (is_string($orderId)) {
                $orderId = trim($orderId);
                if ($orderId !== '' && strlen($orderId) <= 100 && !str_contains($orderId, "\0")) {
                    $title = 'Objednávka ' . $orderId;
                }
            }
        }

        return [
            'id' => $invoiceId,
            'store_id' => $storeId,
            'title' => $title,
            'status' => $status,
            'additional_status' => $additionalStatus,
            'amount' => $amount,
            'address' => $address,
            'bip21_uri' => $bip21Uri,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'seconds_remaining' => $status === 'Expired' ? 0 : max(0, $expiresAt - $now),
            'total_received' => $totalReceived,
            'missing_amount' => $missingAmount,
            'redirect_url' => $redirectUrl,
            'redirect_automatically' => $redirectAutomatically,
        ];
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw $this->invalidResult('validate_' . str_replace(' ', '_', $field));
        }

        return $value;
    }

    private function string(mixed $value, string $field, int $maxBytes): string
    {
        if (!is_string($value)) {
            throw $this->invalidResult('validate_' . str_replace(' ', '_', $field));
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes || str_contains($value, "\0")) {
            throw $this->invalidResult('validate_' . str_replace(' ', '_', $field));
        }

        return $value;
    }

    private function timestamp(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $timestamp = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            $timestamp = filter_var($value, FILTER_VALIDATE_INT);
            $timestamp = $timestamp === false ? 0 : $timestamp;
        } else {
            $timestamp = 0;
        }

        if ($timestamp < 1) {
            throw $this->invalidResult('validate_' . str_replace(' ', '_', $field));
        }

        return $timestamp;
    }

    private function positiveAmount(mixed $value, string $field): string
    {
        $amount = $this->bitcoinAmount($value, $field);
        if (!$amount->isPositive()) {
            throw $this->invalidResult('validate_' . str_replace(' ', '_', $field));
        }

        return $amount->toBtcString();
    }

    private function amount(mixed $value, string $field): string
    {
        $amount = $this->bitcoinAmount($value, $field);
        if ($amount->satoshis() < 0) {
            throw $this->invalidResult('validate_' . str_replace(' ', '_', $field));
        }

        return $amount->toBtcString();
    }

    private function bitcoinAmount(mixed $value, string $field): BitcoinAmount
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw $this->invalidResult('validate_' . str_replace(' ', '_', $field));
        }

        try {
            return BitcoinAmount::fromBtc($value);
        } catch (InvalidArgumentException $exception) {
            throw new CheckoutException(
                'Uložené platební údaje jsou neplatné.',
                500,
                'validate_' . str_replace(' ', '_', $field),
                $exception
            );
        }
    }

    private function invalidResult(string $operation): CheckoutException
    {
        return new CheckoutException(
            'Uložené platební údaje jsou neplatné.',
            500,
            $operation
        );
    }
}
