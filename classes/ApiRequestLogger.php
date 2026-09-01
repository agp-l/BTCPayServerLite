<?php

declare(strict_types=1);

namespace BtcPayLite;

final class ApiRequestLogger
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /** @param array<string,mixed> $server */
    public function record(array $server, int $httpStatus, int $durationMs, ?int $createdAt = null): void
    {
        $createdAt ??= time();
        $method = $this->method($server['REQUEST_METHOD'] ?? null);
        $path = $this->requestPath($server['REQUEST_URI'] ?? null);
        $httpStatus = max(100, min(599, $httpStatus));
        $durationMs = max(0, min(3_600_000, $durationMs));
        $clientIp = $this->ip($server['REMOTE_ADDR'] ?? null);
        $integrationName = $this->header($server, 'HTTP_X_BTCPAY_PLUGIN_NAME', 100);
        $integrationVersion = $this->header($server, 'HTTP_X_BTCPAY_PLUGIN_VERSION', 50);
        $shopOrigin = $this->shopOrigin($server['HTTP_X_BTCPAY_SHOP_URL'] ?? null);
        $storeId = $httpStatus < 400 ? $this->existingStoreFromPath($path) : null;

        $pdo = $this->database->getPdo();
        $statement = $pdo->prepare(
            'INSERT INTO api_request_log
                (store_id, method, request_path, http_status, duration_ms, client_ip,
                 integration_name, integration_version, shop_origin, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $storeId,
            $method,
            $path,
            $httpStatus,
            $durationMs,
            $clientIp,
            $integrationName,
            $integrationVersion,
            $shopOrigin,
            $createdAt,
        ]);

        if ($storeId !== null && ($integrationName !== null || $shopOrigin !== null)) {
            $name = $integrationName ?? 'API klient';
            $integrationKey = hash(
                'sha256',
                strtolower($name) . "\0" . ($shopOrigin ?? '')
            );
            $statement = $pdo->prepare(
                'INSERT INTO store_integrations
                    (store_id, integration_key, name, version, shop_origin, first_seen_at, last_seen_at)
                 VALUES (?, UNHEX(?), ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    version = VALUES(version),
                    shop_origin = VALUES(shop_origin),
                    last_seen_at = VALUES(last_seen_at)'
            );
            $statement->execute([
                $storeId,
                $integrationKey,
                $name,
                $integrationVersion,
                $shopOrigin,
                $createdAt,
                $createdAt,
            ]);
        }
    }

    private function existingStoreFromPath(string $path): ?string
    {
        if (!preg_match('/\/api\/v1\/stores\/([A-Za-z0-9_-]{1,50})(?:\/|\z)/D', $path, $matches)) {
            return null;
        }
        $statement = $this->database->getPdo()->prepare(
            'SELECT id FROM stores WHERE id = ? LIMIT 1'
        );
        $statement->execute([$matches[1]]);
        $storeId = $statement->fetchColumn();

        return is_string($storeId) && $storeId !== '' ? $storeId : null;
    }

    private function method(mixed $value): string
    {
        $method = is_string($value) ? strtoupper(trim($value)) : '';
        return preg_match('/\A[A-Z]{1,10}\z/D', $method) ? $method : 'UNKNOWN';
    }

    private function requestPath(mixed $value): string
    {
        $path = is_string($value) ? parse_url($value, PHP_URL_PATH) : null;
        if (!is_string($path) || $path === '') {
            return '/';
        }
        return substr($path, 0, 255);
    }

    private function ip(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false
            ? $value
            : null;
    }

    /** @param array<string,mixed> $server */
    private function header(array $server, string $key, int $maxLength): ?string
    {
        $value = $server[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value)
            || preg_match('//u', $value) !== 1
        ) {
            return null;
        }
        return $value;
    }

    private function shopOrigin(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url(trim($value));
        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }
        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port']) && is_int($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return strlen($origin) <= 255 ? $origin : null;
    }
}
