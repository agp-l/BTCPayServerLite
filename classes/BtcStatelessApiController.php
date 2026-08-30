<?php

declare(strict_types=1);

namespace BtcPayLite;

use JsonException;

/**
 * Validates the public stateless HTTP API boundary and delegates to the service.
 */
final class BtcStatelessApiController
{
    private const MAX_REQUEST_BYTES = 65_536;

    private BtcStatelessService $service;
    private string $paymentPageUrl;

    public function __construct(BtcStatelessService $service, string $paymentPageUrl)
    {
        $paymentPageUrl = trim($paymentPageUrl);
        if ($paymentPageUrl === '' || preg_match('/[\x00-\x1F\x7F]/', $paymentPageUrl)) {
            throw new BtcStatelessServiceException('Payment page URL is invalid.', 'configure_api');
        }

        $this->service = $service;
        $this->paymentPageUrl = $paymentPageUrl;
    }

    /**
     * @param array<string, mixed> $formData
     * @return array{status: string, data: array<string, mixed>}
     */
    public function handleRequest(
        string $requestMethod,
        string $rawBody,
        array $formData,
        string $authorizationHeader
    ): array {
        if (strtoupper(trim($requestMethod)) !== 'POST') {
            throw new BtcStatelessServiceException('Only POST requests are allowed.', 'handle_api_request', 405);
        }

        $input = $this->decodeInput($rawBody, $formData);
        $apiKey = $this->extractBearerToken($authorizationHeader);
        $result = $this->service->createInvoiceFromApi($input, $apiKey);
        $token = $result['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new BtcStatelessServiceException('Invoice service returned an invalid token.', 'handle_api_request');
        }

        $result['url'] = $this->paymentPageUrl . '?inv=' . rawurlencode($token);

        return ['status' => 'success', 'data' => $result];
    }

    /**
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    private function decodeInput(string $rawBody, array $formData): array
    {
        if (strlen($rawBody) > self::MAX_REQUEST_BYTES) {
            throw new BtcStatelessServiceException('Request body is too large.', 'decode_api_request', 413);
        }

        $trimmedBody = trim($rawBody);
        if ($trimmedBody === '') {
            return $formData;
        }

        try {
            $input = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BtcStatelessServiceException(
                'Request body must contain valid JSON.',
                'decode_api_request',
                400,
                $exception
            );
        }

        if ($trimmedBody[0] !== '{' || !is_array($input)) {
            throw new BtcStatelessServiceException(
                'Request JSON must be an object.',
                'decode_api_request',
                400
            );
        }

        return $input;
    }

    private function extractBearerToken(string $authorizationHeader): string
    {
        if (!preg_match('/\ABearer[ \t]+([^\s,]+)[ \t]*\z/iD', trim($authorizationHeader), $matches)) {
            throw new BtcStatelessServiceException(
                'Authorization header must use the Bearer scheme.',
                'authenticate',
                401
            );
        }

        return $matches[1];
    }
}
