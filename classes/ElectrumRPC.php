<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Throwable;

/**
 * Low-level HTTP transport for the Electrum daemon JSON-RPC interface.
 *
 * Stateless and thread-safe. Does not hold mutable active wallet state.
 * Commands explicitly route to daemon, network (walletless), or a specific wallet.
 */
class ElectrumRPC
{
    private const JSON_RPC_VERSION = '2.0';
    private const DEFAULT_CONNECT_TIMEOUT = 3;
    private const DEFAULT_READ_TIMEOUT = 10;
    private const DEFAULT_WRITE_TIMEOUT = 30;
    private const USER_AGENT = 'BTCPayServerLite/ElectrumRPC';

    private string $rpcUrl;
    private ?string $rpcUser;
    private ?string $rpcPass;
    private int $timeout;
    private int $connectTimeout;
    private int $writeTimeout;
    private string $scheme;
    private int $requestSequence = 0;
    private ElectrumRpcDialect $dialect;
    private ?CircuitBreaker $circuitBreaker;

    public function __construct(
        string $host,
        int $port,
        ?string $user = null,
        ?string $pass = null,
        int $timeout = self::DEFAULT_READ_TIMEOUT,
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        string $scheme = 'http',
        ?ElectrumRpcDialect $dialect = null,
        ?CircuitBreaker $circuitBreaker = null,
        int $writeTimeout = self::DEFAULT_WRITE_TIMEOUT
    ) {
        $this->assertValidConfiguration($host, $port, $user, $pass, $timeout, $connectTimeout, $scheme);

        $this->scheme = strtolower(trim($scheme));
        $this->rpcUrl = $this->buildRpcUrl(trim($host), $port, $this->scheme);
        $this->rpcUser = $user;
        $this->rpcPass = $pass;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->writeTimeout = $writeTimeout;
        $this->dialect = $dialect ?? new StandardElectrumRpcDialect();
        $this->circuitBreaker = $circuitBreaker ?? new CircuitBreaker();
    }

    public function getEndpoint(): string
    {
        return $this->rpcUrl;
    }

    public function getDialect(): ElectrumRpcDialect
    {
        return $this->dialect;
    }

    public function setDialect(ElectrumRpcDialect $dialect): void
    {
        $this->dialect = $dialect;
    }

    public function getCircuitBreaker(): ?CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * Executes a daemon-level command (e.g. list_wallets, load_wallet, close_wallet, version, getinfo).
     */
    public function callDaemon(string $method, array $params = []): mixed
    {
        return $this->executeRpcCall($method, $params, $this->timeout);
    }

    /**
     * Executes a network / walletless command (e.g. getaddressbalance, getaddresshistory, broadcast).
     */
    public function callNetwork(string $method, array $params = []): mixed
    {
        return $this->executeRpcCall($method, $params, $this->timeout);
    }

    /**
     * Executes a wallet-scoped command explicitly targeting the given walletPath.
     */
    public function callWallet(string $walletPath, string $method, array $params = []): mixed
    {
        $walletPath = $this->validateWalletPath($walletPath);
        $scopedParams = $this->dialect->walletParams($walletPath, $params);

        $isWriteMethod = in_array(strtolower($method), [
            'createnewaddress', 'payto', 'broadcast', 'signmessage', 'encrypt', 'decrypt', 'password'
        ], true);

        $timeout = $isWriteMethod ? $this->writeTimeout : $this->timeout;

        return $this->executeRpcCall($method, $scopedParams, $timeout);
    }

    /**
     * Generic unscoped call for backwards compatibility with legacy callers.
     * Note: Prefer callDaemon, callNetwork, or callWallet.
     */
    public function call(string $method, array $params = []): mixed
    {
        return $this->executeRpcCall($method, $params, $this->timeout);
    }

    /**
     * Compatibility shim for callers passing wallet explicitly.
     */
    public function callForWallet(string $method, string $walletPath, array $params = []): mixed
    {
        return $this->callWallet($walletPath, $method, $params);
    }

    /**
     * Daemon health check.
     */
    public function ping(): bool
    {
        try {
            return $this->callDaemon('ping') === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function executeRpcCall(string $method, array $params, int $timeout): mixed
    {
        if ($this->circuitBreaker !== null && !$this->circuitBreaker->isAvailable()) {
            throw new ElectrumRPCException(
                "Electrum daemon is currently marked unhealthy by circuit breaker (last error: " . ($this->circuitBreaker->getLastErrorMessage() ?? 'unknown') . ").",
                ElectrumRPCException::TYPE_TRANSPORT,
                $method,
                0,
                503
            );
        }

        $method = $this->validateMethod($method);
        $requestId = $this->nextRequestId();

        try {
            $payload = json_encode(
                [
                    'jsonrpc' => self::JSON_RPC_VERSION,
                    'id' => $requestId,
                    'method' => $method,
                    'params' => $params,
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new ElectrumRPCException(
                "Unable to encode JSON-RPC request for method '{$method}'.",
                ElectrumRPCException::TYPE_PROTOCOL,
                $method,
                $requestId,
                previous: $e
            );
        }

        try {
            [$responseBody, $httpStatus] = $this->send($payload, $method, $requestId, $timeout);
            $result = $this->decodeResponse($responseBody, $httpStatus, $method, $requestId);
            if ($this->circuitBreaker !== null) {
                $this->circuitBreaker->recordSuccess();
            }
            return $result;
        } catch (ElectrumRPCException $exception) {
            if ($this->circuitBreaker !== null && $exception->getType() === ElectrumRPCException::TYPE_TRANSPORT) {
                $this->circuitBreaker->recordFailure($exception->getMessage());
            }
            throw $exception;
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function send(string $payload, string $method, int $requestId, int $timeout): array
    {
        $curl = curl_init($this->rpcUrl);
        if ($curl === false) {
            throw new ElectrumRPCException(
                'Failed to initialize cURL for Electrum RPC.',
                ElectrumRPCException::TYPE_TRANSPORT,
                $method,
                $requestId
            );
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ];

        if ($this->rpcUser !== null && $this->rpcPass !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $this->rpcUser . ':' . $this->rpcPass;
        }

        if ($this->scheme === 'https') {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        }

        if (!curl_setopt_array($curl, $options)) {
            curl_close($curl);
            throw new ElectrumRPCException(
                'Failed to set cURL options for Electrum RPC.',
                ElectrumRPCException::TYPE_TRANSPORT,
                $method,
                $requestId
            );
        }

        try {
            $response = curl_exec($curl);
            $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($response === false) {
                $curlCode = curl_errno($curl);
                $curlMessage = curl_error($curl);

                throw new ElectrumRPCException(
                    "Connection to Electrum RPC failed for method '{$method}': {$curlMessage}",
                    ElectrumRPCException::TYPE_TRANSPORT,
                    $method,
                    $requestId,
                    $httpStatus > 0 ? $httpStatus : null,
                    curlCode: $curlCode
                );
            }

            return [(string) $response, $httpStatus];
        } finally {
            curl_close($curl);
        }
    }

    private function decodeResponse(
        string $responseBody,
        int $httpStatus,
        string $method,
        int $requestId
    ): mixed {
        if ($httpStatus === 401 || $httpStatus === 403) {
            throw new ElectrumRPCException(
                'Electrum RPC rejected credentials (unauthorized).',
                ElectrumRPCException::TYPE_AUTHENTICATION,
                $method,
                $requestId,
                $httpStatus
            );
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $type = $this->isSuccessfulHttpStatus($httpStatus)
                ? ElectrumRPCException::TYPE_PROTOCOL
                : ElectrumRPCException::TYPE_HTTP;

            throw new ElectrumRPCException(
                "Electrum RPC returned invalid JSON response for method '{$method}'.",
                $type,
                $method,
                $requestId,
                $httpStatus > 0 ? $httpStatus : null,
                previous: $e
            );
        }

        if (!is_array($decoded)) {
            throw new ElectrumRPCException(
                "Electrum RPC response for method '{$method}' is not a JSON object.",
                ElectrumRPCException::TYPE_PROTOCOL,
                $method,
                $requestId,
                $httpStatus > 0 ? $httpStatus : null
            );
        }

        if (!$this->isSuccessfulHttpStatus($httpStatus)) {
            throw new ElectrumRPCException(
                "Electrum RPC returned HTTP status {$httpStatus} for method '{$method}'.",
                ElectrumRPCException::TYPE_HTTP,
                $method,
                $requestId,
                $httpStatus
            );
        }

        if (($decoded['jsonrpc'] ?? null) !== self::JSON_RPC_VERSION) {
            throw new ElectrumRPCException(
                "Electrum RPC response for method '{$method}' does not specify JSON-RPC 2.0 version.",
                ElectrumRPCException::TYPE_PROTOCOL,
                $method,
                $requestId,
                $httpStatus
            );
        }

        if (!array_key_exists('id', $decoded) || $decoded['id'] !== $requestId) {
            throw new ElectrumRPCException(
                "Electrum RPC returned unexpected response ID for method '{$method}'.",
                ElectrumRPCException::TYPE_PROTOCOL,
                $method,
                $requestId,
                $httpStatus
            );
        }

        $hasResult = array_key_exists('result', $decoded);
        $hasError = array_key_exists('error', $decoded) && $decoded['error'] !== null;

        if ($hasResult === $hasError) {
            throw new ElectrumRPCException(
                "Electrum RPC response for method '{$method}' must contain either result or error.",
                ElectrumRPCException::TYPE_PROTOCOL,
                $method,
                $requestId,
                $httpStatus
            );
        }

        if ($hasError) {
            $error = $decoded['error'];
            $rpcCode = is_array($error) && isset($error['code']) && is_int($error['code'])
                ? $error['code']
                : null;
            $rpcMessage = is_array($error) && isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'Unknown Electrum RPC error.';
            $rpcData = is_array($error) ? ($error['data'] ?? null) : null;

            throw new ElectrumRPCException(
                "Electrum RPC metoda '{$method}' selhala: {$rpcMessage}",
                ElectrumRPCException::TYPE_REMOTE,
                $method,
                $requestId,
                $httpStatus,
                $rpcCode,
                $rpcData
            );
        }

        return $decoded['result'];
    }

    private function nextRequestId(): int
    {
        if ($this->requestSequence === PHP_INT_MAX) {
            $this->requestSequence = 0;
        }

        return ++$this->requestSequence;
    }

    private function validateMethod(string $method): string
    {
        $method = trim($method);
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,127}\z/D', $method)) {
            throw new InvalidArgumentException('Invalid Electrum RPC method name.');
        }

        return $method;
    }

    private function validateWalletPath(string $walletPath): string
    {
        $walletPath = trim($walletPath);
        if ($walletPath === '' || str_contains($walletPath, "\0")) {
            throw new InvalidArgumentException('Invalid Electrum wallet path.');
        }

        return $walletPath;
    }

    private function isSuccessfulHttpStatus(int $httpStatus): bool
    {
        return $httpStatus >= 200 && $httpStatus < 300;
    }

    private function assertValidConfiguration(
        string $host,
        int $port,
        ?string $user,
        ?string $pass,
        int $timeout,
        int $connectTimeout,
        string $scheme
    ): void {
        $host = trim($host);
        $scheme = strtolower(trim($scheme));

        if ($host === '' || preg_match('/[\x00-\x20\/?#@]/', $host)) {
            throw new InvalidArgumentException('Invalid Electrum RPC host.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Electrum RPC port must be between 1 and 65535.');
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Electrum RPC scheme must be http or https.');
        }

        if (($user === null) !== ($pass === null)) {
            throw new InvalidArgumentException('Electrum RPC username and password must be configured together.');
        }

        if ($timeout < 1) {
            throw new InvalidArgumentException('Electrum RPC timeout must be at least one second.');
        }

        if ($connectTimeout < 1 || $connectTimeout > $timeout) {
            throw new InvalidArgumentException(
                'Electrum RPC connect timeout must be positive and no greater than the total timeout.'
            );
        }
    }

    private function buildRpcUrl(string $host, int $port, string $scheme): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        } elseif (
            filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            && !preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\z/D', $host)
        ) {
            throw new InvalidArgumentException('Invalid Electrum RPC hostname or IP address.');
        }

        return sprintf('%s://%s:%d/', $scheme, $host, $port);
    }
}
