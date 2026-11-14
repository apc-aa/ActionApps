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
    /** @var array<string,true> paths answered without a session */
    private array $sessionless = [];

    /** @param bool $session false: the path opens no session and sends no cookie, whatever the method */
    public function register(string $method, string $path, callable $handler, bool $session = true): void
    {
        $this->routes[strtoupper($method) . ' ' . $path] = $handler;
        if (!$session) {
            $this->sessionless[$path] = true;
        }
    }

    /** Should the request open the session? Asked before pageOpen(), so all routes must be registered first. */
    public function needsSession(): bool
    {
        // by the path alone: a HEAD of a mail checker would get a cookie too
        return !isset($this->sessionless[$this->extractPath()]);
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
            // the API is public - paths and SQL from the message go to the server log only
            error_log('AA API ' . $path . ': ' . preg_replace('/[\x00-\x1F\x7F]+/', ' ', get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()));
            JsonResponse::error(500, 'Internal error')->emit();
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
