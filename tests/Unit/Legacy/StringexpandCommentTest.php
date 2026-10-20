<?php

declare(strict_types=1);

/**
 * {#comments} are cut out before the expansion, so nothing inside them runs - a commented-out view,
 * rss or include costs nothing. The text around them comes out the same as when the expander removed
 * them innermost-first: the braces pair the same way, a comment with an empty {} inside or without
 * its closing brace stays text, as before.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA_Aliases;
use AA_Unalias_Callback;
use AA_View;
use itemview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use zids;

#[RunClassInSeparateProcess]
final class StringexpandCommentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';   // RecordingDb - the protection of URL values reads its switch
        } finally {
            restore_error_handler();
        }
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $GLOBALS['STRINGEXPAND_SHORTCUTS'] = [
            'Box'      => '<b>_#P1{# c}</b>',
            'Param'    => '{# _#P1 }y',
            'Open'     => 'a{# b',
            'Close'    => 'b}c',
            'Inline'   => '{strlen:{(ab{# c}d)}}',
            'Define'   => 'a{# {define:inalias:1}}b',
        ];
    }

    public static function tearDownAfterClass(): void
    {
        $GLOBALS['spareDBs'] = [];
        unset($GLOBALS['STRINGEXPAND_SHORTCUTS'], $GLOBALS['als'], $GLOBALS['QueryIDsCount']);
    }

    #[DataProvider('sameAsBefore')]
    public function test_unalias_comment_leavesTheTextAroundAsBefore(string $input, string $output): void
    {
        self::assertSame($output, AA::Un($input));
        self::assertSame($output, self::eager($input), 'what the expander gave before comments were cut out');
    }

    public static function sameAsBefore(): array
    {
        return [
            'in a parameter'                 => ['{ifeq:a:a:x{# c}y:no}', 'xy'],
            'a colon inside'                 => ['{ifeq:a:a:yes{#:}:no}', 'yes'],
            'in the condition'               => ['{ifeq:{# c}a:a:yes:no}', 'yes'],
            'between # and :'                => ['{ifeq:a:a:x#{# c}:y:no}', 'x:y'],
            'joins http'                     => ['{ifeq:a:a:ht{# c}tp://x:no}', 'http://x'],
            'javascript inside'              => ["a{# function f() { return 1; } }b", 'ab'],
            'expressions inside'             => ['a{# {strlen:abc} {ifeq:1:1:x} }b', 'ab'],
            'deep inside'                    => ['a{# {ifeq:{strlen:x}:1:{# {strlen:y}}} }b', 'ab'],
            'nested comments'                => ['{#{#a}b}', ''],
            'two comments'                   => ['a{#1}b{#2}c', 'abc'],
            'crlf lines'                     => ["a{#\r\n  {strlen:x}\r\n}b", 'ab'],
            'html comment inside'            => ['a{#<!-- {strlen:x} -->}b', 'ab'],
            'empty comment'                  => ['x{#}y', 'xy'],
            'one more closing brace'         => ['{#a}}', '}'],
            'brace before'                   => ['{{#a}', '{'],
            'joins into a command'           => ['{str{# c}len:abc}', '3'],
            'joins into text braces'         => ['{a{#c}b}', '{ab}'],
            'handlebars'                     => ['{{#each}}x{{/each}}', '{}x{{/each}}'],
            'inside literal'                 => ['{-<b>{# x}</b>}', '<b></b>'],
            'literal inside'                 => ['a{# {-x:y} }b', 'ab'],
            'not closed'                     => ['a{#b', 'a{#b'],
            'not closed, expressions inside' => ['a{# {strlen:abc} b', 'a{# 3 b'],
            'not closed, comment inside'     => ['a{# b {#c} d', 'a{# b  d'],
            'empty braces inside'            => ['a{# a {} b}c', 'a{# a {} b}c'],
            'empty braces deep inside'       => ['a{# {x{}y} }c', 'a{# {x{}y} }c'],
            'comment by empty braces'        => ['a{# x {#y} {} }c', 'a{# x  {} }c'],
            'empty braces after'             => ['{ifset:1:{}:{# x}}', '{ifset:1:{}:}'],
            'inline alias inside'            => ['a{# {( {strlen:abc} )} }b', 'ab'],
            'inside inline alias - raw text' => ['{strlen:{(ab{# c}d)}}', '8'],
            'inline alias expanded'          => ['{expand:{(a{# {strlen:x}}b)}}', 'ab'],
            'quoted text given'              => ["\x1E|~@a{# c}", ':'],
        ];
    }

    #[DataProvider('shortcuts')]
    public function test_unalias_commentInShortcut_isCutOutAsBefore(string $input, string $output): void
    {
        self::assertSame($output, AA::Un($input));
    }

    public static function shortcuts(): array
    {
        return [
            'in the definition'           => ['{_:Box:x}', '<b>x</b>'],
            'around the parameter'        => ['{_:Param:x}', 'y'],
            'around the call'             => ['{# {_:Box:x} }z', 'z'],
            'opened in the definition'    => ['{_:Open}tail}z', 'az'],
            'closed in the definition'    => ['{# a{_:Close}d', 'cd'],
            'inside its inline alias'     => ['{_:Inline}', '8'],
        ];
    }

    public function test_unalias_dequoteFalse_keepsTheQuotes(): void
    {
        self::assertSame("a\x1E|~@ab", AA::Un('a{#c}{-:}b', '', null, false));
    }

    /** a value from the URL with braces is text while the protection is on - its comment too */
    public function test_unalias_commentInUrlValue_isText(): void
    {
        $GLOBALS['als'] = ['MYALS' => 'a{# c}b', 'PLAIN' => 'v'];
        self::assertSame('a&#123;# c&#125;b', AA::Un('{MYALS}'));
        self::assertSame('wv', AA::Un('{# {PLAIN} }w{PLAIN}'));
        unset($GLOBALS['als']);
    }

    /** top and bottom HTML of a view: _#ALIASES are filled in after the expansion, so a comment in their value stays text */
    public function test_unaliasWithScroller_commentInAliasValue_staysText(): void
    {
        $aliases = new AA_Aliases();
        $aliases->addFromArray(AA_View::getAliasesFromUrl(['ZZZZZZZZ' => 'a{# c}b']));
        $GLOBALS['QueryIDsCount'] = 0;
        $listing = new itemview(['group_by' => '', 'selected_item' => ''], $aliases, new zids([], 's'), 0, 1);

        self::assertSame('top  a{# c}b', $listing->unaliasWithScroller('top {# {strlen:x} _#ZZZZZZZZ } _#ZZZZZZZZ'));
    }

    /** the code inside a comment no longer runs - a template which needs it keeps that code outside the comment */
    public function test_unalias_commandsInComment_doNotRun(): void
    {
        self::assertSame('', AA::Un('{# {define:incomment:1}}{var:incomment}'));
        self::assertSame('', AA::Un('{# {var:incomment2:1}}{var:incomment2}'));
        self::assertSame('ab', AA::Un('{_:Define}{var:inalias}'));
        self::assertSame('1', AA::Un('{define:outside:1}{# {define:outside:2}}{var:outside}'));
    }

    /** The one difference: braces inside a comment whose content expands to nothing became {}, which the expander
     *  never expands, so the comment stayed on the page as text. Now it is cut out like any other. */
    #[DataProvider('emptiedBraces')]
    public function test_unalias_commentWithEmptiedBraces_isCutOut(string $input, string $before): void
    {
        self::assertSame('ab', AA::Un($input));
        self::assertSame($before, self::eager($input));
    }

    public static function emptiedBraces(): array
    {
        return [
            'empty expression' => ['a{# {{ifeq:x:y:z}} }b', 'a{# {} }b'],
            'comment inside'   => ['a{# {{#c}} }b', 'a{# {} }b'],
        ];
    }

    /** random brace soup: cutting comments out first gives the same text as the expander alone - but for the case above */
    public function test_unalias_randomText_sameAsBefore(): void
    {
        // the expressions never expand to nothing, so only a comment can empty the braces around it
        $parts = ['{#', '{#', '{', '{', '}', '}', '}', '{}', ':', '#', '#:', 'a', ' ', "\n", '{strlen:', '{ifeq:a:a:x', '{ifset:1:y', '{-x', 'tp://', 'ht'];
        mt_srand(20261020);
        $emptied = 0;
        for ($i = 0; $i < 5000; ++$i) {
            $text = '';
            for ($n = mt_rand(1, 20); $n > 0; --$n) {
                $text .= $parts[mt_rand(0, count($parts) - 1)];
            }
            $before = self::eager($text);
            if (($before !== AA::Un($text)) && preg_match('/\{#[^{}]*(?:\{[^{}]*)*\{\}/', $before)) {
                ++$emptied;
                continue;
            }
            self::assertSame($before, AA::Un($text), json_encode($text));
        }
        self::assertLessThan(100, $emptied, 'the case above is rare - many more mean a different difference');
    }

    /** one pass over the braces - a text full of unclosed comments does not take quadratic time */
    public function test_removeComments_manyUnclosed_staysAsItIs(): void
    {
        $text = str_repeat('{#', 200000);
        self::assertSame($text, \AA_Stringexpander::removeComments($text));
    }

    /** the expansion loop of AA_Stringexpander::unalias() alone - comments removed when the loop reaches them */
    private static function eager(string $text): string
    {
        $callback = [new AA_Unalias_Callback(null, null), 'expand_bracketed'];
        $text     = \AA_Stringexpander::ApplyInlineAlias($text);
        $count    = 0;
        do {
            $text   = preg_replace_callback('/[{]([^{}]+)[}]/s', $callback, $text, 1, $n);
            $count += $n;
        } while ($n);
        return $count ? DeQuoteColons($text) : $text;
    }
}
