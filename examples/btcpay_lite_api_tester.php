<?php

declare(strict_types=1);

/**
 * BTCPay Server Lite – standalone API integration tester and CMS example.
 *
 * Upload this ONE file to a separate HTTPS hosting account, edit the CONFIG
 * block below and open it in a browser. Read-only and write operations are
 * submitted by POST and every result is printed as formatted JSON containing:
 *   - the exact HTTP method, URL, safe headers and JSON body,
 *   - a reusable cURL command with credential placeholders,
 *   - HTTP status, response headers and decoded server JSON.
 *
 * SECURITY:
 *   - Never publish this file without changing tester_password.
 *   - Keep enable_live_payout_actions false until you intentionally test BTC sending.
 *   - Store and payout API keys MUST be different keys.
 *   - Delete the file from public hosting after testing.
 */

$CONFIG = [
    // BTCPay Server Lite installation URL, including a subdirectory when used.
    'btcpay_url' => 'https://pay.example.com',
    'store_id' => 'replace-with-store-id',
    'store_api_key' => 'replace-with-store-api-key',

    // Payouts use a separate key configured in payout_api_keys[store_id].
    'payout_api_key' => 'replace-with-separate-payout-api-key',

    // Optional key from config.php api_clients for the stateless /api endpoint.
    'stateless_api_key' => 'replace-with-stateless-api-key',

    // Protects this tester's browser interface with HTTP Basic authentication.
    'tester_username' => 'btcpay-tester',
    'tester_password' => 'CHANGE-THIS-TO-A-LONG-RANDOM-PASSWORD',

    // Integration telemetry visible to the store administrator.
    'plugin_name' => 'Example CMS Connector',
    'plugin_version' => '0.1.0',
    'shop_url' => 'https://shop.example.com',

    // Public HTTPS URL of this same file with ?webhook=1 appended.
    'webhook_url' => 'https://shop.example.com/btcpay_lite_api_tester.php?webhook=1',
    'webhook_secret' => 'replace-with-random-webhook-secret-at-least-16-characters',

    // A payout with approved=true or a payout approval can broadcast real BTC.
    'enable_live_payout_actions' => false,
];

const TESTER_MAX_RESPONSE_BYTES = 1_048_576;
const TESTER_MAX_WEBHOOK_BYTES = 65_536;

final class BtcPayLiteExampleClient
{
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return $this->request('GET', '/api/v1/health', 'none');
    }

    /** @return array<string,mixed> */
    public function serverInfo(): array
    {
        return $this->request('GET', '/api/v1/server/info', 'store');
    }

    /** @return array<string,mixed> */
    public function currentApiKey(): array
    {
        return $this->request('GET', '/api/v1/api-keys/current', 'store');
    }

    /** @return array<string,mixed> */
    public function store(): array
    {
        return $this->request('GET', $this->storePath(), 'store');
    }

    /** @return array<string,mixed> */
    public function storePaymentMethods(): array
    {
        return $this->request('GET', $this->storePath() . '/payment-methods', 'store');
    }

    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    public function createInvoice(array $invoice): array
    {
        return $this->request('POST', $this->storePath() . '/invoices', 'store', $invoice);
    }

    /** @return array<string,mixed> */
    public function getInvoice(string $invoiceId): array
    {
        return $this->request(
            'GET',
            $this->storePath() . '/invoices/' . rawurlencode($this->identifier($invoiceId, 'invoice ID')),
            'store'
        );
    }

    /** @return array<string,mixed> */
    public function invoicePaymentMethods(string $invoiceId): array
    {
        return $this->request(
            'GET',
            $this->storePath() . '/invoices/'
                . rawurlencode($this->identifier($invoiceId, 'invoice ID'))
                . '/payment-methods',
            'store'
        );
    }

    /** @return array<string,mixed> */
    public function exchangeQuote(string $amount, string $currency): array
    {
        return $this->request('POST', $this->storePath() . '/exchange/quotes', 'store', [
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ]);
    }

    /** @return array<string,mixed> */
    public function listWebhooks(): array
    {
        return $this->request('GET', $this->storePath() . '/webhooks', 'store');
    }

    /** @return array<string,mixed> */
    public function createWebhook(string $url, string $secret): array
    {
        return $this->request('POST', $this->storePath() . '/webhooks', 'store', [
            'url' => $url,
            'secret' => $secret,
        ]);
    }

    /** @return array<string,mixed> */
    public function listPayouts(): array
    {
        return $this->request('GET', $this->storePath() . '/payouts', 'payout');
    }

    /** @param array<string,mixed> $payout @return array<string,mixed> */
    public function createPayout(array $payout, string $idempotencyKey): array
    {
        return $this->request('POST', $this->storePath() . '/payouts', 'payout', $payout, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /** @return array<string,mixed> */
    public function getPayout(string $payoutId): array
    {
        return $this->request(
            'GET',
            '/api/v1/payouts/' . rawurlencode($this->payoutIdentifier($payoutId)),
            'payout'
        );
    }

    /** @return array<string,mixed> */
    public function approvePayout(string $payoutId, int $revision): array
    {
        return $this->request(
            'POST',
            '/api/v1/payouts/' . rawurlencode($this->payoutIdentifier($payoutId)),
            'payout',
            ['revision' => $revision]
        );
    }

    /** @return array<string,mixed> */
    public function createStatelessInvoice(array $invoice): array
    {
        return $this->request('POST', '/api', 'stateless', $invoice);
    }

    /**
     * This method is the reusable HTTP core a CMS plugin can adapt.
     *
     * @param array<string,mixed>|null $jsonBody
     * @param array<string,string> $extraHeaders
     * @return array<string,mixed>
     */
    public function request(
        string $method,
        string $path,
        string $authType,
        ?array $jsonBody = null,
        array $extraHeaders = []
    ): array {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP extension cURL is missing.');
        }

        $method = strtoupper($method);
        $url = rtrim((string) $this->config['btcpay_url'], '/') . '/' . ltrim($path, '/');
        $headers = [
            'Accept' => 'application/json',
            'X-BTCPay-Plugin-Name' => (string) $this->config['plugin_name'],
            'X-BTCPay-Plugin-Version' => (string) $this->config['plugin_version'],
            'X-BTCPay-Shop-URL' => (string) $this->config['shop_url'],
        ];

        $credentialPlaceholder = null;
        if ($authType !== 'none') {
            if ($authType === 'payout') {
                $key = (string) $this->config['payout_api_key'];
                $credentialPlaceholder = '<PAYOUT_API_KEY>';
                $headers['Authorization'] = 'token ' . $key;
            } elseif ($authType === 'stateless') {
                $key = (string) $this->config['stateless_api_key'];
                $credentialPlaceholder = '<STATELESS_API_KEY>';
                $headers['Authorization'] = 'Bearer ' . $key;
            } else {
                $key = (string) $this->config['store_api_key'];
                $credentialPlaceholder = '<STORE_API_KEY>';
                $headers['Authorization'] = 'token ' . $key;
            }
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }

        $encodedBody = null;
        if ($jsonBody !== null) {
            $encodedBody = json_encode(
                $jsonBody,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $headers['Content-Type'] = 'application/json';
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $responseBody = '';
        $responseHeaders = [];
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('cURL could not be initialized.');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'BTCPayLite-Integration-Tester/1.0',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[trim($name)] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > TESTER_MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($encodedBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $encodedBody;
        }
        if (!curl_setopt_array($curl, $options)) {
            curl_close($curl);
            throw new RuntimeException('cURL options could not be configured.');
        }

        $startedAt = microtime(true);
        $executed = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($curl, CURLINFO_PRIMARY_IP);
        curl_close($curl);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $safeHeaders = $headers;
        if ($credentialPlaceholder !== null) {
            $safeHeaders['Authorization'] = ($authType === 'stateless' ? 'Bearer ' : 'token ')
                . $credentialPlaceholder;
        }
        $safeBody = self::redact($jsonBody);
        $decoded = null;
        if ($responseBody !== '') {
            try {
                $decoded = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $decoded = null;
            }
        }

        return [
            'ok' => $executed !== false && $status >= 200 && $status < 300,
            'request' => [
                'method' => $method,
                'url' => $url,
                'headers' => $safeHeaders,
                'json' => $safeBody,
                'curl_example' => $this->curlExample($method, $url, $safeHeaders, $safeBody),
            ],
            'response' => [
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'primary_ip' => $primaryIp,
                'headers' => $responseHeaders,
                'json' => $decoded === null ? null : self::redact($decoded),
                'raw_body' => $decoded === null ? $responseBody : null,
                'curl_error' => $executed === false ? $curlError : null,
            ],
        ];
    }

    /** @return mixed */
    public static function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/\A(?:apiKey|secret|password|rawTransaction)\z/iD', $key)) {
                $result[$key] = '<redacted>';
            } else {
                $result[$key] = self::redact($item);
            }
        }
        return $result;
    }

    /** @param array<string,string> $headers */
    private function curlExample(string $method, string $url, array $headers, mixed $body): string
    {
        $parts = ['curl', '-X', $method, escapeshellarg($url)];
        foreach ($headers as $name => $value) {
            $parts[] = '-H';
            $parts[] = escapeshellarg($name . ': ' . $value);
        }
        if ($body !== null) {
            $parts[] = '--data';
            $parts[] = escapeshellarg((string) json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        }
        return implode(' ', $parts);
    }

    private function storePath(): string
    {
        return '/api/v1/stores/' . rawurlencode($this->identifier(
            (string) $this->config['store_id'],
            'store ID'
        ));
    }

    private function identifier(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D', $value)) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
        return $value;
    }

    private function payoutIdentifier(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/\Apo_[0-9a-f]{32}\z/D', $value)) {
            throw new InvalidArgumentException('Payout ID is invalid.');
        }
        return $value;
    }
}

/** @param array<string,mixed> $payload */
function testerJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

/** @param array<string,mixed> $config */
function testerValidateConfig(array $config): array
{
    $errors = [];
    $url = rtrim(trim((string) ($config['btcpay_url'] ?? '')), '/');
    $parts = parse_url($url);
    if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        $errors[] = 'Set a valid btcpay_url without credentials.';
    }
    foreach (['store_id', 'store_api_key'] as $key) {
        $value = trim((string) ($config[$key] ?? ''));
        if ($value === '' || str_contains($value, 'replace-with-')) {
            $errors[] = 'Set ' . $key . ' in the CONFIG block.';
        }
    }
    $password = (string) ($config['tester_password'] ?? '');
    if (strlen($password) < 16 || str_contains($password, 'CHANGE-THIS')) {
        $errors[] = 'Change tester_password to a random value of at least 16 characters.';
    }
    if (!extension_loaded('curl')) {
        $errors[] = 'Install/enable the PHP cURL extension.';
    }
    return $errors;
}

/** @return array{0:string,1:string} */
function testerBasicCredentials(): array
{
    $user = is_string($_SERVER['PHP_AUTH_USER'] ?? null) ? $_SERVER['PHP_AUTH_USER'] : '';
    $password = is_string($_SERVER['PHP_AUTH_PW'] ?? null) ? $_SERVER['PHP_AUTH_PW'] : '';
    if ($user !== '' || $password !== '') {
        return [$user, $password];
    }
    $authorization = is_string($_SERVER['HTTP_AUTHORIZATION'] ?? null)
        ? $_SERVER['HTTP_AUTHORIZATION']
        : (is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null)
            ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            : '');
    if (preg_match('/\ABasic[ \t]+([^\s]+)\z/iD', trim($authorization), $matches)) {
        $decoded = base64_decode($matches[1], true);
        if (is_string($decoded) && str_contains($decoded, ':')) {
            return explode(':', $decoded, 2);
        }
    }
    return ['', ''];
}

/** @param array<string,mixed> $config */
function testerHandleWebhook(array $config): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        header('Allow: POST');
        testerJson(['ok' => false, 'message' => 'Webhook receiver accepts POST only.'], 405);
    }
    $secret = (string) ($config['webhook_secret'] ?? '');
    if (strlen($secret) < 16 || str_contains($secret, 'replace-with-')) {
        testerJson(['ok' => false, 'message' => 'Configure webhook_secret first.'], 503);
    }
    $rawBody = file_get_contents('php://input', false, null, 0, TESTER_MAX_WEBHOOK_BYTES + 1);
    if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > TESTER_MAX_WEBHOOK_BYTES) {
        testerJson(['ok' => false, 'message' => 'Webhook body is invalid or too large.'], 400);
    }
    $signature = is_string($_SERVER['HTTP_BTCPAY_SIG'] ?? null)
        ? trim($_SERVER['HTTP_BTCPAY_SIG'])
        : '';
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    if (!preg_match('/\Asha256=[a-f0-9]{64}\z/D', $signature)
        || !hash_equals($expected, $signature)) {
        testerJson(['ok' => false, 'message' => 'Webhook HMAC signature is invalid.'], 401);
    }
    try {
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        testerJson(['ok' => false, 'message' => 'Webhook body is not valid JSON.'], 400);
    }
    if (!is_array($payload)) {
        testerJson(['ok' => false, 'message' => 'Webhook JSON must be an object.'], 400);
    }
    $record = [
        'received_at' => gmdate('c'),
        'signature_verified' => true,
        'payload' => $payload,
    ];
    $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents(testerWebhookLogPath(), $encoded, LOCK_EX) === false) {
        testerJson(['ok' => false, 'message' => 'Webhook was valid but could not be saved.'], 500);
    }
    testerJson(['ok' => true, 'message' => 'Webhook accepted.', 'event' => $record], 200);
}

function testerWebhookLogPath(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'btcpay-lite-api-tester-'
        . substr(hash('sha256', __FILE__), 0, 24) . '.json';
}

/** @return array<string,mixed> */
function testerCatalog(): array
{
    return [
        'greenfield' => [
            'GET /api/v1/health' => 'Public health check',
            'GET /api/v1/server/info' => 'Server capabilities',
            'GET /api/v1/api-keys/current' => 'Current key permissions',
            'GET /api/v1/stores/{storeId}' => 'Store settings',
            'GET /api/v1/stores/{storeId}/payment-methods' => 'Enabled payment methods',
            'POST /api/v1/stores/{storeId}/exchange/quotes' => 'Fiat/BTC quote',
            'POST /api/v1/stores/{storeId}/invoices' => 'Create invoice',
            'GET /api/v1/stores/{storeId}/invoices/{invoiceId}' => 'Invoice status',
            'GET /api/v1/stores/{storeId}/invoices/{invoiceId}/payment-methods' => 'Invoice BTC destination and due amount',
            'GET /api/v1/stores/{storeId}/webhooks' => 'List webhooks',
            'POST /api/v1/stores/{storeId}/webhooks' => 'Register webhook',
        ],
        'payouts_separate_key' => [
            'GET /api/v1/stores/{storeId}/payouts' => 'List payouts',
            'POST /api/v1/stores/{storeId}/payouts' => 'Create idempotent payout',
            'GET /api/v1/payouts/{payoutId}' => 'Payout state',
            'POST /api/v1/payouts/{payoutId}' => 'Approve and broadcast payout',
        ],
        'stateless' => [
            'POST /api' => 'Create signed invoice without a database invoice row',
        ],
        'webhook_receiver' => [
            'POST this-file.php?webhook=1' => 'Verify Btcpay-Sig HMAC over the unchanged raw JSON body',
        ],
    ];
}

/** @param array<string,mixed> $config @return array<string,mixed> */
function testerRunAction(string $action, array $input, array $config): array
{
    $client = new BtcPayLiteExampleClient($config);
    $amount = trim((string) ($input['amount'] ?? '0.00010000'));
    $currency = strtoupper(trim((string) ($input['currency'] ?? 'BTC')));
    $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
    $payoutId = trim((string) ($input['payout_id'] ?? ''));
    $revision = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT);
    $orderId = trim((string) ($input['order_id'] ?? ('ORDER-' . gmdate('Ymd-His'))));

    switch ($action) {
        case 'catalog':
            return ['ok' => true, 'catalog' => testerCatalog()];
        case 'diagnostics':
            $checks = [
                'health' => $client->health(),
                'server_info' => $client->serverInfo(),
                'current_api_key' => $client->currentApiKey(),
                'store' => $client->store(),
                'store_payment_methods' => $client->storePaymentMethods(),
                'webhooks' => $client->listWebhooks(),
            ];
            if (!str_contains((string) $config['payout_api_key'], 'replace-with-')) {
                $checks['payouts'] = $client->listPayouts();
            }
            return ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks];
        case 'health': return $client->health();
        case 'server_info': return $client->serverInfo();
        case 'current_api_key': return $client->currentApiKey();
        case 'store': return $client->store();
        case 'store_payment_methods': return $client->storePaymentMethods();
        case 'exchange_quote': return $client->exchangeQuote($amount, $currency);
        case 'create_invoice':
            $checkout = ['expirationMinutes' => (string) ($input['expiration_minutes'] ?? '15')];
            $redirectUrl = trim((string) ($input['redirect_url'] ?? ''));
            if ($redirectUrl !== '') {
                $checkout['redirectURL'] = $redirectUrl;
                $checkout['redirectAutomatically'] = ($input['redirect_automatically'] ?? null) === '1';
            }
            return $client->createInvoice([
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => ['orderId' => $orderId, 'source' => 'standalone-api-tester'],
                'checkout' => $checkout,
            ]);
        case 'get_invoice': return $client->getInvoice($invoiceId);
        case 'invoice_payment_methods': return $client->invoicePaymentMethods($invoiceId);
        case 'list_webhooks': return $client->listWebhooks();
        case 'create_webhook':
            $webhookSecret = trim((string) ($input['webhook_secret'] ?? ''));
            if ($webhookSecret === '') {
                $webhookSecret = (string) $config['webhook_secret'];
            }
            return $client->createWebhook(
                trim((string) ($input['webhook_url'] ?? $config['webhook_url'])),
                $webhookSecret
            );
        case 'last_webhook':
            $path = testerWebhookLogPath();
            $stored = is_file($path) ? file_get_contents($path) : false;
            return [
                'ok' => is_string($stored),
                'event' => is_string($stored) ? json_decode($stored, true) : null,
                'message' => is_string($stored) ? 'Last verified webhook.' : 'No verified webhook received yet.',
            ];
        case 'list_payouts': return $client->listPayouts();
        case 'get_payout': return $client->getPayout($payoutId);
        case 'create_payout':
        case 'create_and_send_payout':
            $sendNow = $action === 'create_and_send_payout';
            testerRequireLivePayoutConfirmation($sendNow, $input, $config);
            $feeRate = trim((string) ($input['fee_rate'] ?? ''));
            $payload = [
                'destination' => trim((string) ($input['destination'] ?? '')),
                'amount' => $amount,
                'currency' => $currency,
                'payoutMethodId' => 'BTC-CHAIN',
                'approved' => $sendNow,
                'metadata' => ['orderId' => $orderId, 'source' => 'standalone-api-tester'],
            ];
            if ($feeRate !== '') {
                $payload['feeRate'] = $feeRate;
            }
            return $client->createPayout(
                $payload,
                trim((string) ($input['idempotency_key'] ?? ''))
            );
        case 'approve_payout':
            testerRequireLivePayoutConfirmation(true, $input, $config);
            if (!is_int($revision) || $revision < 0) {
                throw new InvalidArgumentException('Payout revision must be a non-negative integer.');
            }
            return $client->approvePayout($payoutId, $revision);
        case 'stateless_invoice':
            return $client->createStatelessInvoice([
                'amount' => $amount,
                'description' => trim((string) ($input['description'] ?? 'Test stateless invoice')),
                'order_id' => $orderId,
                'expiration_minutes' => (string) ($input['expiration_minutes'] ?? '15'),
            ]);
        default:
            throw new InvalidArgumentException('Unknown tester action.');
    }
}

/** @param array<string,mixed> $input @param array<string,mixed> $config */
function testerRequireLivePayoutConfirmation(bool $liveAction, array $input, array $config): void
{
    if (!$liveAction) {
        return;
    }
    if (($config['enable_live_payout_actions'] ?? false) !== true) {
        throw new RuntimeException('Live payout actions are disabled in CONFIG.');
    }
    if (!hash_equals('SEND REAL BTC', trim((string) ($input['payout_confirmation'] ?? '')))) {
        throw new RuntimeException('Type SEND REAL BTC into the confirmation field.');
    }
}

if (($GLOBALS['BTCPAY_LITE_TESTER_LIBRARY_ONLY'] ?? false) === true) {
    return;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

if (($_GET['webhook'] ?? null) === '1') {
    testerHandleWebhook($CONFIG);
}

$configurationErrors = testerValidateConfig($CONFIG);
if ($configurationErrors !== []) {
    testerJson([
        'ok' => false,
        'setup_required' => true,
        'message' => 'Edit the CONFIG block at the top of this file before use.',
        'errors' => $configurationErrors,
    ], 503);
}

[$basicUser, $basicPassword] = testerBasicCredentials();
if (!hash_equals((string) $CONFIG['tester_username'], $basicUser)
    || !hash_equals((string) $CONFIG['tester_password'], $basicPassword)) {
    header('WWW-Authenticate: Basic realm="BTCPay Lite API Tester", charset="UTF-8"');
    testerJson(['ok' => false, 'message' => 'HTTP Basic authentication is required.'], 401);
}

session_name('BTCPAYLITETESTER');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (!session_start()) {
    testerJson(['ok' => false, 'message' => 'Tester session could not be started.'], 500);
}
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        testerJson(['ok' => false, 'message' => 'Invalid CSRF token. Reload the tester.'], 403);
    }
    try {
        $result = testerRunAction(trim((string) ($_POST['action'] ?? '')), $_POST, $CONFIG);
        testerJson([
            'tester_action' => (string) ($_POST['action'] ?? ''),
            'executed_at' => gmdate('c'),
            'result' => $result,
        ], ($result['ok'] ?? false) ? 200 : 502);
    } catch (Throwable $exception) {
        testerJson([
            'ok' => false,
            'tester_action' => (string) ($_POST['action'] ?? ''),
            'error' => $exception->getMessage(),
            'error_type' => $exception::class,
        ], 400);
    }
}
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD, POST');
    testerJson(['ok' => false, 'message' => 'Only GET, HEAD and POST are allowed.'], 405);
}

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$csrf = $escape($_SESSION['csrf_token']);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>BTCPay Lite API Tester</title>
  <style>
    :root{color-scheme:dark;--bg:#08090d;--card:#12141b;--line:#2a2e3a;--text:#f5f7fb;--muted:#9ba4b7;--accent:#a855f7;--danger:#ff6b81}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 20% 0,rgba(168,85,247,.15),transparent 35rem),var(--bg);color:var(--text);font:14px/1.5 Inter,system-ui,sans-serif}.shell{width:min(1120px,calc(100% - 28px));margin:0 auto;padding:45px 0 70px}h1{margin:4px 0;font-size:clamp(28px,5vw,44px);letter-spacing:-.04em}.eyebrow{color:#c084fc;font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.lead{max-width:760px;color:var(--muted)}.warning{margin:22px 0;padding:15px 17px;border:1px solid rgba(255,107,129,.35);border-radius:14px;background:rgba(255,107,129,.08);color:#ff9bac}.card{margin-top:18px;padding:25px;border:1px solid var(--line);border-radius:20px;background:rgba(18,20,27,.96)}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}.field{display:grid;gap:7px}.field span{color:#c8cfdd;font-size:11px;font-weight:750}.field input,.field select{width:100%;min-height:45px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;outline:0;background:#0b0d12;color:var(--text)}.field input:focus,.field select:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(168,85,247,.12)}.wide{grid-column:span 2}.full{grid-column:1/-1}.check{display:flex;align-items:center;gap:9px;padding-top:24px;color:var(--muted)}.check input{width:18px;height:18px;accent-color:var(--accent)}.actions{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:22px;padding-top:20px;border-top:1px solid var(--line)}button{min-height:46px;padding:11px 18px;border:0;border-radius:11px;background:linear-gradient(145deg,#b267f8,#7e22ce);color:white;font-weight:800;cursor:pointer}.hint{color:var(--muted);font-size:11px}code{color:#d8b4fe}@media(max-width:800px){.grid{grid-template-columns:1fr 1fr}.wide{grid-column:auto}}@media(max-width:560px){.shell{padding-top:25px}.card{padding:19px 15px}.grid{grid-template-columns:1fr}.wide,.full{grid-column:auto}.actions{align-items:stretch;flex-direction:column}button{width:100%}}
  </style>
</head>
<body>
<main class="shell">
  <span class="eyebrow">Standalone CMS integration example</span>
  <h1>BTCPay Lite API Tester</h1>
  <p class="lead">Vyberte operaci a odešlete formulář. Výsledkem bude čistý JSON s přesným HTTP požadavkem, bezpečně skrytými credentials, znovupoužitelným cURL příkladem a odpovědí serveru.</p>
  <div class="warning"><strong>Pozor na výběry:</strong> akce „create and send“ a „approve payout“ mohou po explicitním povolení v CONFIG skutečně odeslat BTC. Nejdříve používejte testnet/regtest a nízké limity.</div>

  <form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
    <div class="grid">
      <label class="field full"><span>API operace</span><select name="action" required>
        <optgroup label="Přehled a diagnostika">
          <option value="catalog">Katalog všech endpointů (bez volání)</option>
          <option value="diagnostics">Spustit všechny bezpečné read-only kontroly</option>
          <option value="health">Health</option><option value="server_info">Server info</option>
          <option value="current_api_key">API key permissions</option><option value="store">Store</option>
          <option value="store_payment_methods">Store payment methods</option>
        </optgroup>
        <optgroup label="Faktury a kurz">
          <option value="exchange_quote">Exchange quote</option><option value="create_invoice">Create invoice</option>
          <option value="get_invoice">Get invoice</option><option value="invoice_payment_methods">Invoice payment methods</option>
          <option value="stateless_invoice">Create stateless invoice</option>
        </optgroup>
        <optgroup label="Webhooky"><option value="list_webhooks">List webhooks</option><option value="create_webhook">Create webhook</option><option value="last_webhook">Show last verified webhook</option></optgroup>
        <optgroup label="Výběry – samostatný payout klíč"><option value="list_payouts">List payouts</option><option value="get_payout">Get payout</option><option value="create_payout">Create payout awaiting approval</option><option value="create_and_send_payout">Create and send payout</option><option value="approve_payout">Approve and send existing payout</option></optgroup>
      </select></label>
      <label class="field"><span>Částka (desetinný řetězec)</span><input name="amount" value="0.00010000"></label>
      <label class="field"><span>Měna</span><input name="currency" value="BTC" maxlength="5"></label>
      <label class="field"><span>Order ID / reference</span><input name="order_id" value="ORDER-<?php echo gmdate('Ymd-His'); ?>"></label>
      <label class="field"><span>Invoice ID</span><input name="invoice_id" placeholder="inv_..."></label>
      <label class="field"><span>Expirace v minutách</span><input name="expiration_minutes" type="number" min="10" value="15"></label>
      <label class="field"><span>Popis stateless faktury</span><input name="description" value="Testovací objednávka"></label>
      <label class="field wide"><span>Redirect URL po platbě</span><input name="redirect_url" type="url" placeholder="https://shop.example.com/order/thank-you"></label>
      <label class="check"><input name="redirect_automatically" type="checkbox" value="1"> Automatický redirect</label>
      <label class="field wide"><span>Webhook URL</span><input name="webhook_url" type="url" value="<?php echo $escape((string) $CONFIG['webhook_url']); ?>"></label>
      <label class="field"><span>Webhook secret</span><input name="webhook_secret" type="password" placeholder="Použije se hodnota z CONFIG"></label>
      <label class="field full"><span>BTC destination pro výběr</span><input name="destination" placeholder="bc1q..."></label>
      <label class="field"><span>Payout ID</span><input name="payout_id" placeholder="po_32hex..."></label>
      <label class="field"><span>Payout revision</span><input name="revision" type="number" min="0" value="0"></label>
      <label class="field"><span>Fee rate sat/vbyte (volitelné)</span><input name="fee_rate" type="number" min="1" max="10000"></label>
      <label class="field wide"><span>Idempotency-Key (16–128 bezpečných znaků)</span><input name="idempotency_key" value="exchange-order-<?php echo gmdate('YmdHis'); ?>"></label>
      <label class="field"><span>Potvrzení živého výběru</span><input name="payout_confirmation" placeholder="SEND REAL BTC" autocomplete="off"></label>
    </div>
    <div class="actions"><span class="hint">Authorization se ve výsledku zobrazí pouze jako <code>&lt;STORE_API_KEY&gt;</code> nebo <code>&lt;PAYOUT_API_KEY&gt;</code>.</span><button type="submit">Odeslat API požadavek a zobrazit JSON</button></div>
  </form>
</main>
</body>
</html>
