<?php

declare(strict_types=1);

namespace BtcPayLite;

use Closure;

/**
 * Validates webhook destinations and resolves them before a network request.
 *
 * Every resolved address must be public. The explicit localhost names and
 * loopback literals are the only exception, intended for local development.
 */
class WebhookEndpointPolicy
{
    private const MAX_URL_BYTES = 2_048;

    private Closure $resolver;
    private bool $allowLoopback;

    public function __construct(?callable $resolver = null, bool $allowLoopback = false)
    {
        $this->resolver = $resolver === null
            ? Closure::fromCallable([$this, 'resolveHost'])
            : Closure::fromCallable($resolver);
        $this->allowLoopback = $allowLoopback;
    }

    /**
     * @return array{url: string, scheme: string, host: string, port: int, addresses: list<string>}
     */
    public function inspect(string $url): array
    {
        $url = trim($url);
        if (
            $url === ''
            || strlen($url) > self::MAX_URL_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw $this->invalidEndpoint('Webhook URL is invalid.');
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !is_string($parts['scheme'] ?? null)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw $this->invalidEndpoint('Webhook URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = trim(strtolower(rtrim($parts['host'], '.')), '[]');
        if ($host === '') {
            throw $this->invalidEndpoint('Webhook host is invalid.');
        }

        $isLoopbackHost = $this->isLoopback($host);
        if ($isLoopbackHost && !$this->allowLoopback) {
            throw $this->invalidEndpoint('Loopback webhook destinations are not enabled.');
        }
        if (
            $scheme !== 'https'
            && !($scheme === 'http' && $isLoopbackHost && $this->allowLoopback)
        ) {
            throw $this->invalidEndpoint(
                'Webhook URL must use HTTPS (HTTP is allowed only for localhost).'
            );
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!is_int($port) || $port < 1 || $port > 65_535) {
            throw $this->invalidEndpoint('Webhook port is invalid.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } elseif ($host === 'localhost') {
            $addresses = ['127.0.0.1'];
        } else {
            $resolved = ($this->resolver)($host);
            if (!is_array($resolved)) {
                throw new WebhookDeliveryException(
                    'Webhook DNS resolution returned an invalid result.',
                    'resolve_endpoint',
                    true
                );
            }
            $addresses = $this->normalizeAddresses($resolved);
        }

        if ($addresses === []) {
            throw new WebhookDeliveryException(
                'Webhook host could not be resolved.',
                'resolve_endpoint',
                true
            );
        }

        foreach ($addresses as $address) {
            if ($isLoopbackHost && $this->allowLoopback && $this->isLoopback($address)) {
                continue;
            }
            if (!$this->isPublicIp($address)) {
                throw $this->invalidEndpoint('Private or reserved webhook destinations are not allowed.');
            }
        }

        return [
            'url' => $url,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'addresses' => $addresses,
        ];
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param array<mixed> $addresses
     * @return list<string>
     */
    private function normalizeAddresses(array $addresses): array
    {
        $normalized = [];
        foreach ($addresses as $address) {
            if (!is_string($address)) {
                throw new WebhookDeliveryException(
                    'Webhook DNS resolution returned an invalid address.',
                    'resolve_endpoint',
                    true
                );
            }
            $address = trim($address, " \t\n\r\0\x0B[]");
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new WebhookDeliveryException(
                    'Webhook DNS resolution returned an invalid address.',
                    'resolve_endpoint',
                    true
                );
            }
            if (!in_array($address, $normalized, true)) {
                $normalized[] = $address;
            }
        }

        return $normalized;
    }

    private function isLoopback(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function isPublicIp(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }

        // PHP's reserved/private flags have differed for IPv4-mapped IPv6
        // addresses. Classify the embedded IPv4 address explicitly so forms
        // such as ::ffff:127.0.0.1 cannot bypass the SSRF policy.
        if (
            strlen($binary) === 16
            && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
        ) {
            $mappedIpv4 = @inet_ntop(substr($binary, 12));

            return is_string($mappedIpv4) && $this->isPublicIp($mappedIpv4);
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function invalidEndpoint(string $message): WebhookDeliveryException
    {
        return new WebhookDeliveryException($message, 'validate_endpoint');
    }
}
