<?php

declare(strict_types=1);

/**
 * aa_trim() (include/util.php) - trim() of PHP knows only ASCII whitespace; in UTF-8 the non-breaking
 * space and the other Unicode spaces are trimmed too. The Trim insert function and {trim:} use it.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'        => 'http://localhost/aa/',
        'AA_INSTAL_PATH'       => '/aa/',
        'IMG_UPLOAD_PATH'      => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'         => dirname(__DIR__, 3) . '/',
        'PERM_LIB'             => 'sql',
        'DB_HOST'              => 'localhost',
        'DB_NAME'              => 'aa_test',
        'DB_USER'              => 'aa',
        'DB_PASSWORD'          => '',
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;

    final class AaTrimTest extends TestCase
    {
        private mixed $lang;

        public static function setUpBeforeClass(): void
        {
            // loading locsess.php warns about the missing web request; a separate process would report it as the test's
            set_error_handler(static fn(): bool => true);
            try {
                require_once __DIR__ . '/fixtures/store_fixtures.php';
                require_once __DIR__ . '/../../../include/util.php';
            } finally {
                restore_error_handler();
            }
        }

        protected function setUp(): void
        {
            $this->lang = $GLOBALS['mgettext_lang'] ?? null;
        }

        protected function tearDown(): void
        {
            $GLOBALS['mgettext_lang'] = $this->lang;
        }

        public static function utf8(): array
        {
            return [
                'spaces and new lines'   => [" \t Praha\r\n", 'Praha'],
                'non-breaking space'     => ["\u{A0}Praha\u{A0}", 'Praha'],
                'spaces inside are kept' => [' Nové  Město ', 'Nové  Město'],
                'nothing to trim'        => ['Praha', 'Praha'],
                'new lines inside kept'  => ["\n a\n\n b \n", "a\n\n b"],
                'ideographic space'      => ["\u{3000}Praha\u{2003}", 'Praha'],
                'zero width space kept'  => ["\u{200B}Praha\u{200B}", "\u{200B}Praha\u{200B}"],
                'only whitespace'        => [" \u{A0}\n\t ", ''],
                'empty'                  => ['', ''],
                'zero'                   => [' 0 ', '0'],
            ];
        }

        /** a long space run inside the text must stay linear without PCRE JIT - an unanchored `\s+$` takes 2 s here.
         *  Own process: PHP keeps a compiled pattern with the JIT setting it had then, so an earlier aa_trim()
         *  with JIT on would make the switch below void. */
        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function test_aa_trim_long_whitespace_run_inside_is_fast(): void
        {
            $GLOBALS['mgettext_lang'] = 'en-utf8';
            ini_set('pcre.jit', '0');
            $text  = 'a' . str_repeat(' ', 20000) . 'b';
            $start = hrtime(true);
            $this->assertSame($text, aa_trim(" $text\n"));
            $this->assertLessThan(0.2, (hrtime(true) - $start) / 1e9);
        }

        #[DataProvider('utf8')]
        public function test_aa_trim_utf8_trims_unicode_whitespace(string $text, string $expected): void
        {
            $GLOBALS['mgettext_lang'] = 'en-utf8';
            $this->assertSame($expected, aa_trim($text));
        }

        public function test_aa_trim_with_chars_trims_just_them(): void
        {
            $this->assertSame(' Praha ', aa_trim('-- Praha --', '-'));
        }

        /** windows-1250: 0xA0 is the non-breaking space there, but a byte of a letter in UTF-8 - left */
        public function test_aa_trim_single_byte_charset_trims_ascii_whitespace(): void
        {
            $GLOBALS['mgettext_lang'] = 'cz';
            $this->assertSame("Praha\xA0", aa_trim(" Praha\xA0\n"));
        }

        public function test_aa_trim_text_that_is_not_valid_utf8_is_trimmed_bytewise(): void
        {
            $GLOBALS['mgettext_lang'] = 'en-utf8';
            $this->assertSame("Praha \xE9", aa_trim(" Praha \xE9\n"));
        }

        /** a whitespace tail past pcre.backtrack_limit makes the pattern fail - the value still comes back trimmed */
        public function test_aa_trim_whitespace_tail_past_the_backtrack_limit_is_trimmed(): void
        {
            $GLOBALS['mgettext_lang'] = 'en-utf8';
            $limit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', '100');
            try {
                $this->assertSame('Praha', aa_trim(' Praha' . str_repeat(" \n", 500)));
            } finally {
                ini_set('pcre.backtrack_limit', (string) $limit);
            }
        }
    }
}
