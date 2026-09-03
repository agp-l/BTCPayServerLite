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
 * This class reserves Electrum payment requests or generates addresses via XPUB,
 * signs compact invoice tokens, and evaluates payments without requiring database
 * persistence or daemon wallet loading.
 */
final class BtcStatelessInvoiceManager implements BtcStatelessInvoiceGateway
{
    private const MAX_EXPIRATION_MINUTES = 43_200;
    private const MAX_CUSTOM_DATA_BYTES = 1_024;
    private const MAX_DESCRIPTION_BYTES = 255;

    private const ELECTRUM_STATUS_EXPIRED = 1;
    private const ELECTRUM_STATUS_PAID = 3;
    private const ELECTRUM_STATUS_UNCONFIRMED = 7;

    private ?ElectrumWallet $wallet;
    private BtcStatelessTokenCodec $tokenCodec;
    private Closure $clock;
    private ?AddressGeneratorInterface $addressGenerator;
    private ?BlockchainProviderInterface $blockchainProvider;

    public function __construct(
        ?ElectrumWallet $wallet,
        string $secretKey,
        ?callable $clock = null,
        ?AddressGeneratorInterface $addressGenerator = null,
        ?BlockchainProviderInterface $blockchainProvider = null
    ) {
        $this->wallet = $wallet;
        $this->tokenCodec = new BtcStatelessTokenCodec($secretKey);
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
        $this->addressGenerator = $addressGenerator;
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

        // Validate caller data before generating address or request
        $this->encodeJson($customData, 'custom invoice data', self::MAX_CUSTOM_DATA_BYTES);

        $now = ($this->clock)();
        $source = 'electrum';
        $requestId = null;
        $index = null;
        $address = '';

        $preferredSource = is_string($customData['address_source'] ?? null)
            ? strtolower(trim($customData['address_source']))
            : null;

        if ($this->addressGenerator !== null) {
            $context = new AddressGenerationContext(
                'stateless',
                'stateless_' . bin2hex(random_bytes(8)),
                $amount->toSatoshis(),
                $expirationSeconds,
                $preferredSource
            );
            $generated = $this->addressGenerator->generate($context);
            $address = $generated->getAddress();
            $source = $generated->getSource();
            $index = $generated->getIndex();
        } elseif ($this->wallet !== null) {
            $request = $this->reservePaymentRequest($amount, $description, $expirationSeconds);
            $address = $request['address'];
            $requestId = $request['request_id'];
            $source = 'electrum';
        } else {
            throw new BtcInvoiceManagerException(
                'Neither address generator nor wallet available for stateless invoice.',
                'create_stateless_invoice',
                500
            );
        }

        $payload = [
            'ver' => 2,
            'a' => $address,
            's' => $source,
            'v' => $amount->toBtcString(),
            'd' => $description,
            'p' => $customData,
            't' => $now,
            'e' => $now + $expirationSeconds,
        ];

        if ($index !== null) {
            $payload['i'] = $index;
        }
        if ($requestId !== null) {
            $payload['r'] = $requestId;
        }

        try {
            $token = $this->tokenCodec->encode($payload);
        } catch (Throwable $exception) {
            if ($requestId !== null) {
                $this->removePaymentRequestQuietly($requestId);
            }
            throw $exception;
        }

        return [
            'token' => $token,
            'bip21_uri' => $this->bip21(
                $address,
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
        if ($this->wallet === null) {
            throw new BtcInvoiceManagerException('Wallet unavailable for payment request', 'reserve_payment_request', 500);
        }

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
        // Priority 1: BlockchainProvider (no wallet load required, fully cached)
        if ($this->blockchainProvider !== null) {
            try {
                $observation = $this->blockchainProvider->getAddressObservation($address);
                $confirmed = BitcoinAmount::fromSatoshis($observation->getConfirmedSats());
                $unconfirmed = BitcoinAmount::fromSatoshis($observation->getUnconfirmedSats());
                $received = $confirmed->add($unconfirmed);

                return [
                    'electrum_status' => null,
                    'confirmed' => $confirmed,
                    'received' => $received,
                ];
            } catch (Throwable $_ignored) {
            }
        }

        $electrumStatus = null;
        if ($this->wallet !== null) {
            if ($requestId !== null && $requestId !== '') {
                try {
                    $request = $this->wallet->getPaymentRequest($requestId);
                    $rawStatus = $request['status'] ?? null;
                    if (is_int($rawStatus)) {
                        $electrumStatus = $rawStatus;
                    } elseif (is_string($rawStatus) && ctype_digit($rawStatus)) {
                        $electrumStatus = (int) $rawStatus;
                    }
                } catch (Throwable) {
                }
            }

            try {
                $balance = $this->wallet->getAddressBalanceExact($address);
                $confirmed = BitcoinAmount::fromBtc($balance['confirmed'] ?? '0');
                $unconfirmed = BitcoinAmount::fromBtc($balance['unconfirmed'] ?? '0');
            } catch (Throwable $exception) {
                throw new BtcInvoiceManagerException(
                    'Electrum returned an invalid address balance: ' . $exception->getMessage(),
                    'observe_payment',
                    previous: $exception
                );
            }

            $zero = BitcoinAmount::fromSatoshis(0);
            $confirmed = BitcoinAmount::max($zero, $confirmed);
            $received = BitcoinAmount::max($zero, $confirmed->add($unconfirmed));

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

        return [
            'electrum_status' => null,
            'confirmed' => BitcoinAmount::fromSatoshis(0),
            'received' => BitcoinAmount::fromSatoshis(0),
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
            return 'Settled';
        }

        if ($electrumStatus === self::ELECTRUM_STATUS_UNCONFIRMED || $received->compare($expected) >= 0) {
            return 'Processing';
        }

        if ($isExpired) {
            return 'Expired';
        }

        return 'New';
    }

    private function removePaymentRequestQuietly(string $requestId): void
    {
        if ($this->wallet === null) {
            return;
        }

        try {
            $this->wallet->removePaymentRequest($requestId);
        } catch (Throwable) {
        }
    }

    private function requirePositiveAmount(mixed $amount): BitcoinAmount
    {
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            throw new BtcInvoiceManagerException(
                'Invoice amount must be an integer, float, or string.',
                'create_stateless_invoice',
                400
            );
        }

        try {
            $parsed = BitcoinAmount::fromBtc($amount);
        } catch (InvalidArgumentException $exception) {
            throw new BtcInvoiceManagerException(
                'Invalid invoice amount.',
                'create_stateless_invoice',
                400,
                $exception
            );
        }

        if (!$parsed->isPositive()) {
            throw new BtcInvoiceManagerException(
                'Invoice amount must be positive.',
                'create_stateless_invoice',
                400
            );
        }

        return $parsed;
    }

    private function expirationSeconds(int $expirationMinutes): int
    {
        if ($expirationMinutes < 1 || $expirationMinutes > self::MAX_EXPIRATION_MINUTES) {
            throw new BtcInvoiceManagerException(
                'Invoice expiration must be between 1 minute and 30 days.',
                'create_stateless_invoice',
                400
            );
        }

        return $expirationMinutes * 60;
    }

    private function validateString(string $value, string $fieldName, int $maxBytes): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0") || strlen($value) > $maxBytes) {
            throw new BtcInvoiceManagerException(
                "{$fieldName} is invalid.",
                'create_stateless_invoice',
                400
            );
        }

        return $value;
    }

    private function encodeJson(mixed $value, string $fieldName, int $maxBytes): string
    {
        try {
            $json = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new BtcInvoiceManagerException(
                "Unable to encode {$fieldName}.",
                'create_stateless_invoice',
                400,
                $exception
            );
        }

        if (strlen($json) > $maxBytes) {
            throw new BtcInvoiceManagerException(
                ucfirst($fieldName) . ' is too large.',
                'create_stateless_invoice',
                400
            );
        }

        return $json;
    }

    private function bip21(string $address, string $amount, string $message): string
    {
        $query = ['amount' => $amount];
        if ($message !== '') {
            $query['message'] = $message;
        }

        return 'bitcoin:' . $address . '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }
}
