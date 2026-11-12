<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use AA\Api\JsonResponse;
use AA\Api\Router;
use PHPUnit\Framework\TestCase;

/**
 * What the router promises every AA API address: an exact path wins, a {name} segment matches
 * one segment of a narrow alphabet and reaches the handler as ordinary input, and anything else
 * is a 404 rather than a near miss. An endpoint that throws answers 500 to whoever called it - the
 * API is public, so the message (paths, SQL, ids) goes to the server log, not into the response.
 */
final class RouterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $get_before = [];

    private string $log = '';

    private string|false $log_before = false;

    protected function setUp(): void
    {
        $this->get_before = $_GET;
        $_GET             = [];
        $this->log        = (string) tempnam(sys_get_temp_dir(), 'aa-router');
        $this->log_before = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        $_GET = $this->get_before;
        ini_set('error_log', (string) $this->log_before);
        @unlink($this->log);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['PATH_INFO'], $_SERVER['REQUEST_URI']);
    }

    /**
     * Runs one request through a router and gives back the JSON it wrote.
     *
     * @return array<string, mixed>
     */
    private static function request(Router $router, string $path, string $method = 'GET'): array
    {
        $_GET['_path']             = ltrim($path, '/');
        $_SERVER['REQUEST_METHOD'] = $method;

        ob_start();
        $router->dispatch();
        $out = (string) ob_get_clean();

        return (array) json_decode($out, true);
    }

    /** A router with one exact address and one with an {id}. */
    private static function router(): Router
    {
        $router = new Router();
        $router->register('GET', '/v1/demo/list', static fn(): JsonResponse => JsonResponse::ok(['hit' => 'list']));
        $router->register('GET', '/v1/demo/{id}/today', static fn(): JsonResponse => JsonResponse::ok([
            'hit' => 'today',
            'id'  => $_GET['id'] ?? null,
        ]));

        return $router;
    }

    public function testExactPathStillMatchesAndWinsOverAPattern(): void
    {
        $router = self::router();
        // /v1/demo/list fits /v1/demo/{id} as well; the exact address must win
        $router->register('GET', '/v1/demo/{id}', static fn(): JsonResponse => JsonResponse::ok(['hit' => 'one']));

        self::assertSame('list', self::request($router, '/v1/demo/list')['hit']);
    }

    public function testParameterReachesTheHandlerAsInput(): void
    {
        $answer = self::request(self::router(), '/v1/demo/0123456789abcdef0123456789abcdef/today');

        self::assertSame('today', $answer['hit']);
        self::assertSame('0123456789abcdef0123456789abcdef', $answer['id']);
    }

    /** The alphabet is [0-9a-z_-], one segment - nothing else is a match at all. */
    public function testWhatAParameterRefusesToBe(): void
    {
        foreach ([
            'uppercase'  => '/v1/demo/ABC/today',
            'a dot'      => '/v1/demo/a.b/today',
            'two parts'  => '/v1/demo/a/b/today',
            'empty'      => '/v1/demo//today',
            'a space'    => '/v1/demo/a b/today',
            'a per cent' => '/v1/demo/a%2fb/today',
        ] as $what => $path) {
            self::assertSame('Not Found', self::request(self::router(), $path)['error'] ?? null, "$what is not an id");
        }
    }

    public function testTheMethodHasToMatchToo(): void
    {
        $answer = self::request(self::router(), '/v1/demo/0123456789abcdef0123456789abcdef/today', 'POST');

        self::assertSame('Not Found', $answer['error']);
        self::assertSame('POST', $answer['method']);
    }

    public function testAnUnknownPathIsAPlain404(): void
    {
        $answer = self::request(self::router(), '/v1/demo/nothing/here');

        self::assertSame('Not Found', $answer['error']);
        self::assertSame('/v1/demo/nothing/here', $answer['path']);
    }

    /** A parameter is a whole segment: the fixed parts around it still have to be there. */
    public function testTheFixedPartsOfThePathStillHaveToMatch(): void
    {
        self::assertSame('Not Found', self::request(self::router(), '/v1/demo/abc/yesterday')['error']);
        self::assertSame('Not Found', self::request(self::router(), '/v1/demo/abc')['error']);
    }

    /** The path is the authority on its own parameters - a query string cannot pose as one. */
    public function testThePathWinsOverAQueryStringOfTheSameName(): void
    {
        $_GET['id'] = 'ffffffffffffffffffffffffffffffff';

        self::assertSame('abc-1', self::request(self::router(), '/v1/demo/abc-1/today')['id']);
    }

    /** A trailing newline is not part of a path - the pattern must end where an exact route ends. */
    public function testAPathWithATrailingNewlineIsNotARoute(): void
    {
        self::assertSame('Not Found', self::request(self::router(), "/v1/demo/abc-1/today\n")['error'] ?? null);
    }

    /** A route is written by a developer; a mistyped parameter fails at registration, not as a silent 404. */
    public function testAMistypedParameterIsRefusedWhenTheRouteIsRegistered(): void
    {
        foreach (['/x/{a}{b}', '/y/{}', '/z/{p}/{p}', '/w/{Web}', '/v/{1x}', '/u/x{p}', '/t/a{b}c', '/s/a}b', '/r/a{b'] as $path) {
            try {
                (new Router())->register('GET', $path, static fn() => JsonResponse::ok([]));
                self::fail("$path was accepted");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_dispatch_exception_answers_without_its_message(): void
    {
        $router = new Router();
        $router->register('GET', '/v1/boom', static fn() => throw new \RuntimeException("SELECT * FROM users WHERE x='/var/www/secret'"));

        self::assertSame(['error' => 'Internal error'], self::request($router, '/v1/boom'));
        self::assertStringContainsString('/var/www/secret', (string) file_get_contents($this->log), 'the admin finds it in the log');
    }

    /** ?_path[]=x is not a path; the router answers 404 instead of dying in trim(). */
    public function test_dispatch_aPathThatIsNotAString_isNoRoute(): void
    {
        $_GET['_path']             = ['v1/boom'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PATH_INFO']      = '';
        $_SERVER['REQUEST_URI']    = '/aadev/api/';
        $router = new Router();
        $router->register('GET', '/v1/boom', static fn() => throw new \RuntimeException('the array was taken for a path'));

        ob_start();
        $router->dispatch();
        $body = (string) ob_get_clean();

        self::assertSame('Not Found', json_decode($body, true)['error'] ?? null);
    }

    // Apache decodes the path before the rule copies it into the query: without B,
    // /api/v1/x%26admin=1 would reach PHP as _path=v1/x plus a GET key admin=1
    public function test_rewrite_rule_escapes_the_path_it_puts_into_the_query(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 3) . '/api/.htaccess');

        self::assertMatchesRegularExpression('/^\s*RewriteRule \S+ index\.php\?_path=\$1 \[B,L,QSA\]$/m', $htaccess);
    }
}
