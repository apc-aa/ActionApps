<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\IdMap;
use AA\Central\Package\ReferenceRewriter;
use AA\Central\Package\RewriteFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceRewriterTest extends TestCase
{
    /** slice of the app */
    private const SLICE_OLD = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const SLICE_NEW = 'abcdef0123456789abcdef0123456789';
    /** site of the app - its new id is the old id of the slice (proves one pass) */
    private const SITE_OLD = '5ad0d95a490b383ac7d23650e7aa49ee';
    /** constant, its parent constant (ancestors) */
    private const CONST_OLD  = '0497ac46076bf257d15f3e030170da92';
    private const CONST_NEW  = 'fedcba9876543210fedcba9876543210';
    private const PARENT_OLD = '11111111111111111111111111111111';
    private const PARENT_NEW = '22222222222222222222222222222222';
    /** a slice outside the app */
    private const FOREIGN = 'deadbeefdeadbeefdeadbeefdeadbeef';
    /** an old id of digits only - its upper case is itself */
    private const DIGITS_OLD = '12345678901234567890123456789012';
    private const DIGITS_NEW = 'abcdefabcdefabcdefabcdefabcdef12';

    private static function map(): IdMap
    {
        return new IdMap(
            modules: [self::SLICE_OLD => self::SLICE_NEW, self::SITE_OLD => self::SLICE_OLD],
            longIds: [self::CONST_OLD => self::CONST_NEW, self::PARENT_OLD => self::PARENT_NEW, self::DIGITS_OLD => self::DIGITS_NEW],
            // 12 -> 13 -> 14: a chained rewrite would turn 12 into 14
            views: [12 => 13, 13 => 14, 3072 => 5001, 3061 => 5002, 260 => 400, 5 => 6],
            // e-mail 5 is not view 5
            emails: [874 => 900, 5 => 7],
            groups: ['biom__categories' => 'biom__categori_1', 'ekolist-category' => 'ekolist-catego_1', 'cat_a' => 'cat_b', 'cat_b' => 'cat_c', 'grp' => 'grp_1'],
            collections: ['AbC12' => 'XyZ98'],
        );
    }

    private function rewriter(): ReferenceRewriter
    {
        return new ReferenceRewriter(self::map());
    }

    /** [template text, expected after rewriteNumeric()]
     *  @return array<string, array{string, string}> */
    public static function numericForms(): array
    {
        return [
            // vid=N - AA_View::parseViewParameters() view.class.php:422
            'view.php url'                => ['<!--#include virtual="/aaa/view.php?vid=12"-->', '<!--#include virtual="/aaa/view.php?vid=13"-->'],
            'view.php3 in braces'         => ['{view.php3?vid=3072}', '{view.php3?vid=5001}'],
            'vid= with space'             => ['{view.php?vid= 12}', '{view.php?vid= 13}'],
            'chain 12->13->14 one pass'   => ['{view.php?vid=12}{view.php?vid=13}', '{view.php?vid=13}{view.php?vid=14}'],
            'vid=123 is not vid=12'       => ['{view.php?vid=123}', '{view.php?vid=123}'],
            'vid=112 is not vid=12'       => ['vid=112', 'vid=112'],
            'vid=12abc is a named view'   => ['{view.php?vid=12abc}', '{view.php?vid=12abc}'],
            'named view'                  => ['{view.php?vid=nase_aktivity}', '{view.php?vid=nase_aktivity}'],
            'slice_vid is another param'  => ['slice_vid=12&xvid=12', 'slice_vid=12&xvid=12'],
            'number outside any form'     => ['<td width="12">12 items, 12px</td>', '<td width="12">12 items, 12px</td>'],
            'view outside the app'        => ['{view.php?vid=999&cmd[999]=x-999-5}', '{view.php?vid=999&cmd[999]=x-999-5}'],
            'lazy'                        => ['{lazy:/aaa/view.php?vid=12::nolazy}', '{lazy:/aaa/view.php?vid=13::nolazy}'],
            'f_v alias function'          => ['f_v:vid=12&cmd[12]=x-12-_#ITEM_ID_', 'f_v:vid=13&cmd[13]=x-13-_#ITEM_ID_'],
            'ajax url with &amp;'         => ['AA_Ajax(\'div\', \'/aaa/view.php?vid=12&amp;cmd[12]=o-12-{id..............}\')', 'AA_Ajax(\'div\', \'/aaa/view.php?vid=13&amp;cmd[13]=o-13-{id..............}\')'],
            // cmd[N], cmd[N][], x-/o-/i-/v- - view.class.php:426-431, 464-470, view.php:210-213
            'full view.php expression'    => ['{view.php?vid=12&cmd[12]=x-12-{xid}&set[12]=listlen-5}', '{view.php?vid=13&cmd[13]=x-13-{xid}&set[13]=listlen-5}'],
            'cmd[N][] list'               => ['{view.php3?vid=3072&cmd[3072][]=c-1-On&cmd[3072][]=x-3072-_#ITEM_ID_}', '{view.php3?vid=5001&cmd[5001][]=c-1-On&cmd[5001][]=x-5001-_#ITEM_ID_}'],
            'cmd[] filled from vid'       => ['{view.php?vid=12&cmd[]=x-12-5}', '{view.php?vid=13&cmd[]=x-13-5}'],
            'c- is a condition number'    => ['{view.php?vid=12&cmd[12]=c-12-On}', '{view.php?vid=13&cmd[13]=c-12-On}'],
            'unrelated cmd[] value'       => ['{view.php?vid=12&cmd[]=c-1-12-2-13}', '{view.php?vid=13&cmd[]=c-1-12-2-13}'],
            'd- condition'                => ['cmd[12]=d-category........-=-12', 'cmd[13]=d-category........-=-12'],
            'v- switches the view'        => ['cmd[99]=v-12', 'cmd[99]=v-13'],
            'i- with spaces'              => ['cmd[12]= i- 12-a', 'cmd[13]= i- 13-a'],
            // include/view.php:211 trims the command letter
            'x -N in cmd[N][]'            => ['cmd[12][]=x -12-1', 'cmd[13][]=x -12-1' === '' ? '' : 'cmd[13][]=x -13-1'],
            'spaces around the letter'    => ['cmd[12]= v - 12', 'cmd[13]= v - 13'],
            '+v is encoded, not rewritten' => ['cmd[12]=+v-12', 'cmd[13]=+v-12'],
            'x-12abc not numeric'         => ['cmd[12]=x-12abc', 'cmd[13]=x-12abc'],
            'xcmd is another param'       => ['xcmd[12]=x-12', 'xcmd[12]=x-12'],
            // banner-POS-N - AA_View::parseSettings() ALLOW_MULTIPLE, parseBannerParam() view.class.php:352-396
            'set banner list'             => ['set[5]=banner-2-260,banner-10-261', 'set[6]=banner-2-400,banner-10-261'],
            'set[N][] banner norandom'    => ['set[5][]=banner-2-260-norandom', 'set[6][]=banner-2-400-norandom'],
            'banner after other setting'  => ['set[5]=listlen-5,banner-2-260', 'set[6]=listlen-5,banner-2-400'],
            'set[] banner'                => ['{view.php?vid=5&set[]=banner-1-12}', '{view.php?vid=6&set[]=banner-1-13}'],
            'banner in {view:} settings'  => ['{view:12::listlen-5,banner-2-260}', '{view:13::listlen-5,banner-2-400}'],
            'banner as first {view:} set' => ['{view:12::banner-2-12}', '{view:13::banner-2-13}'],
            'slice.php banner param'      => ['/aaa/slice.php?slice_id=x&banner=3-260', '/aaa/slice.php?slice_id=x&banner=3-400'],
            'banner css class untouched'  => ['<div class="banner-2-260">', '<div class="banner-2-260">'],
            // {view:N...} - AA_Stringexpand_View stringexpand.php:3190
            '{view:N}'                    => ['{view:12}', '{view:13}'],
            '{view:N::settings}'          => ['{view:12::listlen-5}', '{view:13::listlen-5}'],
            '{view:N:ids}'                => ['{view:3061:{ids:' . self::SLICE_OLD . ':d-category........-==-Env}}', '{view:5002:{ids:' . self::SLICE_OLD . ':d-category........-==-Env}}'],
            '{view:123} not 12'           => ['{view:123}', '{view:123}'],
            '{view:12{..}} computed'      => ['{view:12{var:x}}', '{view:12{var:x}}'],
            // {livesearch:N...} - AA_Stringexpand_Livesearch stringexpand.php:1246
            '{livesearch:N:placeholder}'  => ['{livesearch:12:Hledat}', '{livesearch:13:Hledat}'],
            '{livesearch:N&cmd[N]}'       => ['{livesearch:12&cmd[12]=c-1-%22AA_LS_QUERY%22-2-pubs:search}', '{livesearch:13&cmd[13]=c-1-%22AA_LS_QUERY%22-2-pubs:search}'],
            // {mailtemplate:N} - AA_Stringexpand_Mailtemplate stringexpand.php:10218 - the e-mail map
            '{mailtemplate:N:to}'         => ['{mailtemplate:874:["info@ecn.cz"]}', '{mailtemplate:900:["info@ecn.cz"]}'],
            'e-mail 5 is not view 5'      => ['{mailtemplate:5:a@b.cz}{view:5}', '{mailtemplate:7:a@b.cz}{view:6}'],
            'e-mail outside the app'      => ['{mailtemplate:12:a@b.cz}', '{mailtemplate:12:a@b.cz}'],
            // computed forms stay - the lint lists them
            'computed vid'                => ['{view.php3?vid={ifeq:{xseo1}:nase-aktivity:3072:3061}&cmd[{var:v}]=x-1}', '{view.php3?vid={ifeq:{xseo1}:nase-aktivity:3072:3061}&cmd[{var:v}]=x-1}'],
            'alias vid'                   => ['f_v:vid=_#VIEW_ID_', 'f_v:vid=_#VIEW_ID_'],
            'computed mailtemplate'       => ['{mailtemplate:{if1:{qss:send}:874}:a@b.cz}', '{mailtemplate:{if1:{qss:send}:874}:a@b.cz}'],
            'long ids stay'               => ['{ids:' . self::SLICE_OLD . '}', '{ids:' . self::SLICE_OLD . '}'],
            // AA lower-cases command names - constructClassName(), include/statestore.php:153
            '{VIEW:N}'                    => ['{VIEW:12}{View:3072::banner-1-260}', '{VIEW:13}{View:5001::banner-1-400}'],
            '{LiveSearch:N}'              => ['{LiveSearch:12}', '{LiveSearch:13}'],
            '{MailTemplate:N}'            => ['{MailTemplate:874:a@b.cz}', '{MailTemplate:900:a@b.cz}'],
            'VID= is no view parameter'   => ['VID=12', 'VID=12'],
            // zero padding - AA reads ctype_digit() + (int) (view.class.php:638, stringexpand.php:10227); written unpadded
            'zero-padded {view:}'         => ['{view:000000000003072}', '{view:5001}'],
            'zero-padded vid'             => ['{view.php?vid=0012&cmd[012]=x-0012-5}', '{view.php?vid=13&cmd[13]=x-13-5}'],
            'zero-padded mailtemplate'    => ['{mailtemplate:0874:a@b.cz}', '{mailtemplate:900:a@b.cz}'],
            'zero-padded banner'          => ['set[05]=banner-002-0260', 'set[6]=banner-002-400'],
            'padded 11 significant'       => ['vid=012345678901', 'vid=012345678901'],
            'vid=0'                       => ['vid=0&cmd[00]=x-0', 'vid=0&cmd[00]=x-0'],
            // numbers are at most 10 digits (view.id is int) - a longer run is no reference
            '11 digits'                   => ['vid=12345678901', 'vid=12345678901'],
            'huge digit run'              => ['vid=' . str_repeat('1', 600000) . 'x {view:12}', 'vid=' . str_repeat('1', 600000) . 'x {view:13}'],
            'huge cmd key'                => ['cmd[' . str_repeat('1', 600000) . ']=x-12', 'cmd[' . str_repeat('1', 600000) . ']=x-12'],
            'huge space run'              => ['{view:12' . str_repeat(' ', 600000) . 'x}{view:12}', '{view:12' . str_repeat(' ', 600000) . 'x}{view:13}'],
        ];
    }

    #[DataProvider('numericForms')]
    public function test_rewriteNumeric_known_forms(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->rewriter()->rewriteNumeric($text));
    }

    /** [template text, expected after rewriteLongIds()]
     *  @return array<string, array{string, string}> */
    public static function longIdForms(): array
    {
        return [
            // long ids - § 8.1
            'ids of a slice'              => ['{ids:' . self::SLICE_OLD . ':d-category........-==-Env}', '{ids:' . self::SLICE_NEW . ':d-category........-==-Env}'],
            'upper case'                  => ['{ids:' . strtoupper(self::SLICE_OLD) . '}', '{ids:' . strtoupper(self::SLICE_NEW) . '}'],
            'mixed case -> lower case'    => ['{ids:0F1e2d3c4b5a69788796a5b4c3d2e1f0}', '{ids:' . self::SLICE_NEW . '}'],
            'digits only old id'          => ['{ids:' . self::DIGITS_OLD . '}', '{ids:' . self::DIGITS_NEW . '}'],
            'relation widget #sLiCe-'     => ['sel:#sLiCe-' . self::SLICE_OLD . ':', 'sel:#sLiCe-' . self::SLICE_NEW . ':'],
            'new id = other old id'       => [self::SITE_OLD . ' ' . self::SLICE_OLD, self::SLICE_OLD . ' ' . self::SLICE_NEW],
            'ancestors concatenated'      => [self::PARENT_OLD . self::CONST_OLD, self::PARENT_NEW . self::CONST_NEW],
            'ancestors, one upper case'   => [self::PARENT_OLD . strtoupper(self::CONST_OLD), self::PARENT_NEW . strtoupper(self::CONST_NEW)],
            'hex run of 33 is no id'      => [self::SLICE_OLD . 'a', self::SLICE_OLD . 'a'],
            'hex run of 31 + id'          => ['a' . self::SLICE_OLD, 'a' . self::SLICE_OLD],
            'id outside the app'          => ['{ids:' . self::FOREIGN . '}', '{ids:' . self::FOREIGN . '}'],
            'numbers stay'                => ['{view.php?vid=12}', '{view.php?vid=12}'],
            'groups stay'                 => ['{constants:cat_a}', '{constants:cat_a}'],
            // alerts field ids - getAlertsField() modules/alerts/util.php:115
            'alerts field ids'            => ['{alerts1....AbC12} _#ALERTS2 f_h:alerts2....AbC12', '{alerts1....XyZ98} _#ALERTS2 f_h:alerts2....XyZ98'],
            'other collection'            => ['{alerts1....QqQ12}', '{alerts1....QqQ12}'],
        ];
    }

    #[DataProvider('longIdForms')]
    public function test_rewriteLongIds_long_ids_and_alerts_fields(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->rewriter()->rewriteLongIds($text));
    }

    /** [template text, expected after rewriteGroups()]
     *  @return array<string, array{string, string}> */
    public static function groupForms(): array
    {
        return [
            // constant groups - § 8.3, stringexpand.php:4411, 4451, 4489
            '{constant:g:...}'            => ['{constant:biom__categories:{@category........:|}:name:|:, }', '{constant:biom__categori_1:{@category........:|}:name:|:, }'],
            '{constants:g}'               => ['{constants:ekolist-category}', '{constants:ekolist-catego_1}'],
            '{options:g:selected}'        => ['<select name="t">{options:ekolist-category:{qs:t}}</select>', '<select name="t">{options:ekolist-catego_1:{qs:t}}</select>'],
            '{Constant:g} any case'       => ['{Constant:cat_a:1}{OPTIONS:cat_a}', '{Constant:cat_b:1}{OPTIONS:cat_b}'],
            'group with spaces kept'      => ['{constants: ekolist-category :name}', '{constants: ekolist-catego_1 :name}'],
            'groups chain a->b->c'        => ['{constants:cat_a}{constants:cat_b}', '{constants:cat_b}{constants:cat_c}'],
            'core group not mapped'       => ['{constants:lt_languages:_#VALUE##_:json}', '{constants:lt_languages:_#VALUE##_:json}'],
            'json list is no group'       => ['{options:[1,2,5,7]:7}', '{options:[1,2,5,7]:7}'],
            'unterminated'                => ['{constants:cat_a', '{constants:cat_a'],
            'group name in plain text'    => ['ekolist-category and cat_a', 'ekolist-category and cat_a'],
            'group in another command'    => ['{field:biom__categories}', '{field:biom__categories}'],
            'long ids stay'               => ['{ids:' . self::SLICE_OLD . '}', '{ids:' . self::SLICE_OLD . '}'],
            'huge space run'              => ['{constant:' . str_repeat(' ', 600000) . '{constants:cat_a}', '{constant:' . str_repeat(' ', 600000) . '{constants:cat_b}'],
        ];
    }

    #[DataProvider('groupForms')]
    public function test_rewriteGroups_group_parameter_of_constant_commands(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->rewriter()->rewriteGroups($text));
    }

    public function test_rewriteLongIds_keeps_serialized_text_valid(): void
    {
        $value     = ['start_id' => '1', 'v' => ['slice' => self::SLICE_OLD, 'g' => '{constants:cat_a}'], 'c' => ['x' => strtoupper(self::CONST_OLD)]];
        $structure = serialize($value);

        $rewritten = $this->rewriter()->rewriteLongIds($structure);

        $this->assertSame(strlen($structure), strlen($rewritten));
        $this->assertSame(
            ['start_id' => '1', 'v' => ['slice' => self::SLICE_NEW, 'g' => '{constants:cat_a}'], 'c' => ['x' => strtoupper(self::CONST_NEW)]],
            unserialize($rewritten, ['allowed_classes' => false]),
        );
    }

    /** Why rewriteGroups()/rewriteNumeric() must not see serialize()d text: the length changes. */
    public function test_group_pass_breaks_serialized_text(): void
    {
        $structure = serialize(['g' => '{constants:grp}']);

        $this->assertFalse(@unserialize($this->rewriter()->rewriteGroups($structure), ['allowed_classes' => false]));
    }

    public function test_rewrite_does_all_passes_in_one_call(): void
    {
        $text     = '{view:12:{ids:' . self::SLICE_OLD . '}}{constants:cat_a}{mailtemplate:874:x@y.cz}';
        $expected = '{view:13:{ids:' . self::SLICE_NEW . '}}{constants:cat_b}{mailtemplate:900:x@y.cz}';

        $this->assertSame($expected, $this->rewriter()->rewrite($text));
    }

    public function test_numeric_rewrite_without_numeric_maps_changes_nothing(): void
    {
        $plan = new IdMap(modules: [self::SLICE_OLD => self::SLICE_NEW]);
        $text = '{view.php?vid=12&cmd[12]=x-12-{ids:' . self::SLICE_OLD . '}}';

        $this->assertSame($text, (new ReferenceRewriter($plan))->rewriteNumeric($text));
    }

    /** [input_show_func, position of the const parameter, expected]
     *  @return array<string, array{string, int, string}> */
    public static function inputShowFuncs(): array
    {
        return [
            'select'                      => ['sel:biom__categories:1', 0, 'sel:biom__categori_1:1'],
            'multiple checkboxes'         => ['mch:ekolist-category:3:1', 0, 'mch:ekolist-catego_1:3:1'],
            'const is the 3rd param'      => ['tpr:5:40:ekolist-category', 2, 'tpr:5:40:ekolist-catego_1'],
            'same name elsewhere kept'    => ['sel:cat_a:cat_a', 0, 'sel:cat_b:cat_a'],
            'spaces kept'                 => ['sel: biom__categories :x', 0, 'sel: biom__categori_1 :x'],
            'escaped colon before'        => ['tpr:a#:b:40:cat_a:', 2, 'tpr:a#:b:40:cat_b:'],
            'escaped colon after'         => ['sel:cat_a:{ifeq#:a#:b}:x', 0, 'sel:cat_b:{ifeq#:a#:b}:x'],
            'relation to a slice'         => ['sel:#sLiCe-' . self::SLICE_OLD . ':', 0, 'sel:#sLiCe-' . self::SLICE_OLD . ':'],
            'group not in the map'        => ['sel:lt_languages', 0, 'sel:lt_languages'],
            'empty const'                 => ['sel::1', 0, 'sel::1'],
            'no such parameter'           => ['tpr:5', 2, 'tpr:5'],
            'no parameters'               => ['fld', 0, 'fld'],
            'chain a->b->c'               => ['sel:cat_a', 0, 'sel:cat_b'],
        ];
    }

    #[DataProvider('inputShowFuncs')]
    public function test_rewriteInputShowFunc_replaces_the_const_parameter_in_place(string $isf, int $position, string $expected): void
    {
        $this->assertSame($expected, $this->rewriter()->rewriteInputShowFunc($isf, $position));
    }

    /** @return array<string, array{string}> */
    public static function methods(): array
    {
        return ['rewriteLongIds' => ['rewriteLongIds'], 'rewriteGroups' => ['rewriteGroups'], 'rewriteNumeric' => ['rewriteNumeric'], 'rewriteInputShowFunc' => ['rewriteInputShowFunc']];
    }

    /** A failed regex (backtrack limit, …) must stop the import - never pass the text half-rewritten. */
    #[DataProvider('methods')]
    public function test_pcre_failure_throws(string $method): void
    {
        $rewriter = $this->rewriter();
        $limit    = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $this->expectException(RewriteFailed::class);
            $rewriter->$method('sel:cat_a {constants:cat_a} {view:12} ' . self::SLICE_OLD);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }
}
