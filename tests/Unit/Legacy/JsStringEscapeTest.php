<?php

declare(strict_types=1);

/**
 * escape4js() (include/util.php) and AA_Debug_Console::_do() (include/debug.class.php) put text
 * into a single-quoted JS string inside a <script> block - the debug console does it with request
 * data on any page with ?debug=. Two copies of one escaping: the console runs before util.php is
 * loaded. Bytes are kept as they are (pages may be in windows-1250), only the characters that end
 * the string or the block are escaped - every '<', as HTML ends the block on </script in any case.
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

    use AA_Debug_Console;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class JsStringEscapeTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/debug.class.php';
        }

        #[DataProvider('escapes')]
        public function test_escape4js_escapesForSingleQuotedStringInScript(string $text, string $expected): void
        {
            self::assertSame($expected, escape4js($text));
        }

        #[DataProvider('escapes')]
        public function test_debugConsole_escapesLikeEscape4js(string $text, string $expected): void
        {
            ob_start();
            (new AA_Debug_Console())->_do('log', [$text]);
            self::assertStringContainsString("console.log('$expected');\n", ob_get_clean());
        }

        public static function escapes(): array
        {
            return [
                'plain'            => ['Hello world', 'Hello world'],
                'apostrophe'       => ["It's", "It\\'s"],
                'backslash'        => ['a\\b', 'a\\\\b'],
                'backslash quote'  => ["a\\'", "a\\\\\\'"],
                'line ends'        => ["a\r\nb\nc\rd", 'a\\nb\\nc\\nd'],
                'utf-8 kept'       => ['Žluťoučký', 'Žluťoučký'],
                'windows-1250 kept' => ["\x8Ee\x9Ae", "\x8Ee\x9Ae"],
                'script tag'       => ['<script>x</script>', '\\x3Cscript>x\\x3C/script>'],
                'end tag in caps'  => ['</SCRIPT><b>', '\\x3C/SCRIPT>\\x3Cb>'],
                'end tag mixed'    => ['</ScRiPt >', '\\x3C/ScRiPt >'],
                'html comment'     => ['<!--<script>', '\\x3C!--\\x3Cscript>'],
                'any tag'          => ['a<b>c', 'a\\x3Cb>c'],
                'backslash tag'    => ['\\<', '\\\\\\x3C'],
                'escape as text'   => ['\\x3C', '\\\\x3C'],
                'nul kept'         => ["a\0b", "a\0b"],
                'separator kept'   => ["a\u{2028}b", "a\u{2028}b"],
            ];
        }
    }
}
