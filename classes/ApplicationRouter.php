<?php

declare(strict_types=1);

namespace BtcPayLite;

final class ApplicationRouter
{
    /**
     * @var array<string,array{methods:list<string>,handler?:string,menu?:string,role?:string,redirect?:string}>
     */
    private array $routes;

    public function __construct()
    {
        $this->routes = [
            '/' => $this->page(['GET', 'HEAD'], 'pages/prezentace.php', 'home'),
            '/home' => $this->redirect('/'),
            '/prezentace' => $this->redirect('/'),
            '/login' => $this->page(['GET', 'HEAD', 'POST'], 'client/login.php', 'login'),
            '/registrace' => $this->page(['GET', 'HEAD', 'POST'], 'client/registrace.php', 'registrace'),
            '/client' => $this->page(['GET', 'HEAD', 'POST'], 'client/index.php', 'client', 'client'),
            '/dashboard' => $this->redirect('/client'),
            '/api' => $this->page(['POST'], 'api_stateless.php', 'api'),
            '/pay' => $this->page(['GET', 'HEAD', 'POST'], 'checkout/pay.php', 'pay'),
            '/admin' => $this->redirect('/admin/dashboard'),
            '/admin/dashboard' => $this->page(
                ['GET', 'HEAD'], 'admin/dashboard.php', 'dashboard', 'admin'
            ),
            '/admin/wallet' => $this->page(
                ['GET', 'HEAD', 'POST'], 'admin/wallet.php', 'wallet', 'admin'
            ),
            '/admin/stores' => $this->page(
                ['GET', 'HEAD', 'POST'], 'admin/stores.php', 'stores', 'admin'
            ),
            '/admin/invoices' => $this->page(
                ['GET', 'HEAD', 'POST'], 'admin/invoices.php', 'invoices', 'admin'
            ),
            '/admin/webhooks' => $this->page(
                ['GET', 'HEAD', 'POST'], 'admin/webhooks.php', 'webhooks', 'admin'
            ),
            '/admin/url_invoices' => $this->page(
                ['GET', 'HEAD', 'POST'], 'admin/url_invoices.php', 'url_invoices', 'admin'
            ),
            '/admin/test_shop' => $this->page(
                ['GET', 'HEAD', 'POST'], 'admin/test_shop.php', 'test_shop', 'admin'
            ),
            '/admin/test_api_webhook' => $this->page(
                ['GET', 'HEAD', 'POST'], 'admin/test_api_webhook.php', 'test_api_webhook', 'admin'
            ),
        ];
    }

    public function match(string $path, string $method): ApplicationRoute
    {
        $definition = $this->routes[$path] ?? null;
        if ($definition === null) {
            throw new RouterException('Stránka nebyla nalezena.', 404);
        }

        $method = strtoupper($method);
        if (!in_array($method, $definition['methods'], true)) {
            throw new RouterException('Metoda není pro tuto cestu povolena.', 405, $definition['methods']);
        }

        if (isset($definition['redirect'])) {
            return ApplicationRoute::redirect($definition['redirect']);
        }

        return ApplicationRoute::handler(
            $definition['handler'],
            $definition['menu'],
            $definition['role'] ?? null
        );
    }

    /**
     * @param list<string> $methods
     * @return array{methods:list<string>,handler:string,menu:string,role?:string}
     */
    private function page(array $methods, string $handler, string $menu, ?string $role = null): array
    {
        $route = ['methods' => $methods, 'handler' => $handler, 'menu' => $menu];
        if ($role !== null) {
            $route['role'] = $role;
        }
        return $route;
    }

    /** @return array{methods:list<string>,redirect:string} */
    private function redirect(string $path): array
    {
        return ['methods' => ['GET', 'HEAD'], 'redirect' => $path];
    }
}
