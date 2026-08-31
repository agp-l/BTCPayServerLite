<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

final class UrlManager
{
    /** @var list<string> */
    private array $segments;
    private string $baseUrl;
    private string $basePath;
    private string $requestPath;
    private string $queryString;
    private string $referer;

    /**
     * @param array<string,mixed>|null $server
     */
    public function __construct(?array $server = null, ?string $configuredBaseUrl = null)
    {
        $server ??= $_SERVER;
        [$this->baseUrl, $this->basePath] = $this->resolveBaseUrl($server, $configuredBaseUrl);
        [$this->requestPath, $this->segments, $this->queryString] = $this->parseRequest($server);
        $this->referer = is_string($server['HTTP_REFERER'] ?? null) ? $server['HTTP_REFERER'] : '';
    }

    public function getSegment(int $index): ?string
    {
        if ($index < 0) {
            return null;
        }

        return $this->segments[$index] ?? null;
    }

    /** @return list<string> */
    public function getSegments(): array
    {
        return $this->segments;
    }

    public function getPath(): string
    {
        return $this->requestPath;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Builds an absolute application URL from a root-relative application path.
     *
     * @param array<string,scalar|null> $query
     */
    public function url(string $path = '', array $query = []): string
    {
        $path = $this->normalizeApplicationPath($path);
        $url = $this->baseUrl . ($path === '/' ? '/' : $path);

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    public function getFullUrl(): string
    {
        $url = $this->url($this->requestPath);
        return $this->queryString === '' ? $url : $url . '?' . $this->queryString;
    }

    public function getBackPage(): string
    {
        $referer = $this->referer;
        if ($referer === '') {
            return $this->url('/');
        }

        $parts = parse_url($referer);
        $baseParts = parse_url($this->baseUrl);
        if (!is_array($parts) || !is_array($baseParts)) {
            return $this->url('/');
        }

        $sameOrigin = strtolower((string) ($parts['scheme'] ?? '')) === strtolower((string) $baseParts['scheme'])
            && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) $baseParts['host'])
            && $this->effectivePort($parts) === $this->effectivePort($baseParts);
        $path = (string) ($parts['path'] ?? '/');
        $insideBase = $this->basePath === ''
            || $path === $this->basePath
            || str_starts_with($path, $this->basePath . '/');

        return $sameOrigin && $insideBase ? $referer : $this->url('/');
    }

    public function getActiveMenu(): string
    {
        $first = strtolower($this->getSegment(0) ?? '');
        $second = strtolower($this->getSegment(1) ?? '');

        return $first === 'admin'
            ? ($second !== '' ? $second : 'dashboard')
            : ($first !== '' ? $first : 'home');
    }

    public function __toString(): string
    {
        return $this->getFullUrl();
    }

    /**
     * @param array<string,mixed> $server
     * @return array{string,string}
     */
    private function resolveBaseUrl(array $server, ?string $configuredBaseUrl): array
    {
        if ($configuredBaseUrl !== null && trim($configuredBaseUrl) !== '') {
            return $this->validateBaseUrl(trim($configuredBaseUrl));
        }

        $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost';
        if (!is_string($host) || !$this->isValidHostHeader($host)) {
            throw new InvalidArgumentException('Invalid request host.');
        }

        $https = $server['HTTPS'] ?? '';
        $scheme = is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        $scriptName = is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : '/index.php';
        $directory = str_replace('\\', '/', dirname($scriptName));
        $basePath = $directory === '/' || $directory === '.' ? '' : '/' . trim($directory, '/');

        return [$scheme . '://' . strtolower($host) . $basePath, $basePath];
    }

    /** @return array{string,string} */
    private function validateBaseUrl(string $baseUrl): array
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Configured app_url is invalid.');
        }

        $host = strtolower((string) $parts['host']);
        if (!$this->isValidHostname($host)) {
            throw new InvalidArgumentException('Configured app_url host is invalid.');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $basePath = $this->normalizeBasePath((string) ($parts['path'] ?? ''));

        return [strtolower((string) $parts['scheme']) . '://' . $host . $port . $basePath, $basePath];
    }

    /**
     * @param array<string,mixed> $server
     * @return array{string,list<string>,string}
     */
    private function parseRequest(array $server): array
    {
        $requestUri = $server['REQUEST_URI'] ?? '/';
        if (!is_string($requestUri) || preg_match('/[\x00-\x1F\x7F]/', $requestUri) === 1) {
            throw new InvalidArgumentException('Invalid request URI.');
        }

        $parts = parse_url($requestUri);
        if (!is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Invalid request URI.');
        }
        $rawPath = (string) ($parts['path'] ?? '/');
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $rawPath) === 1) {
            throw new InvalidArgumentException('Invalid URL encoding.');
        }

        $relativePath = $this->stripBasePath($rawPath);
        $segments = [];
        foreach (explode('/', trim($relativePath, '/')) as $rawSegment) {
            if ($rawSegment === '') {
                continue;
            }
            $segment = rawurldecode($rawSegment);
            if ($segment === '.' || $segment === '..'
                || str_contains($segment, '/')
                || str_contains($segment, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1
            ) {
                throw new InvalidArgumentException('Unsafe URL path segment.');
            }
            $segments[] = $segment;
        }

        $path = $segments === [] ? '/' : '/' . implode('/', array_map('rawurlencode', $segments));
        return [$path, $segments, (string) ($parts['query'] ?? '')];
    }

    private function stripBasePath(string $path): string
    {
        if ($this->basePath === '') {
            return $path;
        }
        if ($path === $this->basePath) {
            return '/';
        }
        if (!str_starts_with($path, $this->basePath . '/')) {
            throw new InvalidArgumentException('Request path is outside the application base path.');
        }

        return substr($path, strlen($this->basePath));
    }

    private function normalizeApplicationPath(string $path): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1) {
            throw new InvalidArgumentException('Invalid application path encoding.');
        }
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '/';
        }

        $encoded = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            $decoded = rawurldecode($segment);
            if ($segment === '' || $decoded === '.' || $decoded === '..'
                || str_contains($decoded, '/') || str_contains($decoded, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
            ) {
                throw new InvalidArgumentException('Invalid application path.');
            }
            $encoded[] = rawurlencode($decoded);
        }

        return '/' . implode('/', $encoded);
    }

    private function normalizeBasePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '';
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1) {
            throw new InvalidArgumentException('Configured app_url path is invalid.');
        }

        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '' || $decoded === '.' || $decoded === '..'
                || str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                throw new InvalidArgumentException('Configured app_url path is invalid.');
            }
            $segments[] = rawurlencode($decoded);
        }
        return '/' . implode('/', $segments);
    }

    private function isValidHostHeader(string $host): bool
    {
        if ($host === '' || preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
            return false;
        }
        $parts = parse_url('http://' . $host);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $rebuilt = strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        return $rebuilt === strtolower($host) && $this->isValidHostname((string) $parts['host']);
    }

    private function isValidHostname(string $host): bool
    {
        $unbracketed = trim($host, '[]');
        return filter_var($unbracketed, FILTER_VALIDATE_IP) !== false
            || filter_var($unbracketed, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** @param array<string,mixed> $parts */
    private function effectivePort(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }
}
