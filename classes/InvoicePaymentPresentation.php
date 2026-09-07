<?php

declare(strict_types=1);

namespace BtcPayLite;

/** Pure projection of persisted invoice state. Never observes or transitions payments. */
final class InvoicePaymentPresentation
{
    public static function fromInvoice(array $invoice): array
    {
        $expected = BitcoinAmount::fromBtc((string) $invoice['amount']);
        $current = max(0, (int) ($invoice['confirmed_balance_sats'] ?? 0) + (int) ($invoice['mempool_delta_sats'] ?? 0));
        $status = (string) $invoice['status'];
        InvoiceStateMachine::assertTransition($status, $status);
        $received = BitcoinAmount::fromSatoshis($current);
        $missing = $status === 'Settled' ? 0 : max(0, $expected->toSatoshis() - $current);
        $metadata = $invoice['metadata'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true, 32, JSON_THROW_ON_ERROR);
        }
        $invoice['metadata'] = is_array($metadata) ? $metadata : [];
        unset($invoice['metadata']['_btcpaylite_electrum_request_id'], $invoice['electrum_request_id']);
        $invoice['amount'] = $expected->toBtcString();
        $invoice['bip21_uri'] = 'bitcoin:' . $invoice['btc_address'] . '?' . http_build_query([
            'amount' => $expected->toBtcString(), 'label' => 'Faktura ' . $invoice['id'],
        ], '', '&', PHP_QUERY_RFC3986);
        return [
            'id' => $invoice['id'],
            'status' => $status,
            'additional_status' => $status !== 'Settled' && $current > 0 && $current < $expected->toSatoshis() ? 'PaidPartial' : 'None',
            'invoice' => $invoice,
            'payment' => [
                'current_balance' => $received->toBtcString(),
                // Existing HTTP field retained as a presentation alias, not cumulative receipts.
                'total_received' => $received->toBtcString(),
                'missing_amount' => BitcoinAmount::fromSatoshis($missing)->toBtcString(),
            ],
        ];
    }
}
