<?php

declare(strict_types=1);

/**
 * go_url() moves by a script once the headers are sent, and the URL may come from the request
 * (itemedit.php return_url). The script must keep the URL a JS string and must not run a
 * javascript: or data: URL, which a Location header would never run.
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

    final class GoUrlScriptTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/go_url.php';
        }

        #[DataProvider('urls')]
        public function test_goUrlScript_movesToTheUrlAsAJsString(string $url, string $expected): void
        {
            self::assertSame("<script>window.location = '$expected';</script>", GoUrlScript($url));
        }

        public static function urls(): array
        {
            return [
                'absolute'        => ['https://example.org/a?b=1&c=2', 'https://example.org/a?b=1&c=2'],
                'relative'        => ['itemedit.php?x=a:b', 'itemedit.php?x=a:b'],
                'root relative'   => ['/aa/admin/index.php', '/aa/admin/index.php'],
                'quote breaks out' => ["x';alert(1);'", "x\\';alert(1);\\'"],
                'double quote'    => ['x";alert(1);"', 'x";alert(1);"'],
                'script end'      => ['x</script><script>alert(1)', 'x\\x3C/script>\\x3Cscript>alert(1)'],
            ];
        }

        #[DataProvider('scriptUrls')]
        public function test_goUrlScript_doesNotRunAScriptUrl(string $url): void
        {
            self::assertSame('', GoUrlScript($url));
        }

        public static function scriptUrls(): array
        {
            return [
                'javascript'        => ['javascript:alert(1)'],
                'upper case'        => ['JavaScript:alert(1)'],
                'leading space'     => [' javascript:alert(1)'],
                'tab inside'        => ["java\tscript:alert(1)"],
                'newline inside'    => ["java\nscript:alert(1)"],
                'data'              => ['data:text/html,<script>alert(1)</script>'],
                'vbscript'          => ['vbscript:x'],
            ];
        }
    }
}
