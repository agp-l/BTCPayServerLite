<?php

declare(strict_types=1);

namespace BtcPayLite;

use JsonException;

/**
 * Routes and validates Greenfield HTTP requests without reading global state.
 */
class GreenfieldApiController
{
    private const MAX_REQUEST_BYTES = 65_536;

    private GreenfieldApiService $service;

    public function __construct(GreenfieldApiService $service)
    {
        $this->service = $service;
    }

    /**
     * Adapts Apache/PHP server variables to the testable request boundary.
     *
     * @param array<string, mixed> $server
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function handleServerRequest(array $server, string $rawBody): array
    {
        $method = $server['REQUEST_METHOD'] ?? null;
        $requestUri = $server['REQUEST_URI'] ?? null;
        $scriptName = $server['SCRIPT_NAME'] ?? '';
        if (!is_string($method) || !is_string($requestUri) || !is_string($scriptName)) {
            throw new GreenfieldApiException('HTTP request data is invalid.', 'adapt_request', 400);
        }

        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($requestPath)) {
            throw new GreenfieldApiException('HTTP request path is invalid.', 'adapt_request', 400);
        }

        $scriptPath = str_replace('\\', '/', $scriptName);
        if (
            $scriptPath !== ''
            && ($requestPath === $scriptPath || str_starts_with($requestPath, $scriptPath . '/'))
        ) {
            $requestPath = substr($requestPath, strlen($scriptPath));
        } else {
            // Supports an Apache rewrite that maps /api/v1/* to api.php.
            $apiPathOffset = strpos($requestPath, '/api/v1/');
            if ($apiPathOffset !== false) {
                $requestPath = substr($requestPath, $apiPathOffset);
            }
        }

        $authorization = $server['HTTP_AUTHORIZATION']
            ?? $server['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if (!is_string($authorization)) {
            throw new GreenfieldApiException('Authorization header is invalid.', 'authenticate', 401);
        }

        return $this->handleRequest($method, $requestPath, $rawBody, $authorization);
    }

    /**
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function handleRequest(
        string $method,
        string $path,
        string $rawBody,
        string $authorizationHeader
    ): array {
        $method = strtoupper(trim($method));
        $path = '/' . ltrim($path, '/');
        $apiKey = $this->extractBearerToken($authorizationHeader);

        if (preg_match('/\A\/api\/v1\/stores\/([A-Za-z0-9_-]+)\z/D', $path, $matches)) {
            $this->requireMethod($method, 'GET');

            return ['status_code' => 200, 'body' => $this->service->getStore($matches[1], $apiKey)];
        }

        if (preg_match(
            '/\A\/api\/v1\/stores\/([A-Za-z0-9_-]+)\/invoices\/([A-Za-z0-9_-]+)\z/D',
            $path,
            $matches
        )) {
            $this->requireMethod($method, 'GET');

            return [
                'status_code' => 200,
                'body' => $this->service->getInvoice($matches[1], $matches[2], $apiKey),
            ];
        }

        if (preg_match('/\A\/api\/v1\/stores\/([A-Za-z0-9_-]+)\/invoices\z/D', $path, $matches)) {
            $this->requireMethod($method, 'POST');

            return [
                'status_code' => 200,
                'body' => $this->service->createInvoice(
                    $matches[1],
                    $this->decodeJsonObject($rawBody),
                    $apiKey
                ),
            ];
        }

        if (preg_match('/\A\/api\/v1\/stores\/([A-Za-z0-9_-]+)\/webhooks\z/D', $path, $matches)) {
            $this->requireMethod($method, 'POST');

            return [
                'status_code' => 200,
                'body' => $this->service->createWebhook(
                    $matches[1],
                    $this->decodeJsonObject($rawBody),
                    $apiKey
                ),
            ];
        }

        throw new GreenfieldApiException('Endpoint was not found.', 'route_request', 404);
    }

    private function requireMethod(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new GreenfieldApiException(
                "Only {$expected} requests are allowed for this endpoint.",
                'route_request',
                405
            );
        }
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $rawBody): array
    {
        if (strlen($rawBody) > self::MAX_REQUEST_BYTES) {
            throw new GreenfieldApiException('Request body is too large.', 'decode_request', 413);
        }

        $trimmedBody = trim($rawBody);
        if ($trimmedBody === '') {
            throw new GreenfieldApiException('Request body must contain JSON.', 'decode_request', 400);
        }

        try {
            $input = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GreenfieldApiException(
                'Request body must contain valid JSON.',
                'decode_request',
                400,
                $exception
            );
        }

        if ($trimmedBody[0] !== '{' || !is_array($input)) {
            throw new GreenfieldApiException('Request JSON must be an object.', 'decode_request', 400);
        }

        return $input;
    }

    private function extractBearerToken(string $authorizationHeader): string
    {
        if (!preg_match('/\ABearer[ \t]+([^\s,]+)[ \t]*\z/iD', trim($authorizationHeader), $matches)) {
            throw new GreenfieldApiException(
                'Authorization header must use the Bearer scheme.',
                'authenticate',
                401
            );
        }

        return $matches[1];
    }
}
