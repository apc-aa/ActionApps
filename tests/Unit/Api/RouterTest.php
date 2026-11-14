<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use AA\Api\Router;
use PHPUnit\Framework\TestCase;

/**
 * An endpoint that throws answers 500 to whoever called it - the API is public, so the message
 * (paths, SQL, ids) goes to the server log, not into the response.
 */
final class RouterTest extends TestCase
{
    private string $log = '';
    private string|false $log_before = false;

    protected function setUp(): void
    {
        $this->log        = (string) tempnam(sys_get_temp_dir(), 'aa-router');
        $this->log_before = ini_set('error_log', $this->log);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['_path'] = 'v1/boom';
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->log_before);
        @unlink($this->log);
        unset($_GET['_path'], $_SERVER['REQUEST_METHOD']);
    }

    public function test_dispatch_exception_answers_without_its_message(): void
    {
        $router = new Router();
        $router->register('GET', '/v1/boom', static fn() => throw new \RuntimeException("SELECT * FROM users WHERE x='/var/www/secret'"));

        ob_start();
        $router->dispatch();
        $body = (string) ob_get_clean();

        self::assertSame(['error' => 'Internal error'], json_decode($body, true));
        self::assertStringContainsString('/var/www/secret', (string) file_get_contents($this->log), 'the admin finds it in the log');
    }

    /** ?_path[]=x is no path - not a TypeError */
    public function test_needsSession_pathAsArray_isLikeNoPath(): void
    {
        $router = new Router();
        $router->register('GET', '/v1/mail/hit', static fn() => null, false);
        $_GET['_path'] = ['v1/mail/hit'];

        self::assertTrue($router->needsSession());

        ob_start();
        $router->dispatch();
        $body = (string) ob_get_clean();
        self::assertSame('/', json_decode($body, true)['path'] ?? null);
    }

    public function test_logError_keepsTheLogEntryOnOneLine(): void
    {
        Router::logError("AA API /v1/x\ny", new \RuntimeException("a\r\nb"));

        $line = (string) file_get_contents($this->log);
        self::assertStringContainsString('AA API /v1/x y: RuntimeException: a b at ', $line);
        self::assertSame(1, substr_count(rtrim($line), "\n") + 1);
    }

    // an image in a mail must not hand the reader a cookie, nor start a session at every open
    public function test_needsSession_routeWithoutSession_isFalse(): void
    {
        $_GET['_path'] = 'v1/mail/hit';

        self::assertFalse(self::routes()->needsSession());
    }

    // a mail checker sends HEAD first: it gets a 404 and no cookie either
    public function test_needsSession_anotherMethodOnThatPath_isFalse(): void
    {
        $_GET['_path'] = 'v1/mail/hit';
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $router = self::routes();

        self::assertFalse($router->needsSession());
        ob_start();
        $router->dispatch();
        $body = (string) ob_get_clean();
        self::assertSame('Not Found', json_decode($body, true)['error'] ?? null);
    }

    public function test_needsSession_otherRouteOrUnknownPath_isTrue(): void
    {
        $router = self::routes();
        foreach (['v1/ping', 'v1/unknown', 'v1/mail/hit/x', 'v1/mail'] as $path) {
            $_GET['_path'] = $path;
            self::assertTrue($router->needsSession(), $path);
        }
    }

    private static function routes(): Router
    {
        $router = new Router();
        $router->register('GET', '/v1/ping', static fn() => null);
        $router->register('GET', '/v1/mail/hit', static fn() => null, false);
        return $router;
    }

    // Apache decodes the path before the rule copies it into the query: without B,
    // /api/v1/x%26admin=1 would reach PHP as _path=v1/x plus a GET key admin=1
    public function test_rewrite_rule_escapes_the_path_it_puts_into_the_query(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 3) . '/api/.htaccess');

        self::assertMatchesRegularExpression('/^\s*RewriteRule \S+ index\.php\?_path=\$1 \[B,L,QSA\]$/m', $htaccess);
    }
}
