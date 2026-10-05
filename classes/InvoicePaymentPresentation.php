<?php

declare(strict_types=1);

namespace BtcPayLite;

/** Pure projection of persisted invoice state. Never observes or transitions payments. */
final class InvoicePaymentPresentation
{
    public static function fromInvoice(array $invoice): array
    {
        $expected = BitcoinAmount::fromBtc((string) $invoice['amount']);
        // Migrated maxima are retained only as evidence for the worker. Without
        // an observation timestamp they are not a current-balance snapshot.
        $current = ($invoice['payment_observed_at'] ?? null) === null ? 0
            : max(0, (int) ($invoice['confirmed_balance_sats'] ?? 0) + (int) ($invoice['mempool_delta_sats'] ?? 0));
        $status = (string) $invoice['status'];
        InvoiceStateMachine::assertTransition($status, $status);
        $hasOutputs = ($invoice['payment_observed_at'] ?? null) !== null
            && ($invoice['confirmed_output_sats'] ?? null) !== null && ($invoice['unconfirmed_output_sats'] ?? null) !== null;
        $receivedSats = $hasOutputs ? (int) $invoice['confirmed_output_sats'] + (int) $invoice['unconfirmed_output_sats'] : $current;
        $received = BitcoinAmount::fromSatoshis($receivedSats);
        $missing = $status === 'Settled' ? 0 : max(0, $expected->satoshis() - $receivedSats);
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
            'additional_status' => $status !== 'Settled' && $receivedSats > 0 && $receivedSats < $expected->satoshis() ? 'PaidPartial' : 'None',
            'invoice' => $invoice,
            'payment' => [
                'observed_at' => $invoice['payment_observed_at'] ?? null,
                'current_balance' => BitcoinAmount::fromSatoshis($current)->toBtcString(),
                'observation_kind' => $hasOutputs ? 'received_outputs' : 'current_balance',
                'total_received' => $received->toBtcString(),
                'missing_amount' => BitcoinAmount::fromSatoshis($missing)->toBtcString(),
            ],
        ];
    }
}
