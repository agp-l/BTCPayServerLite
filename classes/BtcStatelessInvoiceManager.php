<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Portable stateless invoice kernel.
 *
 * This class reserves Electrum payment requests, signs compact invoice tokens
 * and evaluates payments. It deliberately has no Database dependency.
 */
final class BtcStatelessInvoiceManager implements BtcStatelessInvoiceGateway
{
    private const MAX_EXPIRATION_MINUTES = 43_200;
    private const MAX_CUSTOM_DATA_BYTES = 1_024;
    private const MAX_DESCRIPTION_BYTES = 255;

    private const ELECTRUM_STATUS_EXPIRED = 1;
    private const ELECTRUM_STATUS_PAID = 3;
    private const ELECTRUM_STATUS_UNCONFIRMED = 7;
    private const EXPIRED_HARD_CUTOFF_SECONDS = 86_400;

    private ElectrumWallet $wallet;
    private BtcStatelessTokenCodec $tokenCodec;
    private Closure $clock;
    private ?BlockchainProviderInterface $blockchainProvider;

    public function __construct(
        ElectrumWallet $wallet,
        string $secretKey,
        ?callable $clock = null,
        ?BlockchainProviderInterface $blockchainProvider = null
    ) {
        $this->wallet = $wallet;
        $this->tokenCodec = new BtcStatelessTokenCodec($secretKey);
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
        $this->blockchainProvider = $blockchainProvider;
    }

    public function createStatelessInvoice(
        int|float|string $amountBtc,
        string $description,
        array $customData = [],
        int $expirationMinutes = 15
    ): array {
        $amount = $this->requirePositiveAmount($amountBtc);
        $description = $this->validateString(
            $description,
            'Invoice description',
            self::MAX_DESCRIPTION_BYTES
        );
        $expirationSeconds = $this->expirationSeconds($expirationMinutes);

        // Validate caller data before creating a mutable Electrum request.
        $this->encodeJson($customData, 'custom invoice data', self::MAX_CUSTOM_DATA_BYTES);

        $request = $this->reservePaymentRequest($amount, $description, $expirationSeconds);
        $now = ($this->clock)();
        $payload = [
            'ver' => 2,
            'a' => $request['address'],
            'r' => $request['request_id'],
            'v' => $amount->toBtcString(),
            'd' => $description,
            'p' => $customData,
            't' => $now,
            'e' => $now + $expirationSeconds,
        ];

        try {
            $token = $this->tokenCodec->encode($payload);
        } catch (Throwable $exception) {
            $this->removePaymentRequestQuietly($request['request_id']);
            throw $exception;
        }

        return [
            'token' => $token,
            'bip21_uri' => $this->bip21(
                $request['address'],
                $amount->toBtcString(),
                $description
            ),
        ];
    }

    public function decodeStatelessToken(string $token): array
    {
        return $this->tokenCodec->decode($token);
    }

    public function checkStatelessPaymentStatus(string $token): array
    {
        $invoice = $this->decodeStatelessToken($token);
        $expected = $this->requirePositiveAmount((string) $invoice['v']);
        $now = ($this->clock)();
        $isExpired = $now >= (int) $invoice['e'];

        // Fast cutoff: If invoice expired long ago, return expired without contacting Electrum
        if ($now >= (int) $invoice['e'] + self::EXPIRED_HARD_CUTOFF_SECONDS) {
            return [
                'status' => 'expired',
                'is_expired' => true,
                'seconds_remaining' => 0,
                'invoice' => $invoice,
                'payment' => [
                    'received_total' => '0.00000000',
                    'total_received' => '0.00000000',
                    'missing_amount' => $expected->toBtcString(),
                ],
                'bip21_uri' => $this->bip21(
                    (string) $invoice['a'],
                    $expected->toBtcString(),
                    (string) $invoice['d']
                ),
            ];
        }

        $observation = $this->observePayment(
            (string) $invoice['a'],
            isset($invoice['r']) ? (string) $invoice['r'] : null,
            $expected
        );
        $missing = BitcoinAmount::max(
            BitcoinAmount::fromSatoshis(0),
            $expected->subtract($observation['received'])
        );

        return [
            'status' => $this->paymentStatus(
                $observation['electrum_status'],
                $observation['confirmed'],
                $observation['received'],
                $expected,
                $isExpired
            ),
            'is_expired' => $isExpired,
            'seconds_remaining' => $isExpired ? 0 : ((int) $invoice['e'] - $now),
            'invoice' => $invoice,
            'payment' => [
                'received_total' => $observation['received']->toBtcString(),
                'total_received' => $observation['received']->toBtcString(),
                'missing_amount' => $missing->toBtcString(),
            ],
            'bip21_uri' => $this->bip21(
                (string) $invoice['a'],
                $expected->toBtcString(),
                (string) $invoice['d']
            ),
        ];
    }

    /** @return array{address: string, request_id: string} */
    private function reservePaymentRequest(
        BitcoinAmount $amount,
        string $memo,
        int $expirationSeconds
    ): array {
        $request = $this->wallet->createPaymentRequest(
            $amount->toBtcString(),
            $memo,
            $expirationSeconds
        );

        return [
            'address' => $this->validateString(
                (string) ($request['address'] ?? ''),
                'Electrum payment request address',
                100
            ),
            'request_id' => $this->validateString(
                (string) ($request['request_id'] ?? ''),
                'Electrum payment request ID',
                128
            ),
        ];
    }

    /**
     * @return array{electrum_status: int|null, confirmed: BitcoinAmount, received: BitcoinAmount}
     */
    private function observePayment(
        string $address,
        ?string $requestId,
        BitcoinAmount $expected
    ): array {
        $electrumStatus = null;
        if ($requestId !== null && $requestId !== '') {
            $request = $this->wallet->getPaymentRequest($requestId);
            $rawStatus = $request['status'] ?? null;
            if (is_int($rawStatus)) {
                $electrumStatus = $rawStatus;
            } elseif (is_string($rawStatus) && ctype_digit($rawStatus)) {
                $electrumStatus = (int) $rawStatus;
            } else {
                throw new BtcInvoiceManagerException(
                    'Electrum payment request returned an invalid status.',
                    'observe_stateless_payment'
                );
            }
        }

        if ($this->blockchainProvider !== null) {
            $obs = $this->blockchainProvider->observeAddress($address, $expected->toSatoshis());
            $amounts = $obs->toAmountArray();
            $confirmed = $amounts['confirmed'];
            $received = $amounts['received'];
        } else {
            $balance = $this->wallet->getAddressBalanceExact($address);
            try {
                $confirmed = BitcoinAmount::fromBtc($balance['confirmed'] ?? '0');
                $unconfirmed = BitcoinAmount::fromBtc($balance['unconfirmed'] ?? '0');
            } catch (InvalidArgumentException $exception) {
                throw new BtcInvoiceManagerException(
                    'Electrum returned an invalid address balance.',
                    'observe_stateless_payment',
                    previous: $exception
                );
            }

            $zero = BitcoinAmount::fromSatoshis(0);
            $confirmed = BitcoinAmount::max($zero, $confirmed);
            $received = BitcoinAmount::max($zero, $confirmed->add($unconfirmed));
        }
        if ($electrumStatus === self::ELECTRUM_STATUS_PAID) {
            $confirmed = BitcoinAmount::max($confirmed, $expected);
            $received = BitcoinAmount::max($received, $expected);
        } elseif ($electrumStatus === self::ELECTRUM_STATUS_UNCONFIRMED) {
            $received = BitcoinAmount::max($received, $expected);
        }

        return [
            'electrum_status' => $electrumStatus,
            'confirmed' => $confirmed,
            'received' => $received,
        ];
    }

    private function paymentStatus(
        ?int $electrumStatus,
        BitcoinAmount $confirmed,
        BitcoinAmount $received,
        BitcoinAmount $expected,
        bool $isExpired
    ): string {
        if ($electrumStatus === self::ELECTRUM_STATUS_PAID || $confirmed->compare($expected) >= 0) {
            return 'paid';
        }
        if ($electrumStatus === self::ELECTRUM_STATUS_UNCONFIRMED || $received->compare($expected) >= 0) {
            return 'pending_mempool';
        }
        if ($received->isPositive()) {
            return 'underpaid';
        }

        return $isExpired || $electrumStatus === self::ELECTRUM_STATUS_EXPIRED
            ? 'expired'
            : 'unpaid';
    }

    private function expirationSeconds(int $minutes): int
    {
        if ($minutes < 1 || $minutes > self::MAX_EXPIRATION_MINUTES) {
            throw new InvalidArgumentException('Invoice expiration is outside the allowed range.');
        }
        return $minutes * 60;
    }

    private function requirePositiveAmount(int|float|string $amount): BitcoinAmount
    {
        $value = BitcoinAmount::fromBtc($amount);
        if (!$value->isPositive()) {
            throw new InvalidArgumentException('Invoice amount must be greater than zero.');
        }
        return $value;
    }

    private function validateString(string $value, string $field, int $maxBytes): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0") || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException("{$field} is invalid.");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function encodeJson(array $data, string $label, int $maxBytes): string
    {
        try {
            $json = json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Unable to encode {$label}.", 0, $exception);
        }
        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException(ucfirst($label) . ' is too large.');
        }
        return $json;
    }

    private function bip21(string $address, string $amount, string $label): string
    {
        return 'bitcoin:' . $address . '?' . http_build_query(
            ['amount' => $amount, 'label' => $label],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    private function removePaymentRequestQuietly(string $requestId): void
    {
        try {
            $this->wallet->deletePaymentRequest($requestId);
        } catch (Throwable) {
            // Preserve the original token-encoding failure.
        }
    }
}
