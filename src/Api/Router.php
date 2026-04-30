<?php
/**
 * Minimal HTTP router for the AA /api/ front controller.
 *
 * Only exact method + path matches; no path parameters. Each new AA API
 * endpoint registers itself here from api/index.php.
 *
 * Path resolution order: $_SERVER['PATH_INFO'] (preferred, via mod_rewrite)
 * → REQUEST_URI stripped of the "/api" segment.
 *
 * @package Api
 */

namespace AA\Api;

class Router
{
    /** @var array<string,callable> "METHOD /path" => handler */
    private array $routes = [];

    public function register(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method) . ' ' . $path] = $handler;
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = $this->extractPath();

        $handler = $this->routes["$method $path"] ?? null;
        if ($handler === null) {
            JsonResponse::error(404, 'Not Found', ['path' => $path, 'method' => $method])->emit();
            return;
        }
        try {
            $response = $handler();
            if ($response instanceof JsonResponse) {
                $response->emit();
            }
        } catch (\Throwable $e) {
            JsonResponse::error(500, 'Internal error', ['detail' => $e->getMessage()])->emit();
        }
    }

    private function extractPath(): string
    {
        // Preferred: the rewrite rule hands the path via ?_path=v1/ping.
        if (isset($_GET['_path']) && $_GET['_path'] !== '') {
            return '/' . trim($_GET['_path'], '/');
        }
        // PATH_INFO fallback (when AcceptPathInfo is on).
        if (!empty($_SERVER['PATH_INFO'])) {
            return rtrim($_SERVER['PATH_INFO'], '/') ?: '/';
        }
        // Direct-URL fallback — e.g. when .htaccess wasn't loaded. Strip the
        // install prefix up to and including "/api" and keep the tail.
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        if (($pos = strrpos($uri, '/api')) !== false) {
            $tail = substr($uri, $pos + 4);
            return rtrim($tail, '/') ?: '/';
        }
        return '/';
    }
}
