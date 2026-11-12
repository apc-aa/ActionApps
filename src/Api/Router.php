<?php

/**
 * Minimal HTTP router for the AA /api/ front controller.
 *
 * Exact method + path matches, plus whole path segments written as {name}
 * (e.g. "/v1/slices/{slice}/items"), which match one segment of [0-9a-z_-] and
 * reach the handler through $_GET - where every other input of a request is
 * read from. An exact path always wins over a pattern. Each new AA API
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

    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable}> */
    private array $patterns = [];

    public function register(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        if (!str_contains($path, '{') && !str_contains($path, '}')) {
            $this->routes["$method $path"] = $handler;
            return;
        }

        $names = [];
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                // a parameter is a whole segment with a plain name - a typo here would otherwise
                // be a route that never matches, found only when a caller gets a 404
                if (!preg_match('~^\{([a-z][a-z0-9_]*)\}$~D', $segment, $m) || in_array($m[1], $names, true)) {
                    throw new \InvalidArgumentException("Bad path parameter '$segment' in route '$path'");
                }
                $names[] = $m[1];
                $parts[] = '([0-9a-z_-]+)';    // an id, a slug - nothing that could be a path of its own
                continue;
            }
            $parts[] = preg_quote($segment, '~');
        }
        $this->patterns[] = [
            'method'  => $method,
            'regex'   => '~^' . implode('/', $parts) . '$~D',
            'names'   => $names,
            'handler' => $handler,
        ];
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = $this->extractPath();

        $handler = $this->routes["$method $path"] ?? $this->match($method, $path);
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

    /**
     * The first registered pattern this request fits, with its parameters put where the handler
     * reads its input.
     *
     * The values go into $_GET on purpose: a controller reads them through AA's own reading of
     * the input (InputVars) exactly as it reads everything else, and nothing has to be handed
     * along a second path. The path is the authority, so it overwrites a query string of the
     * same name rather than the other way round.
     */
    private function match(string $method, string $path): ?callable
    {
        foreach ($this->patterns as $route) {
            if ($route['method'] !== $method || !preg_match($route['regex'], $path, $found)) {
                continue;
            }
            foreach ($route['names'] as $i => $name) {
                $_GET[$name] = $found[$i + 1];
            }

            return $route['handler'];
        }

        return null;
    }

    private function extractPath(): string
    {
        // Preferred: the rewrite rule hands the path via ?_path=v1/ping.
        if (isset($_GET['_path']) && is_string($_GET['_path']) && $_GET['_path'] !== '') {
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
