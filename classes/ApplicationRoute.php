<?php

declare(strict_types=1);

namespace BtcPayLite;

use InvalidArgumentException;

final class ApplicationRoute
{
    private ?string $handler;
    private ?string $redirectPath;
    private ?string $requiredRole;
    private string $menu;

    private function __construct(
        ?string $handler,
        ?string $redirectPath,
        ?string $requiredRole,
        string $menu
    ) {
        $this->handler = $handler;
        $this->redirectPath = $redirectPath;
        $this->requiredRole = $requiredRole;
        $this->menu = $menu;
    }

    public static function handler(string $handler, string $menu, ?string $requiredRole = null): self
    {
        if ($handler === '' || str_starts_with($handler, '/') || str_contains($handler, '..')) {
            throw new InvalidArgumentException('Route handler must be a safe relative path.');
        }
        if ($requiredRole !== null && !in_array($requiredRole, ['admin', 'client'], true)) {
            throw new InvalidArgumentException('Route role is invalid.');
        }

        return new self($handler, null, $requiredRole, $menu);
    }

    public static function redirect(string $path): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Redirect path must be application-relative.');
        }

        return new self(null, $path, null, '');
    }

    public function isRedirect(): bool
    {
        return $this->redirectPath !== null;
    }

    public function getHandler(): ?string
    {
        return $this->handler;
    }

    public function getRedirectPath(): ?string
    {
        return $this->redirectPath;
    }

    public function getRequiredRole(): ?string
    {
        return $this->requiredRole;
    }

    public function getMenu(): string
    {
        return $this->menu;
    }
}
