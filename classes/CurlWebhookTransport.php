<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Sends a single signed webhook request without automatic retries.
 *
 * Retry scheduling belongs to the persistent delivery service. This transport
 * pins a previously validated DNS result to prevent DNS rebinding between URL
 * validation and the connection made by cURL.
 */
class CurlWebhookTransport implements WebhookTransport
{
    private const MAX_PAYLOAD_BYTES = 65_536;
    private const USER_AGENT = 'BTCPayServerLite/Webhook';

    private WebhookEndpointPolicy $endpointPolicy;
    private int $connectTimeoutSeconds;
    private int $timeoutSeconds;

    public function __construct(
        WebhookEndpointPolicy $endpointPolicy,
        int $connectTimeoutSeconds = 5,
        int $timeoutSeconds = 10
    ) {
        if (
            $connectTimeoutSeconds < 1
            || $timeoutSeconds < 1
            || $connectTimeoutSeconds > $timeoutSeconds
            || $timeoutSeconds > 60
        ) {
            throw new WebhookDeliveryException(
                'Webhook transport timeout configuration is invalid.',
                'configure_transport'
            );
        }

        $this->endpointPolicy = $endpointPolicy;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /** @return array{http_status: int, primary_ip: string} */
    public function deliver(string $url, string $payload, string $signature): array
    {
        if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new WebhookDeliveryException('Webhook payload is invalid.', 'prepare_delivery');
        }
        if (!preg_match('/\Asha256=[a-f0-9]{64}\z/D', $signature)) {
            throw new WebhookDeliveryException('Webhook signature is invalid.', 'prepare_delivery');
        }

        $endpoint = $this->endpointPolicy->inspect($url);
        $pinnedAddress = $endpoint['addresses'][0];
        $curl = curl_init($endpoint['url']);
        if ($curl === false) {
            throw new WebhookDeliveryException(
                'Webhook transport could not be initialized.',
                'initialize_transport',
                true
            );
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                'Btcpay-Sig: ' . $signature,
            ],
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk): int {
                // Response bodies are deliberately discarded to avoid an
                // untrusted endpoint consuming application memory.
                return strlen($chunk);
            },
        ];

        if (filter_var($endpoint['host'], FILTER_VALIDATE_IP) === false) {
            $resolveAddress = filter_var($pinnedAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[' . $pinnedAddress . ']'
                : $pinnedAddress;
            $options[CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $endpoint['host'],
                $endpoint['port'],
                $resolveAddress
            )];
        }

        if ($endpoint['scheme'] === 'https') {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        }

        if (!curl_setopt_array($curl, $options)) {
            curl_close($curl);
            throw new WebhookDeliveryException(
                'Webhook transport could not be configured.',
                'configure_transport',
                true
            );
        }

        $sent = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($curl, CURLINFO_PRIMARY_IP);
        curl_close($curl);

        if ($sent === false) {
            throw new WebhookDeliveryException(
                $curlError === ''
                    ? 'Webhook request failed.'
                    : 'Webhook request failed: ' . $curlError,
                'send_webhook',
                true
            );
        }

        if ($primaryIp === '' || !$this->sameIp($pinnedAddress, $primaryIp)) {
            throw new WebhookDeliveryException(
                'Webhook connection used an unexpected IP address.',
                'verify_connection'
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new WebhookDeliveryException(
                "Webhook endpoint returned HTTP {$httpStatus}.",
                'send_webhook',
                $this->isRetryableStatus($httpStatus),
                $httpStatus
            );
        }

        return ['http_status' => $httpStatus, 'primary_ip' => $primaryIp];
    }

    private function sameIp(string $expected, string $actual): bool
    {
        $expectedBinary = @inet_pton($expected);
        $actualBinary = @inet_pton($actual);

        return $expectedBinary !== false
            && $actualBinary !== false
            && hash_equals($expectedBinary, $actualBinary);
    }

    private function isRetryableStatus(int $httpStatus): bool
    {
        return in_array($httpStatus, [408, 425, 429], true)
            || $httpStatus >= 500;
    }
}
