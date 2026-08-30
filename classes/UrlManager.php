<?php
// BTCPayLite/classes/UrlManager.php
declare(strict_types=1);
namespace BtcPayLite;

/**
 * Zabezpečená třída pro parsování a správu URL adres.
 */
class UrlManager
{
    private array $urlSegments = [];
    private int $pathOffset = 0;
    private string $basePath = '';
    private string $safeHost = '';

    public function __construct()
    {
        $this->resolveSafeHost();
        $this->parseUrl();
        $this->calculatePathOffset();
    }

    /**
     * Získá bezpečný název hostitele a zamezí Host Header Injection.
     */
    private function resolveSafeHost(): void
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        // Povolí pouze alfanumerické znaky, tečky, pomlčky a případně port (dvojtečka)
        $this->safeHost = preg_replace('/[^a-zA-Z0-9.:-]/', '', $host);
    }

    private function parseUrl(): void
    {
        $urlPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
        // Ochrana před XSS v segmentech URL
        $urlPath = htmlspecialchars($urlPath, ENT_QUOTES, 'UTF-8');
        
        $segments = explode('/', $urlPath);
        $this->urlSegments = array_values(array_filter($segments));
    }

    private function calculatePathOffset(): void
    {
        $scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $this->basePath = trim(str_replace('\\', '/', $scriptName), '/');
        
        $this->pathOffset = $this->basePath !== '' ? count(array_filter(explode('/', $this->basePath))) : 0;
    }

    public function getSegment(int $index): ?string
    {
        $targetIndex = $index + $this->pathOffset;
        return $this->urlSegments[$targetIndex] ?? null;
    }

    public function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $protocol . '://' . $this->safeHost . ($this->basePath ? '/' . $this->basePath : '');
    }

    public function getFullUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $requestUri = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8');
        return $protocol . '://' . $this->safeHost . $requestUri;
    }

    /**
     * Vrací URL s ochranou proti Open Redirect zranitelnosti.
     */
    public function getBackPage(): string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $baseUrl = $this->getBaseUrl();

        // Bezpečnostní pojistka: Referer musí začínat naší základní doménou
        if ($referer !== '' && str_starts_with($referer, $baseUrl)) {
            return htmlspecialchars($referer, ENT_QUOTES, 'UTF-8');
        }

        return $baseUrl;
    }

    /**
     * Zjistí aktivní menu. (Pozn.: Koncepčně patří spíše do ViewHelperu).
     */
    public function getActiveMenu(): string
    {
        $segment1 = strtolower($this->getSegment(0) ?? '');
        $segment2 = strtolower($this->getSegment(1) ?? '');

        if ($segment1 === 'admin') {
            return $segment2 !== '' ? $segment2 : 'dashboard';
        }

        return $segment1 !== '' ? $segment1 : 'home';
    }

    public function __toString(): string
    {
        return "Base URL: " . $this->getBaseUrl() . " | Segments: " . implode(', ', $this->urlSegments);
    }
}