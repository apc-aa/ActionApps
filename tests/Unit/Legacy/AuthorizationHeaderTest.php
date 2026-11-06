<?php

declare(strict_types=1);

/**
 * get_authorization_header() (include/util.php) - the Bearer token for the API and the site module.
 * Without mod_php the header comes from $_SERVER or apache_request_headers(); the CLI has neither,
 * so the test supplies apache_request_headers() itself.
 */

namespace {
    if (!function_exists('apache_request_headers')) {
        function apache_request_headers(): array
        {
            return $GLOBALS['aa_test_request_headers'] ?? [];
        }
        define('AA_TEST_FAKE_APACHE_HEADERS', true);
    }
}

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\TestCase;

    final class AuthorizationHeaderTest extends TestCase
    {
        private array $server;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
        }

        protected function setUp(): void
        {
            $this->server = $_SERVER;
            unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION']);
            $GLOBALS['aa_test_request_headers'] = [];
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->server;
            unset($GLOBALS['aa_test_request_headers']);
        }

        private function headers(array $headers): void
        {
            if (!defined('AA_TEST_FAKE_APACHE_HEADERS')) {
                self::markTestSkipped('the SAPI has its own apache_request_headers()');
            }
            $GLOBALS['aa_test_request_headers'] = $headers;
        }

        public function test_server_variable_wins(): void
        {
            $_SERVER['HTTP_AUTHORIZATION'] = ' Bearer abc ';
            $this->headers(['Authorization' => 'Bearer other']);
            self::assertSame('Bearer abc', get_authorization_header());
        }

        public function test_apache_header(): void
        {
            $this->headers(['Authorization' => 'Bearer abc']);
            self::assertSame('Bearer abc', get_authorization_header());
        }

        public function test_apache_header_in_lower_case(): void
        {
            $this->headers(['authorization' => 'Bearer abc']);
            self::assertSame('Bearer abc', get_authorization_header());
        }

        public function test_no_header_is_empty_without_warning(): void
        {
            $this->headers(['Host' => 'example.org']);
            set_error_handler(static function (int $no, string $msg): never {
                throw new \ErrorException($msg, 0, $no);
            });
            try {
                self::assertSame('', get_authorization_header());
            } finally {
                restore_error_handler();
            }
        }
    }
}
