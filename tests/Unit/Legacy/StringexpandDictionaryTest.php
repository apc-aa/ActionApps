<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * {dictionary:} looks up the keywords by the words of the text instead of strtr() with the whole
 * dictionary.
 *
 * Pins that this gives exactly what strtr() with the whole dictionary gives - the old code. A
 * keyword missed by the grouping would silently stay unlinked on live sites (biom.cz links
 * ~10 000 keywords in every article).
 *
 * include/stringexpand.php cannot be loaded on its own, so the class is extracted from the real
 * file and evaluated without its parent, as tests/Unit/Legacy/StringexpandIncludeTest.php does.
 */
final class StringexpandDictionaryTest extends TestCase
{
    private const CLS = 'AA_Test_Stringexpand_Dictionary';

    private const KEYWORDS = [
        'bio', 'biomasa', 'biomasa ve', 'biomasa ve světě', 'bio plyn', 'plyn', 'kotel', 'kotle na dřevo', 'dřevo',
        'AA', 'A', 'e-mail', '2026', '(ZOO)', 'Praha 2', 'čistírna', 'Čistírna odpadních vod', 'x.y',
    ];

    public static function setUpBeforeClass(): void
    {
        if (class_exists(self::CLS, false)) {
            return;
        }
        $source = (string) file_get_contents(__DIR__ . '/../../../include/stringexpand.php');
        eval(strtr(self::extract($source, 'AA_Stringexpand_Dictionary'), [
            'class AA_Stringexpand_Dictionary extends AA_Stringexpand' => 'class ' . self::CLS,
        ]));
    }

    public function test_replaceInText_samples_matchWholeDictionaryStrtr(): void
    {
        $samples = [
            'Biomasa ve světě a bio plyn.',
            'biomasa, biomasa; (biomasa) "biomasa" bio-plyn bioplyn',
            'kotle na dřevo i kotle na uhlí, dřevo.',
            '_AA_0123456789abcdef0123456789abcdef_ShCutbiomasa_AA_0123456789abcdef0123456789abcdef_ShCut kotel',
            'AA A AAA AA#@ A#@ AA#@AA#@ bio',
            'rok 2026, 2026. 20261',
            'Praha 2 a Praha 22, (ZOO) i ZOO',
            'Čistírna odpadních vod, čistírna odpadních vod',
            'x.y x y',
            '',
            "e-mail\ne-mail\r\nbio",
        ];
        foreach ($samples as $text) {
            self::assertSame(self::wholeDictionary($text), self::grouped($text), $text);
        }
    }

    public function test_replaceInText_randomTexts_matchWholeDictionaryStrtr(): void
    {
        $vocabulary = array_merge(self::KEYWORDS, array_map('ucfirst', self::KEYWORDS), ['ve', 'světě', 'na', 'uhlí', 'odpadních', 'vod', 'xy', 'Bio', 'ZOO', '22']);
        $glue       = [' ', ' ', ' ', ', ', '. ', '(', ')', '"', "'", ';', ':', '!', '?', "\n", '-', '', '_AA_0123456789abcdef0123456789abcdef_ShCut'];
        mt_srand(20261009);
        for ($n = 0; $n < 400; $n++) {
            $text = '';
            for ($i = mt_rand(0, 30); $i > 0; $i--) {
                $text .= $vocabulary[mt_rand(0, count($vocabulary) - 1)] . $glue[mt_rand(0, count($glue) - 1)];
            }
            self::assertSame(self::wholeDictionary($text), self::grouped($text), $text);
        }
    }

    public function test_indexPairs_longestKeyFirst(): void
    {
        $cls             = self::CLS;
        [$pairs, $multi] = $cls::indexPairs(self::pairs());

        self::assertSame('<a>biomasa ve světě</a>', $pairs['biomasaAA#@ AA#@veAA#@ AA#@světě']);
        self::assertSame('<a>biomasa</a>', $pairs['biomasa']);
        self::assertSame([5, 3], $multi['biomasa']);
        self::assertSame('<a>(ZOO)</a>', $pairs['AA#@(AA#@ZOOAA#@)AA#@']);
        self::assertSame([5], $multi['']);
        self::assertArrayNotHasKey('kotel', $multi);
        self::assertCount(count(self::pairs()), $pairs);
    }

    /** the replacement the old code did: strtr() with every keyword of the dictionary */
    private static function wholeDictionary(string $text): string
    {
        $cls  = self::CLS;
        $text = 'AA#@' . strtr($text, $cls::defineDelimiters()) . 'AA#@';

        return str_replace('AA#@', '', strtr($text, array_merge(self::pairs(), self::shortcuts())));
    }

    private static function grouped(string $text): string
    {
        $cls = self::CLS;

        return $cls::replaceInText($text, $cls::indexPairs(self::pairs()), self::shortcuts(), $cls::defineDelimiters());
    }

    /** search_kw => link, built as getDictReplaceIndex() builds them */
    private static function pairs(): array
    {
        $cls        = self::CLS;
        $delimiters = $cls::defineDelimiters();
        $pairs      = [];
        foreach (self::KEYWORDS as $kw) {
            $pairs['AA#@' . strtr($kw, $delimiters) . 'AA#@'] = "<a>$kw</a>";
            if (($first_upper = strtoupper($kw[0])) !== $kw[0]) {
                $kw[0]                                            = $first_upper;
                $pairs['AA#@' . strtr($kw, $delimiters) . 'AA#@'] = "<a>$kw</a>";
            }
        }
        return $pairs;
    }

    private static function shortcuts(): array
    {
        return ['_AA_0123456789abcdef0123456789abcdef_ShCut' => '<b class="x">'];
    }

    /** Source of one class, from its keyword to the closing brace (comments/strings safe) */
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
        self::fail("$name not found in include/stringexpand.php");
    }
}
