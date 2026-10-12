<?php

declare(strict_types=1);

/**
 * {protectmail:<email>} writes the address by a script, split at '@'. The address comes from item
 * content (often a field without an e-mail validator), so its parts must stay JS strings, and the
 * link text, which the script inserts as HTML, must be HTML-escaped.
 *
 * include/stringexpand.php cannot be loaded on its own, so the class is extracted from the real
 * file and evaluated without its parent, as StringexpandDictionaryTest does.
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

    use PHPUnit\Framework\TestCase;

    final class StringexpandProtectmailTest extends TestCase
    {
        private const CLS = 'AA_Test_Stringexpand_Protectmail';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            if (class_exists(self::CLS, false)) {
                return;
            }
            $source = (string) file_get_contents(__DIR__ . '/../../../include/stringexpand.php');
            eval(strtr(self::extract($source, 'AA_Stringexpand_Protectmail'), [
                'class AA_Stringexpand_Protectmail extends AA_Stringexpand_Nevercache' => 'class ' . self::CLS,
            ]));
        }

        public function test_expand_splitsAPlainAddress(): void
        {
            $out = self::expand('info@ecn.cz');
            self::assertStringContainsString("'mai'+'lto:'+'info'+'@'+'ecn.cz'", $out);
            self::assertStringContainsString("insertAdjacentHTML('afterbegin','info'+'@'+'ecn.cz')", $out);
        }

        public function test_expand_keepsAQuoteInsideTheString(): void
        {
            $out = self::expand("x'+alert(1)+'@ecn.cz");
            self::assertStringNotContainsString("'x'+alert(1)", $out);
            self::assertStringContainsString("'x\\'+alert(1)+\\''+'@'+'ecn.cz'", $out);
        }

        public function test_expand_insertsTheAddressAsText(): void
        {
            $out = self::expand('<img src=x onerror=alert(1)>@ecn.cz');
            self::assertStringContainsString("insertAdjacentHTML('afterbegin','&lt;img src=x onerror=alert(1)&gt;'+'@'+'ecn.cz')", $out);
            self::assertSame(2, substr_count(strtolower($out), '<script') + substr_count(strtolower($out), '</script'), 'only the own script tags');
        }

        public function test_expand_givenText_isInsertedAsTheAuthorWroteIt(): void
        {
            self::assertStringContainsString("insertAdjacentHTML('afterbegin','\\x3Cb>Write us\\x3C/b>')", self::expand('info@ecn.cz', '<b>Write us</b>'));
        }

        private static function expand(string $email, string $text = ''): string
        {
            $cls = self::CLS;
            return (new $cls())->expand($email, $text);
        }

        private static function extract(string $source, string $name): string
        {
            $tokens = token_get_all($source);
            $start  = null;
            $depth  = 0;
            $out    = '';
            foreach ($tokens as $i => $token) {
                if (is_null($start)) {
                    if (is_array($token) && $token[0] === T_CLASS && ($tokens[$i + 2][1] ?? '') === $name) {
                        $start = $i;
                    } else {
                        continue;
                    }
                }
                $text = is_array($token) ? $token[1] : $token;
                $out .= $text;
                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}' && --$depth === 0) {
                    return $out;
                }
            }
            self::fail("class $name not found");
        }
    }
}
