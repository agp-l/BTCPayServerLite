<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * Low-level HTTP transport for the Electrum daemon JSON-RPC interface.
 *
 * This class is deliberately unaware of Bitcoin business rules. Wallet,
 * invoice and payment behaviour belongs in higher application layers.
 * Requests are never retried automatically because some Electrum operations
 * are not idempotent.
 */
class ElectrumRPC
{
    private const JSON_RPC_VERSION = '2.0';
    private const DEFAULT_CONNECT_TIMEOUT = 5;
    private const USER_AGENT = 'BTCPayServerLite/ElectrumRPC';

    private string $rpcUrl;
    private ?string $rpcUser;
    private ?string $rpcPass;
    private int $timeout;
    private int $connectTimeout;
    private string $scheme;
    private ?string $activeWallet = null;
    private int $requestSequence = 0;

    public function __construct(
        string $host,
        int $port,
        ?string $user = null,
        ?string $pass = null,
        int $timeout = 30,
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        string $scheme = 'http'
    ) {
        $this->assertValidConfiguration($host, $port, $user, $pass, $timeout, $connectTimeout, $scheme);

        $this->scheme = strtolower(trim($scheme));
        $this->rpcUrl = $this->buildRpcUrl(trim($host), $port, $this->scheme);
        $this->rpcUser = $user;
        $this->rpcPass = $pass;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Stores an active wallet for callForActiveWallet().
     *
     * The wallet is no longer appended to the URL. Electrum's supported
     * JSON-RPC interface expects wallet_path as a named request parameter.
     */
    public function setWallet(string $walletPath): void
    {
        $this->activeWallet = $this->validateWalletPath($walletPath);
    }

    public function clearWallet(): void
    {
        $this->activeWallet = null;
    }

    public function getActiveWallet(): ?string
    {
        return $this->activeWallet;
    }

    public function getEndpoint(): string
    {
        return $this->rpcUrl;
    }

    /**
     * Executes an unscoped Electrum JSON-RPC command.
     *
     * Params may be positional (a list) or named (an associative array).
     * Empty params are encoded as [] as documented by Electrum.
     *
     * @return mixed JSON-decoded RPC result
     *
     * @throws ElectrumRPCException
     */
    public function call(string $method, array $params = []): mixed
    {
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
                "Nelze zakódovat JSON-RPC požadavek pro metodu '{$method}'.",
                ElectrumRPCException::TYPE_PROTOCOL,
                $method,
                $requestId,
                previous: $e
            );
        }

        [$responseBody, $httpStatus] = $this->send($payload, $method, $requestId);

        return $this->decodeResponse($responseBody, $httpStatus, $method, $requestId);
    }

    /**
     * Executes a wallet-scoped command using Electrum's wallet_path parameter.
     *
     * Only named parameters are accepted because wallet_path must be added to
     * the JSON object without changing the command's positional arguments.
     *
     * @throws ElectrumRPCException
     */
    public function callForWallet(string $method, string $walletPath, array $params = []): mixed
    {
        if (!$this->hasOnlyNamedKeys($params)) {
            throw new InvalidArgumentException(
                'Wallet-scoped RPC calls require named parameters.'
            );
        }

        $walletPath = $this->validateWalletPath($walletPath);

        if (array_key_exists('wallet_path', $params) && $params['wallet_path'] !== $walletPath) {
            throw new InvalidArgumentException(
                'The wallet_path parameter conflicts with the requested wallet.'
            );
        }

        $params['wallet_path'] = $walletPath;

        return $this->call($method, $params);
    }

    /**
     * Executes a command for the wallet previously selected by setWallet().
     *
     * @throws ElectrumRPCException
     */
    public function callForActiveWallet(string $method, array $params = []): mixed
    {
        if ($this->activeWallet === null) {
            throw new LogicException('No active Electrum wallet has been selected.');
        }

        return $this->callForWallet($method, $this->activeWallet, $params);
    }

    /**
     * Lightweight daemon health check.
     *
     * Exceptions are intentionally not swallowed, so callers can distinguish
     * transport, authentication and protocol failures.
     *
     * @throws ElectrumRPCException
     */
    public function ping(): bool
    {
        return $this->call('ping') === true;
    }

    /**
     * @return array{0: string, 1: int}
     *
     * @throws ElectrumRPCException
     */
    private function send(string $payload, string $method, int $requestId): array
    {
        $curl = curl_init($this->rpcUrl);
        if ($curl === false) {
            throw new ElectrumRPCException(
                'Nelze inicializovat cURL pro Electrum RPC.',
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
            CURLOPT_TIMEOUT => $this->timeout,
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
                'Nelze nastavit cURL volby pro Electrum RPC.',
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
                    "Spojení s Electrum RPC selhalo pro metodu '{$method}': {$curlMessage}",
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

    /**
     * @throws ElectrumRPCException
     */
    private function decodeResponse(
        string $responseBody,
        int $httpStatus,
        string $method,
        int $requestId
    ): mixed {
        if ($httpStatus === 401 || $httpStatus === 403) {
            throw new ElectrumRPCException(
                'Electrum RPC odmítlo přihlašovací údaje.',
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
                "Electrum RPC vrátilo neplatnou JSON odpověď pro metodu '{$method}'.",
                $type,
                $method,
                $requestId,
                $httpStatus > 0 ? $httpStatus : null,
                previous: $e
            );
        }

        if (!is_array($decoded)) {
            throw new ElectrumRPCException(
                "Electrum RPC odpověď pro metodu '{$method}' není JSON objekt.",
                ElectrumRPCException::TYPE_PROTOCOL,
                $method,
                $requestId,
                $httpStatus > 0 ? $httpStatus : null
            );
        }

        if (!$this->isSuccessfulHttpStatus($httpStatus)) {
            throw new ElectrumRPCException(
                "Electrum RPC vrátilo HTTP stav {$httpStatus} pro metodu '{$method}'.",
                ElectrumRPCException::TYPE_HTTP,
                $method,
                $requestId,
                $httpStatus
            );
        }

        if (($decoded['jsonrpc'] ?? null) !== self::JSON_RPC_VERSION) {
            throw new ElectrumRPCException(
                "Electrum RPC odpověď pro metodu '{$method}' nemá JSON-RPC verzi 2.0.",
                ElectrumRPCException::TYPE_PROTOCOL,
                $method,
                $requestId,
                $httpStatus
            );
        }

        if (!array_key_exists('id', $decoded) || $decoded['id'] !== $requestId) {
            throw new ElectrumRPCException(
                "Electrum RPC vrátilo neočekávané ID odpovědi pro metodu '{$method}'.",
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
                "Electrum RPC odpověď pro metodu '{$method}' musí obsahovat právě result nebo error.",
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
                : 'Neznámá chyba Electrum RPC.';
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

    private function hasOnlyNamedKeys(array $params): bool
    {
        foreach (array_keys($params) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
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

        if ($user !== null && ($user === '' || str_contains($user, "\0"))) {
            throw new InvalidArgumentException('Invalid Electrum RPC username.');
        }

        if ($pass !== null && ($pass === '' || str_contains($pass, "\0"))) {
            throw new InvalidArgumentException('Invalid Electrum RPC password.');
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
