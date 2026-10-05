<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * How AA_Unalias_Callback::expand_bracketed() splits {command:params} into the command and its
 * parameters.
 *
 * include/stringexpand.php cannot be loaded on its own (perm_core, formutil, a session and a
 * database), so the class is extracted from the real file and evaluated with the helpers it
 * reaches on these paths renamed to stubs, as tests/Unit/Legacy/StringexpandIncludeTest.php does.
 */
final class UnaliasCallbackTest extends TestCase
{
    private const CLS = 'AA_Test_Unalias_Callback';

    /** @var list<array{string, array<int, string>}> what the stubbed StrExpand() was asked to run */
    public static array $expanded = [];

    public static function setUpBeforeClass(): void
    {
        if (class_exists(self::CLS, false)) {
            return;
        }
        $source = (string) file_get_contents(__DIR__ . '/../../../include/stringexpand.php');

        // keeps its name - the definition in include/util.php, if loaded, does the same job
        if (!function_exists('aa_substr')) {
            eval('function aa_substr($text, $start, $len = null): string { return \AA\Util\Txt::substr($text, $start, $len); }');
        }
        eval('
            function aa_test_unalias_quote_colons($text) { return "quoted(" . $text . ")"; }
            function aa_test_unalias_class_name($name, $mask) { return $mask . ucwords(strtolower($name)); }
            function aa_test_unalias_strexpand($class_name, array $params = []) {
                \Tests\Unit\Legacy\UnaliasCallbackTest::$expanded[] = [$class_name, $params];
                return "expanded";
            }
            class AA_Test_Stringexpand_Now {
                public static function parseParam(string $params, string $delimiter = ":"): array {
                    return ($params === "") ? [] : explode($delimiter, $params);
                }
            }
        ');
        eval(strtr(self::extract($source, 'AA_Unalias_Callback'), [
            'class AA_Unalias_Callback'            => 'class AA_Test_Unalias_Callback',
            'QuoteColons('                         => 'aa_test_unalias_quote_colons(',
            'AA_Serializable::constructClassName(' => 'aa_test_unalias_class_name(',
            "'AA_Stringexpand_'"                   => "'AA_Test_Stringexpand_'",
            'StrExpand('                           => 'aa_test_unalias_strexpand(',
        ]));
    }

    protected function setUp(): void
    {
        self::$expanded = [];
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

    /** @return array{string, list<string>} the expansion and every warning it raised */
    private static function expand(string $inner): array
    {
        $cls      = self::CLS;
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $out = (new $cls(null, null))->expand_bracketed([0 => '{' . $inner . '}', 1 => $inner]);
        } finally {
            restore_error_handler();
        }

        return [$out, $warnings];
    }

    /** {now} is the whole expression - there is no delimiter after the name to read */
    public function test_expandBracketed_commandWithoutParameters_runsWithoutWarning(): void
    {
        [$out, $warnings] = self::expand('now');

        self::assertSame([], $warnings);
        self::assertSame('expanded', $out);
        self::assertSame([['AA_Test_Stringexpand_Now', []]], self::$expanded);
    }

    public function test_expandBracketed_commandWithParameters_getsThemSplit(): void
    {
        [, $warnings] = self::expand('now:U:x');

        self::assertSame([], $warnings);
        self::assertSame([['AA_Test_Stringexpand_Now', ['U', 'x']]], self::$expanded);
    }

    /** an unknown {xyz} stays in the page as it was written */
    public function test_expandBracketed_unknownWord_isReturnedAsWrittenWithoutWarning(): void
    {
        [$out, $warnings] = self::expand('xyz');

        self::assertSame([], $warnings);
        self::assertSame('quoted({xyz})', $out);
        self::assertSame([], self::$expanded);
    }
}
