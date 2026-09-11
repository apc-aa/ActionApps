<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Session;

/**
 * Session::self_url() (include/phplib.php) is what the login form posts to.
 * It used to take $_SERVER['PHP_SELF'], which carries PATH_INFO: a request for
 * /aaa/admin/index.php3/aaa/admin/index.php3/ got a form posting to exactly
 * that path, and a scanner following it produced 2 875 login attempts against
 * a 255-byte URL on toolkit.ecn.cz (2026-08-06). The action must be the script
 * itself; the query string is kept as before.
 */
final class SessionSelfUrlTest extends TestCase
{
    private array $server_backup = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../include/phplib.php';
        $this->server_backup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server_backup;
    }

    private function selfUrl(array $server): string
    {
        foreach (['PHP_SELF', 'SCRIPT_NAME', 'PATH_INFO', 'REQUEST_URI', 'QUERY_STRING'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER = array_merge($_SERVER, $server);
        // the constructor starts a PHP session; the method under test only reads $_SERVER
        return (new ReflectionClass(Session::class))->newInstanceWithoutConstructor()->self_url();
    }

    public function test_script_with_query_string_is_unchanged(): void
    {
        $this->assertSame('/aaa/admin/index.php3?relogin=true', $this->selfUrl([
            'PHP_SELF'     => '/aaa/admin/index.php3',
            'SCRIPT_NAME'  => '/aaa/admin/index.php3',
            'REQUEST_URI'  => '/aaa/admin/index.php3?relogin=true',
            'QUERY_STRING' => 'relogin=true',
        ]));
    }

    public function test_query_string_falls_back_when_request_uri_has_none(): void
    {
        $this->assertSame('/aaa/admin/index.php3?x=1', $this->selfUrl([
            'PHP_SELF'     => '/aaa/admin/index.php3',
            'SCRIPT_NAME'  => '/aaa/admin/index.php3',
            'REQUEST_URI'  => '/aaa/admin/index.php3',
            'QUERY_STRING' => 'x=1',
        ]));
    }

    public function test_path_info_is_not_echoed_into_the_action(): void
    {
        $this->assertSame('/aaa/admin/index.php3', $this->selfUrl([
            'PHP_SELF'     => '/aaa/admin/index.php3/aaa/admin/index.php3/',
            'SCRIPT_NAME'  => '/aaa/admin/index.php3',
            'PATH_INFO'    => '/aaa/admin/index.php3/',
            'REQUEST_URI'  => '/aaa/admin/index.php3/aaa/admin/index.php3/',
            'QUERY_STRING' => '',
        ]));
    }

    public function test_missing_script_name_falls_back_to_php_self(): void
    {
        $this->assertSame('/aaa/admin/index.php3', $this->selfUrl([
            'PHP_SELF'    => '/aaa/admin/index.php3',
            'REQUEST_URI' => '/aaa/admin/index.php3',
        ]));
    }
}
