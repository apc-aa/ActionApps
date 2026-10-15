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
}
