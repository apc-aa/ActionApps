<?php

declare(strict_types=1);

/**
 * return_url comes from the request and ends in a link, a button's window.location or a redirect
 * (expand_return_url(), go_return_or_url() in include/util.php). Relative and http(s) URLs pass
 * as they are, a URL of another scheme is dropped - IsNavigableUrl() in include/go_url.php decides.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class ReturnUrlSchemeTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/go_url.php';
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['return_url'], $GLOBALS['sess']);
        }

        #[DataProvider('kept')]
        public function test_expandReturnUrl_relativeOrHttp_isKept(string $url): void
        {
            $GLOBALS['return_url'] = $url;
            unset($GLOBALS['sess']);

            self::assertTrue(IsNavigableUrl($url));
            self::assertSame($url, expand_return_url(false));
            self::assertSame($url, expand_return_url(true));   // no session object - nothing is added
        }

        public static function kept(): array
        {
            return [
                'page'          => ['index.php'],
                'query'         => ['itemedit.php?x=a:b'],
                'root relative' => ['/aa/admin/index.php'],
                'https'         => ['https://x/'],
                'http'          => ['http://example.org/a?b=1&c=2'],
                'keyword'       => ['close_dialog'],
                'other host'    => ['//example.org/a'],
            ];
        }

        #[DataProvider('dropped')]
        public function test_expandReturnUrl_otherScheme_isEmpty(string $url): void
        {
            $GLOBALS['return_url'] = $url;
            unset($GLOBALS['sess']);

            self::assertFalse(IsNavigableUrl($url));
            self::assertSame('', expand_return_url(false));
        }

        public static function dropped(): array
        {
            return [
                'javascript'     => ['javascript:alert(1)//'],
                'upper case'     => [' JavaScript:alert(1)'],
                'tab inside'     => ["java\tscript:alert(1)"],
                'data'           => ['data:text/html,x'],
                'vbscript'       => ['vbscript:x'],
            ];
        }
    }
}
