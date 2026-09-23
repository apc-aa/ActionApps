<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\IdMap;
use AA\Central\Package\LintFinding;
use AA\Central\Package\ReferenceLint;
use AA\Central\Package\RewriteFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceLintTest extends TestCase
{
    /** slice of the app / slice outside the app */
    private const S = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const F = 'deadbeefdeadbeefdeadbeefdeadbeef';

    /** the import plan with the numbers already known */
    private static function map(): IdMap
    {
        return new IdMap(
            modules: [self::S => 'abcdef0123456789abcdef0123456789'],
            views: [12 => 13, 3072 => 5001, 3061 => 5002, 5 => 6],
            emails: [874 => 900, 5 => 7],
            groups: ['ekolist-category' => 'ekolist-catego_1', 'cat_a' => 'cat_b'],
        );
    }

    /** [source text, expected findings as "level code ref"]
     *  @return array<string, array{string, list<string>}> */
    public static function texts(): array
    {
        return [
            'clean app template'          => ['{view.php?vid=12&cmd[12]=x-12-{ids:' . self::S . '}&set[12]=listlen-5}{mailtemplate:874:a@b.cz}{constants:cat_a}', []],
            'numbers in known forms only' => ['vid=12 cmd[3072]=c-12-On 12px 112 1.12 listlen-5', []],
            // numeric reference outside the app - § 8.2, § 5.2
            'view outside the app'        => ['{view.php?vid=999&cmd[999]=x-998-1}', ['W view-outside 999', 'W view-outside 999', 'W view-outside 998']],
            '{view:} outside the app'     => ['{view:999::listlen-5}', ['W view-outside 999']],
            'banner outside the app'      => ['set[12]=banner-2-777', ['W view-outside 777']],
            'e-mail outside the app'      => ['{mailtemplate:12:a@b.cz}', ['W email-outside 12']],
            // computed - § 8.2 "Nepřepsatelné"
            'computed vid'                => ['{view.php3?vid={ifeq:{xseo1}:nase-aktivity:3072:3061}', ['W computed vid={', 'I bare-number 3072', 'I bare-number 3061']],
            'computed vid and cmd'        => ['{view.php3?vid={var:pohled}&cmd[{var:pohled}]=x-1}', ['W computed vid={', 'W computed cmd[{']],
            'alias vid'                   => ['f_v:vid=_#VIEW_ID_', ['W computed vid=_#']],
            'computed {view:}'            => ['{view:{var:v}}', ['W computed {view:{']],
            'computed x- command'         => ['cmd[12]=x-{var:v}-1', ['W computed cmd[12]=x-{']],
            'computed mailtemplate'       => ['{mailtemplate:{if1:{qss:send}:874}:a@b.cz}', ['W computed {mailtemplate:{', 'I bare-number 874']],
            // bare numbers equal to an app view / e-mail id
            'bare view id in an attr'     => ['<a data-view="3072">', ['I bare-number 3072']],
            'bare number not an app id'   => ['<td width="3000">', []],
            // modules outside the app - § 5.2, D11
            'ids of a foreign slice'      => ['{ids:' . self::F . ':d-x-=-1}', ['W module-outside ' . self::F]],
            'modulefield foreign'         => ['{modulefield:' . strtoupper(self::F) . ':name}', ['W module-outside ' . strtoupper(self::F)]],
            '#sLiCe- foreign'             => ['sel:#sLiCe-' . self::F, ['W module-outside ' . self::F]],
            'app slice upper case'        => ['{ids:' . strtoupper(self::S) . '}', []],
            // AA_Form - § 5.4, D14
            'form'                        => ['{form:' . self::F . '}', ['W form {form:']],
            // constant groups - § 5.3, § 8.3
            'group outside the package'   => ['{constants:unknown_grp}', ['I group-outside unknown_grp']],
            'core group'                  => ['{options:lt_languages:{qs:l}}{constant:AA_Core_Bins....:1}', []],
            'json list'                   => ['{options:[1,2]:1}', []],
            'computed group'              => ['{constants:{var:g}}', []],
            'Constant any case'           => ['{CONSTANTS:unknown_grp}', ['I group-outside unknown_grp']],
            'group with spaces'           => ['{constants: unknown_grp :x}', ['I group-outside unknown_grp']],
            // command names in any case - constructClassName(), include/statestore.php:153
            '{VIEW:} outside the app'     => ['{VIEW:999}', ['W view-outside 999']],
            '{Form:}'                     => ['{Form:' . self::F . '}', ['W form {Form:']],
            // forms AA reads but the rewriter does not rewrite
            'vid= after a word char'      => ['{view.php?covid=3072}', ['W unknown-form covid', 'I bare-number 3072']],
            'f_v:x_vid='                  => ['f_v:x_vid=5&cmd[5]=x-5', ['W unknown-form x_vid', 'I bare-number 5']],
            '...vid= in a plain url'      => ['<a href="https://x.cz/?covid=19">', ['I unknown-form vid=']],
            // zero padding and too long numbers
            'zero-padded app view'        => ['{view:000000000003072}{mailtemplate:0874}', []],
            'zero-padded foreign view'    => ['{view:0000999}', ['W view-outside 0000999']],
            '11 significant digits'       => ['{view:012345678901} vid=12345678901', ['W unknown-form {view:012345678901', 'W unknown-form vid=12345678901']],
            '11 digits + letter is named' => ['vid=12345678901x', []],
            '11 digits in cmd value'      => ['cmd[12]=x-12345678901', ['W unknown-form cmd[12]=x-12345678901']],
            // encoded / odd values AA decodes (parse_str, NormalizeArrayIndex util.php:322)
            'encoded dash in cmd value'   => ['cmd[12]=v%2D12', ['W unknown-form cmd[12]=v%']],
            'spaced letter is rewritten'  => ['cmd[12]=v -999', ['W view-outside 999']],
            '+v'                          => ['cmd[12]=+v-3072', ['W unknown-form cmd[12]=+']],
            'encoded space before -'      => ['cmd[12]=v%20-3072', ['W unknown-form cmd[12]=v%']],
            'encoded letter'              => ['cmd[12][]=%76-3072', ['W unknown-form cmd[12][]=%']],
            'encoded digit of the view'   => ['cmd[12]=v-%33072', ['W unknown-form cmd[12]=v-%']],
            'encoded digit in set banner' => ['set[12]=banner-1-%33072', ['W unknown-form set[12]=banner-1-%']],
            'encoded condition is fine'   => ['{livesearch:12&cmd[12]=c-1-%22AA_LS_QUERY%22-2-pubs}', []],
            'v-+N'                        => ['cmd[12]=v-+12', ['W unknown-form cmd[12]=v-+', 'I bare-number 12']],
            'quoted index'                => ["{view.php?vid=12&cmd['12']=x-12}", ["W unknown-form cmd['", 'I bare-number 12']],
            'encoded vid key'             => ['view.php?v%69d=12', ['W unknown-form v%69d', 'I bare-number 12']],
            'encoded cmd key'             => ['/aaa/view.php?vid=12&amp;c%6Dd[12]=x-12', ['W unknown-form c%6Dd[12]', 'I bare-number 12']],
            // ids glued to other hex chars are not rewritten
            'glued before'                => ['#d' . self::S, ['I glued-id ' . self::S]],
            'glued x1'                    => ['x1' . self::S . ' {ids:' . self::S . 'f}', ['I glued-id ' . self::S, 'I glued-id ' . self::S]],
            'misaligned 64 run'           => [str_repeat('a', 16) . self::S . str_repeat('b', 16), ['I glued-id ' . self::S]],
            'aligned run is rewritten'    => [self::S . self::F, []],
            'glued foreign id'            => ['#d' . self::F, []],
            'huge hex run'                => [str_repeat('a', 1000001) . ' ' . str_repeat('b', 900) . self::S . 'c', ['I glued-id ' . self::S]],
            'url-encoded cmd[]'           => ['{view.php?vid=12&cmd%5B3072%5D=x-3072-1}', ['W unknown-form cmd%5B']],
            'url-encoded set[]'           => ['view.php?vid=12&set%5b12%5d=listlen-5', ['W unknown-form set%5b']],
            'named view is fine'          => ['{view.php?covid=abc}', []],
            // a digit run longer than 10 is no id; must not exhaust PCRE
            'huge digit run'              => [str_repeat('1', 1100000) . 'x vid=' . str_repeat('2', 600000) . 'x 3072', ['I bare-number 3072']],
            'group name in other place'   => ['{field:ekolist-category} ekolist-category-2 cat_a', ['I group-other ekolist-category', 'I group-other cat_a']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('texts')]
    public function test_lint_findings(string $text, array $expected): void
    {
        $findings = (new ReferenceLint(self::map()))->lint($text);

        $this->assertSame($expected, array_map(static fn(LintFinding $f) => "$f->level $f->code $f->ref", $findings));
    }

    public function test_ref_is_capped(): void
    {
        $findings = (new ReferenceLint(self::map()))->lint('{constants:' . str_repeat('x', 16) . '}' . '{view.php?vid={' . str_repeat('y', 5000) . '}}');

        $this->assertSame(16, strlen($findings[0]->ref));
        $this->assertSame('vid={', $findings[1]->ref);
        $long = (new ReferenceLint(self::map()))->lint('{constants:' . str_repeat('z', 500) . '}');
        $this->assertSame(ReferenceLint::MAX_REF, strlen($long[0]->ref));
    }

    public function test_findings_are_capped_per_code(): void
    {
        $findings = (new ReferenceLint(self::map()))->lint(str_repeat('{view:999}', ReferenceLint::MAX_PER_CODE + 500) . ' #sLiCe-' . self::F);

        $this->assertCount(ReferenceLint::MAX_PER_CODE + 2, $findings);
        $views = array_values(array_filter($findings, static fn(LintFinding $f) => $f->code === ReferenceLint::VIEW_OUTSIDE));
        $this->assertCount(ReferenceLint::MAX_PER_CODE + 1, $views);
        $this->assertSame('+500 more', $views[ReferenceLint::MAX_PER_CODE]->ref);
        $this->assertSame(LintFinding::WARNING, $views[ReferenceLint::MAX_PER_CODE]->level);
        $this->assertSame(ReferenceLint::MAX_PER_CODE * 10 + 6, $views[ReferenceLint::MAX_PER_CODE]->offset, 'offset of the first one left out');
    }

    public function test_many_groups(): void
    {
        $groups = [];
        for ($i = 0; $i < 5000; $i++) {
            $groups["grp_$i"] = "new_$i";
        }
        $findings = (new ReferenceLint(new IdMap(groups: $groups)))->lint('{constants:grp_7} grp_4999 grp_5000');

        $this->assertSame(['group-other grp_4999'], array_map(static fn(LintFinding $f) => "$f->code $f->ref", $findings));
    }

    public function test_pcre_failure_throws(): void
    {
        $lint  = new ReferenceLint(self::map());
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $this->expectException(RewriteFailed::class);
            $lint->lint('{view:12} cat_a');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    public function test_lint_reports_the_byte_offset(): void
    {
        $findings = (new ReferenceLint(self::map()))->lint('abc {view:999}');

        $this->assertCount(1, $findings);
        $this->assertSame(10, $findings[0]->offset);
    }

    public function test_export_lints_with_an_identity_map(): void
    {
        $app  = new IdMap(modules: [self::S => self::S], views: [3072 => 3072]);
        $lint = new ReferenceLint($app);

        $this->assertSame([], $lint->lint('{view:3072:{ids:' . self::S . '}}'));
        $this->assertSame(['view-outside'], array_map(static fn(LintFinding $f) => $f->code, $lint->lint('{view:3061}')));
    }
}
