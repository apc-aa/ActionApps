<?php

declare(strict_types=1);

/**
 * The branches of {ifeq:...}, {ifset:...} and the other conditions which the condition does not choose
 * are not expanded, so a view or rss in them costs nothing. The output is the same as when the expander
 * expanded everything inside the condition first - only what the code in a branch not chosen did
 * ({define}, {mail}...) no longer happens.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA_Unalias_Callback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

#[RunClassInSeparateProcess]
final class StringexpandConditionTest extends TestCase
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
            'One'     => '1',
            'Colon'   => 'a:b',
            'Close'   => '}',
            'Hash'    => 'x#',
            'Http'    => 'http',
            'Slashes' => '//x',
            'Cond'    => '{ifeq:_#P1:1:{define:inshortcut:yes}yes:no}',
        ];
    }

    public static function tearDownAfterClass(): void
    {
        $GLOBALS['spareDBs'] = [];
        unset($GLOBALS['STRINGEXPAND_SHORTCUTS'], $GLOBALS['als']);
    }

    #[DataProvider('sameAsBefore')]
    public function test_unalias_condition_givesWhatItGaveBefore(string $input, string $output): void
    {
        self::assertSame($output, AA::Un($input));
        self::assertSame($output, self::eager($input), 'what the expander gave when it expanded the branches first');
    }

    public static function sameAsBefore(): array
    {
        return [
            'first chosen'                 => ['{ifeq:a:a:{strlen:x}:{strlen:yy}}', '1'],
            'else chosen'                  => ['{ifeq:a:b:{strlen:x}:{strlen:yy}}', '2'],
            'none chosen'                  => ['{ifeq:a:b:{strlen:x}}', ''],
            'second option'                => ['{ifeq:b:a:{strlen:x}:b:{strlen:yy}:{strlen:zzz}}', '2'],
            'head expanded'                => ['{ifeq:{strlen:ab}:2:{strlen:abc}:no}', '3'],
            'option expanded'              => ['{ifeq:2:{strlen:ab}:{strlen:abc}:no}', '3'],
            'option after the chosen'      => ['{ifeq:a:a:{strlen:x}:{strlen:o2}:{strlen:yy}:{strlen:zzz}}', '1'],
            'option after the chosen runs' => ['{ifeq:a:a:{strlen:L}:{define:sb1:o}:M}[{var:sb1}]', '1[o]'],
            // the code in the parameters runs in the same order as before
            'redefined in the conditions'  => ['{ifset:{var:sb2:A}:[{var:sb2}]:{var:sb2:B}:[{var:sb2}]:none}', '[A]'],
            'redefined in the options'     => ['{ifeq:{var:sb3:a}:a:[{var:sb3}]:{var:sb3:b}:[{var:sb3}]:none}', '[a]'],
            'option after reads later'     => ['{define:sb4:}{ifeq:a:a:[{var:sb4}]:{var:sb4:ran}:no}', '[]'],
            'option after reads the branch' => ['{define:sb5:}{define:sb6:}{ifeq:a:a:{define:sb5:1}:{var:sb6:{var:sb5}}x:no}[{var:sb6}]', '[1]'],
            'many branches, bracket options' => ['{ifeq:z' . str_repeat(':{strlen:o}:{strlen:x}', 100) . ':e}', 'e'],
            'many branches'                => ['{ifeq:z' . str_repeat(':o:{strlen:x}', 300) . ':z:{strlen:ab}:e}', '2'],
            '_#1 and _#2'                  => ['{ifeq: a :a:[_#1|_#2|{strlen:x}]:no}', '[a|a|1]'],
            'if'                           => ['{if:{strlen:abc}:>:2:{strlen:big}:{strlen:s}}', '3'],
            'if, second compare'           => ['{if:5:<:3:{strlen:a}:6:{strlen:bb}:no}', '2'],
            'if, _#1'                      => ['{if:5:>:3:[_#1]{strlen:a}}', '[5]1'],
            'ifin, _#2'                    => ['{ifin:abcdef:cd:[_#2]{strlen:xyz}:no}', '[cd]3'],
            'ifset, set'                   => ['{ifset:{strlen:a}:[_#1]{strlen:bb}:{strlen:c}}', '[1]2'],
            'ifset, empty'                 => ['{ifset::{strlen:bb}:{strlen:c}}', '1'],
            'ifset, pairs'                 => ['{ifset::{strlen:a}:x:{strlen:bb}:{strlen:ccc}}', '2'],
            'ifset, just the condition'    => ['{ifset:{strlen:abc}}', '3'],
            'iftext'                       => ['{iftext:<!-- x -->:{strlen:a}:{strlen:bb}}', '2'],
            'if1, 1'                       => ['{if1:1:{strlen:a}:{strlen:bb}}', '1'],
            'if1, 0'                       => ['{if1:0:{strlen:a}:{strlen:bb}}', '2'],
            'if1, more parameters'         => ['{if1:0:{strlen:a}:{strlen:bb}:{strlen:ccc}}', '2'],
            'ifbit'                        => ['{ifbit:13:8:{strlen:a}:{strlen:bb}}', '1'],
            'ifintersect'                  => ['{ifintersect:a-b:b-c:{strlen:a}:{strlen:bb}}', '1'],
            'iftype'                       => ['{iftype:abc:number:{strlen:a}:{strlen:bb}}', '2'],
            'ifeqfield without an item'    => ['{ifeqfield::headline........:x:{strlen:a}:{strlen:bb}}', '2'],
            'name in capitals'             => ['{IfEq:a:a:{strlen:x}:no}', '1'],
            'nested'                       => ['{ifeq:a:a:{ifeq:b:c:{strlen:x}:{strlen:yy}}:{ifeq:1:1:{strlen:zzz}}}', '2'],
            'condition in the head'        => ['{ifeq:{ifset:x:a:b}:a:{strlen:aa}:no}', '2'],
            'condition in a branch'        => ['{ifeq:a:b:{ifeq:1:1:{strlen:x}}:{strlen:yy}}', '2'],
            'text around'                  => ['<{ifeq:a:b:{strlen:x}:{strlen:yy}}>{strlen:abc}', '<2>3'],
            'two conditions'               => ['{ifeq:a:b:{strlen:x}:y}{ifeq:a:a:{strlen:xx}:y}', 'y2'],
            'literal colon'                => ['{ifeq:a:b:x#:{strlen:y}:{strlen:q}#:z}', '1:z'],
            'http'                         => ['{ifeq:a:a:http://x{strlen:y}:no}', 'http://x1'],
            'quoted colon'                 => ['{ifeq:a:a:{-x:y}:no}', 'x:y'],
            'option with a colon'          => ['{ifeq:{-a:b}:a#:b:{strlen:x}:no}', '1'],
            'empty parameters'             => ['{ifeq:::{strlen:x}:no}', '1'],
            'nothing before # and :'       => ['{ifeq:a:a:#{ifeq:1:2:z}:x:no}', ':x'],
            'tp: before a bracket'         => ['{ifeq:a:a:http:{ifeq:1:1://x}:no}', 'http://x'],
            'a bracket before ://'         => ['{ifeq:a:a:{ifeq:1:1:http}://x:no}', 'http://x'],
            'tp at a separator'            => ['{ifin:http:http:{strlen:x}}', '1'],
            'empty braces'                 => ['{ifeq:a:a:{strlen:x}{}:no}', '{ifeq:a:a:1{}:no}'],
            'not closed'                   => ['{ifeq:a:a:{strlen:x}', '{ifeq:a:a:1'],
            'comment in a branch'          => ['{ifeq:a:b:{# {strlen:x}}y:z}', 'z'],
            'inline alias in a branch'     => ['{ifeq:a:b:{strlen:{(abc)}}:{strlen:{(de)}}}', '2'],
            'javascript in a branch'       => ['{ifset:1:<script>if (a) { b(); }</script>:no}', '<script>if (a) { b(); }</script>'],
            'alias text'                   => ['{ifeq:a:a:_#HEADLINE{strlen:x}:no}', '_#HEADLINE1'],
            'quoted text given'            => ["{ifeq:a:a:\x1E|~@a{strlen:x}:no}", ':1'],
            // {_:...} gives text which is expanded after it, so its ':', braces or '#' may split the condition differently
            'shortcut in the head'         => ['{ifeq:{_:One}:1:{strlen:x}:no}', '1'],
            'colon in the head'            => ['{ifeq:{_:Colon}:a:{strlen:x}:{strlen:yy}:no}', 'no'],
            'colon in a branch'            => ['{ifeq:a:b:{_:Colon}:{strlen:x}}', ''],
            'colon in the chosen branch'   => ['{ifeq:a:a:{_:Colon}:{strlen:x}}', 'a'],
            'shortcut in a branch'         => ['{ifeq:a:b:{_:One}:{strlen:yy}}', '2'],
            'shortcut in the chosen'       => ['{ifeq:a:a:{_:One}{strlen:x}:no}', '11'],
            'brace from a shortcut'        => ['{ifeq:a:a:x{_:Close}y:no}', 'xy:no}'],
            '# from a shortcut'            => ['{ifeq:a:a:{_:Hash}:x:no}', 'x:x'],
            'http from a shortcut'         => ['{ifeq:a:a:{_:Http}://x:no}', 'http://x'],
            'slashes from a shortcut'      => ['{ifeq:a:a:http:{_:Slashes}:no}', 'http://x'],
            'tp before slashes'            => ['{ifeq:a:b:{_:Http}:{_:Slashes}}', ''],
            'quoted tp before slashes'     => ['{ifeq:a:b:{-http}:{-//x}}', ''],
            'dequote'                      => ["{ifeq:a:a:{dequote:x\x1E|~@ay}:no}", 'x'],
            'shortcut with ('              => ['{ifeq:a:b:{_(Colon}:else}', ''],
            '# from a shortcut with ('     => ['{ifeq:a:b:{_(Hash}:{strlen:x}}', ''],
            'separator joined in a branch' => ['{ifeq:a:b:##S{-}x:else}', ''],
            'mark in the data'             => ["{ifeq:{-\x1E|~@?2}:a:{strlen:x}:_#1}", "\x1E|~@?2"],
        ];
    }

    public function test_unalias_urlValueInBranch_isQuotedAsBefore(): void
    {
        $GLOBALS['als'] = ['MYALS' => 'a:b'];
        self::assertSame('a:b', AA::Un('{ifeq:a:a:{MYALS}:no}'));
        self::assertSame('1', AA::Un('{ifeq:{MYALS}:a#:b:{strlen:x}:no}'));
        unset($GLOBALS['als']);
    }

    public function test_unalias_dequoteFalse_keepsTheQuotes(): void
    {
        self::assertSame("x\x1E|~@ay", AA::Un('{ifeq:a:b:{strlen:q}:x{-:}y}', '', null, false));
    }

    /** the branch not chosen does not run - what its code did before ({define} here) no longer happens */
    #[DataProvider('notChosen')]
    public function test_unalias_branchNotChosen_doesNotRun(string $input, string $output, string $before): void
    {
        self::assertSame($output, AA::Un($input));
        self::assertSame($before, self::eager($input), 'what the expander gave when it expanded the branches first');
    }

    public static function notChosen(): array
    {
        return [
            'branch'                       => ['{ifeq:a:b:{define:nc1:ran}}[{var:nc1}]', '[]', '[ran]'],
            'else'                         => ['{ifeq:a:a:yes:{define:nc2:ran}}[{var:nc2}]', 'yes[]', 'yes[ran]'],
            'the chosen of many'           => ['{ifeq:c:a:{define:nc3:a}:b:{define:nc3:b}:c:{define:nc3:c}:{define:nc3:else}}[{var:nc3}]', '[c]', '[else]'],
            'nested'                       => ['{ifeq:a:b:{ifeq:1:1:{define:nc5:x}}}[{var:nc5}]', '[]', '[x]'],
            'in a shortcut'                => ['{_:Cond:2}[{var:inshortcut}]', 'no[]', 'no[yes]'],
            'if'                           => ['{if:1:>:2:{define:nc6:x}}[{var:nc6}]', '[]', '[x]'],
            'ifin'                         => ['{ifin:abc:z:{define:nc7:x}}[{var:nc7}]', '[]', '[x]'],
            'ifset'                        => ['{ifset::{define:nc8:x}}[{var:nc8}]', '[]', '[x]'],
            'iftext'                       => ['{iftext:<!-- -->:{define:nc9:x}}[{var:nc9}]', '[]', '[x]'],
            'if1'                          => ['{if1:0:{define:nc10:x}}[{var:nc10}]', '[]', '[x]'],
            'ifbit'                        => ['{ifbit:1:2:{define:nc11:x}}[{var:nc11}]', '[]', '[x]'],
            'ifintersect'                  => ['{ifintersect:a:b:{define:nc12:x}}[{var:nc12}]', '[]', '[x]'],
            'iftype'                       => ['{iftype:x:number:{define:nc13:x}}[{var:nc13}]', '[]', '[x]'],
            'ifeqfield'                    => ['{ifeqfield::headline........:x:{define:nc14:x}}[{var:nc14}]', '[]', '[x]'],
        ];
    }

    /** the code in the chosen branch runs, in the same order as before */
    public function test_unalias_chosenBranch_runsInOrder(): void
    {
        self::assertSame('[ran]', AA::Un('{ifeq:a:a:{define:ch1:ran}}[{var:ch1}]'));
        self::assertSame('22', AA::Un('{define:ch2:1}{ifeq:{var:ch2}:1:{define:ch2:2}{var:ch2}:no}{var:ch2}'));
    }

    /** where a {_:...} splits the condition differently, the rest expands as before - the branch not chosen too */
    public function test_unalias_shortcutSplitsTheCondition_restRunsAsBefore(): void
    {
        self::assertSame('[ran]', AA::Un('{ifeq:a:b:{_:Colon}:{define:sp1:ran}}[{var:sp1}]'));
    }

    /** The one difference in the text: a bracket in the branch not chosen which expanded to nothing became {}, which
     *  the expander never expands, so the whole condition stayed on the page as text. Now the branch is left out. */
    public function test_unalias_emptiedBracketsInBranchNotChosen_areLeftOut(): void
    {
        $input = '{ifeq:a:b:{{ifeq:1:2:x}}:no}';
        self::assertSame('no', AA::Un($input));
        self::assertSame('{ifeq:a:b:{}:no}', self::eager($input));
    }

    /** a condition not closed shows on the page as before - the conditions in it do not take quadratic time */
    public function test_unalias_manyConditionsNotClosed_asBefore(): void
    {
        $text = str_repeat('{ifeq:a:a:', 100000) . '{strlen:x}';
        self::assertSame(self::eager($text), AA::Un($text));
    }

    /** conditions nested deeper than the expander leaves out branches in are expanded as before */
    public function test_unalias_deeplyNested_sameAsBefore(): void
    {
        $text = str_repeat('{ifeq:a:b:{strlen:x}:', 100) . 'z' . str_repeat('}', 100);
        self::assertSame('z', AA::Un($text));
        self::assertSame('z', self::eager($text));
    }

    /** random brace soup with conditions: the same text as the expander expanding the branches first */
    public function test_unalias_randomText_sameAsBefore(): void
    {
        $parts = ['{ifeq:a:a:', '{ifeq:a:b:', '{ifeq:{strlen:x}:1:', '{ifset:', '{ifset:1:', '{if:2:>:1:', '{ifin:abc:b:', '{if1:1:',
            '{', '}', '}', '}', '}', ':', ':', '#', '#:', '{}', 'a', ' ', '{strlen:', '{-x', '{-', 'tp://', 'ht', 'tp', '//',
            '{(', ')}', '{#', '{_:One}', '{_:Colon}', '{_:Hash}', '{_:Http}', '{_:Slashes}', "\x1E|~@a", '{dequote:'];
        mt_srand(20261021);
        for ($i = 0; $i < 5000; ++$i) {
            $text = '';
            for ($n = mt_rand(1, 24); $n > 0; --$n) {
                $text .= $parts[mt_rand(0, count($parts) - 1)];
            }
            // an inline alias left unexpanded shows its random name
            self::assertSame(preg_replace('/R\d{9}/', 'R', self::eager($text)), preg_replace('/R\d{9}/', 'R', AA::Un($text)), json_encode($text));
        }
    }

    /** the expansion loop alone: everything inside a condition is expanded before the condition */
    private static function eager(string $text): string
    {
        $callback = [new AA_Unalias_Callback(null, null), 'expand_bracketed'];
        $count    = 0;
        $text     = \AA_Stringexpander::removeComments(\AA_Stringexpander::ApplyInlineAlias($text), $count);
        do {
            $text   = preg_replace_callback('/[{]([^{}]+)[}]/s', $callback, $text, 1, $n);
            $count += $n;
        } while ($n);
        return $count ? DeQuoteColons($text) : $text;
    }
}
