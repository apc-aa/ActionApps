<?php

declare(strict_types=1);

/**
 * TargetCharset, C4.4 part 3 (design balicek-v1.md § 2.4): every value the importer writes converts
 * from UTF-8 to the charset of its target module and fits its column - checked in the plan, before
 * any write. Through the Planner: the charsets come from the modules' lang_file.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\FindingList;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use AA\Central\Package\Planner;
use AA\Central\Package\TargetCharset;
use AA\Central\Package\ValidationResult;
use AA\Central\Package\Validator;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeImportTarget;
use Tests\Unit\Central\Package\Fixtures\PackageMutations;

require_once __DIR__ . '/fixtures/PackageMutations.php';

final class TargetCharsetTest extends TestCase
{
    use PackageMutations;

    private const LATIN2 = 'hu_news_lang.php';      // iso-8859-2: Czech letters yes, € and CJK no
    private const CP1250 = 'cz_site_lang.php';
    private const OTHER_ITEM = '1234567890abcdef1234567890abcdef';

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
    }

    /** @param list<string> $taken groups taken on the target */
    private static function plan(Package $package, array $taken = []): ImportPlan
    {
        $validation = (new Validator())->validate($package);
        self::assertTrue($validation->importable(), 'the mutation must stay valid');
        return (new Planner(new FakeImportTarget($taken)))->plan($package, $validation);
    }

    /** @return list<string> "<level> <code> <path> <ref>" of the planner, sorted - no reference lint (PlannerTest) */
    private static function findings(ImportPlan $plan): array
    {
        $codes = [TargetCharset::CHARSET, TargetCharset::TOO_LONG, TargetCharset::MAY_BE_TOO_LONG, Planner::GROUP_RENAMED, Planner::LANG_FILE];
        $list  = array_filter($plan->findings, static fn(array $f): bool => in_array($f[1]->code, $codes, true) || str_starts_with($f[1]->ref, '+'));
        $list  = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", $list);
        sort($list);
        return $list;
    }

    private static function latin2(Package $p): Package
    {
        return self::lang($p, self::SLICE, self::LATIN2);
    }

    /** @return array<string, array{Closure(Package): Package, list<string>, 2?: list<string>}> mutation, findings, groups taken */
    public static function mutations(): array
    {
        $S = self::S;
        $W = self::W;
        $G = self::G;
        return [
            'Czech into iso-8859-2'            => [static fn(Package $p): Package => self::latin2($p), []],
            'euro into iso-8859-2'             => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'view', 0, ['name' => 'Cena 5 €']), ["E charset $S/view[12]/name €"]],
            'CJK into iso-8859-2'              => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'slice', 0, ['fulltext_format' => '<h1>_#HEADLINE</h1> 漢字']), ["E charset $S/slice[#0]/fulltext_format 漢字"]],
            'euro into utf-8'                  => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['name' => 'Cena 5 €']), []],
            'the ref stops at 16 bytes'        => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'view', 1, ['odd' => 'a 漢字漢字漢字漢字']), ["E charset $S/view[13]/odd 漢字漢字漢"]],
            // the group has the charset of its owner
            'constant into its owners charset' => [static fn(Package $p): Package => self::group(self::latin2($p), static fn(ConstantGroup $g): array => ['rows' => ['constant' => [['name' => '€'] + $g->rows['constant'][0], $g->rows['constant'][1]]] + $g->rows]), ["E charset $G/constant[" . self::C1 . ']/name €']],
            'constant, owner in utf-8'         => [static fn(Package $p): Package => self::group($p, static fn(ConstantGroup $g): array => ['rows' => ['constant' => [['name' => '€'] + $g->rows['constant'][0], $g->rows['constant'][1]]] + $g->rows]), []],
            'object property'                  => [static fn(Package $p): Package => self::object(self::latin2($p), self::SLICE, 0, static function (array $o): array {
                $o['props']['conds'][0]['props']['value'] = '€';
                return $o;
            }), ["E charset $S/object[" . self::SET . ']/conds[0]/value[0] €']],
            'settings'                         => [static fn(Package $p): Package => self::setting(self::latin2($p), self::SLICE, 'translations', ['cz', '漢']), ["E charset $S/settings/translations[1] 漢"]],
            'spot name, var, condition'        => [static fn(Package $p): Package => self::tree(self::lang($p, self::SITE, self::CP1250), static function (array $t): array {
                $t['spots'][1]['n'] = 'Článek';           // windows-1250 has it
                $t['spots'][0]['v'] = ['漢' => '漢'];
                $t['spots'][2]['c'] = ['x' => '€ ok', 'y' => '字'];     // windows-1250 has €
                return $t;
            }), ["E charset $W/spot-tree/spot[1]/var[#0] 漢", "E charset $W/spot-tree/spot[3]/cond[#1] 字"]],
            'site spot content'                => [static fn(Package $p): Package => self::cell(self::lang($p, self::SITE, self::CP1250), self::SITE, 'site_spot', 1, ['content' => 'Приём']), ["E charset $W/site_spot[2]/content Приём"]],
            'item value'                       => [static fn(Package $p): Package => self::items(self::latin2($p), [self::item(self::OTHER_ITEM, 'Cena €')]), ["E charset $S/item[" . self::OTHER_ITEM . ']/headline........[0] €']],
            // the rename takes a non-ASCII group name out of the text before the conversion
            'non-ASCII group in a text'        => [static fn(Package $p): Package => self::cell(self::latin2(self::with($p, ['constantGroups' => [new ConstantGroup('druhy_漢', self::SLICE)]])), self::SLICE, 'view', 1, ['odd' => '{constant:druhy_漢:x}']), ['I group-renamed constant-group[druhy_漢] druhy_漢 => druhy__']],
            'raw bytes go as they are'         => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'view', 0, ['odd' => new RawBytes("\xA9\xFF")]), []],
            // ---- length: the converted value against the column (Schema::lengthLimits())
            // ---- length: char(N) / varchar(N) count characters, the others bytes (Schema::lengthLimits()); AA_Metabase
            //      cuts every (N) value to N BYTES (AA_MbT::getVarset4Data()), so more bytes are an E as well
            'module name 101 characters'       => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'module', 0, ['name' => str_repeat('a', 101)]), ["E too-long $S/module[#0]/name 101 characters > 100"]],
            'module name 100 bytes'            => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'module', 0, ['name' => str_repeat('a', 100)]), []],
            // 90 Czech characters, 126 bytes in utf-8: AA_Metabase would cut it inside a character
            '90 Czech characters in utf-8'     => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'module', 0, ['name' => str_repeat('Žluťoučký ', 9)]), ["E too-long $S/module[#0]/name 126 bytes > 100"]],
            '101 Czech characters in utf-8'    => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'module', 0, ['name' => str_repeat('ž', 101)]), ["E too-long $S/module[#0]/name 101 characters > 100"]],
            '90 Czech characters, iso-8859-2'  => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'module', 0, ['name' => str_repeat('Žluťoučký ', 9)]), []],
            '101 x š in iso-8859-2'            => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'module', 0, ['name' => str_repeat('š', 101)]), ["E too-long $S/module[#0]/name 101 characters > 100"]],
            // RawBytes get the same rewrite (ImportRewrite): a module id keeps the length, a renamed group not
            'raw template, module id'          => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'view', 0, ['cond1cond' => new RawBytes("\xA9{ids:" . self::SLICE . '}' . str_repeat('y', 216))]), []],
            'raw template, group renamed'      => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'view', 0, ['cond1cond' => new RawBytes("\xA9{constant:ct_rubriky:x}" . str_repeat('y', 231))]), ["E too-long $S/view[12]/cond1cond 257 characters > 255"], ['ct_rubriky']],
            'varbinary counts bytes'           => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'field', 0, ['type' => str_repeat('x', 17)]), ["E too-long $S/field[headline........]/type 17 bytes > 16"]],
            'raw bytes too long'               => [static fn(Package $p): Package => self::cell(self::latin2($p), self::SLICE, 'field', 0, ['alias1' => new RawBytes(str_repeat("\xFF", 11))]), ["E too-long $S/field[headline........]/alias1 11 characters > 10"]],
            // MySQL (non-strict) cuts a utf-8 column at the first invalid byte - after the security lint saw the whole text
            'raw bytes no UTF-8 in utf-8'      => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['odd' => new RawBytes("ok\xFF{include:x}")]), ["E charset $S/view[12]/odd \xFF{include:x}"]],
            'raw bytes UTF-8 in utf-8'         => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['odd' => new RawBytes('ok č')]), []],
            // after the rewrite with the planned maps: a renamed group is longer
            'text group renamed'               => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['cond1cond' => '{constant:ct_rubriky:x}' . str_repeat('y', 232)]), ["E too-long $S/view[12]/cond1cond 257 characters > 255"], ['ct_rubriky']],
            // views and e-mails are numbered by the writer: up to 10 digits - a warning, the writer checks again
            'view number may grow'             => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['cond1cond' => 'vid=12 ' . str_repeat('y', 241)]), ["W may-be-too-long $S/view[12]/cond1cond 256 bytes > 255"]],
            'view number fits'                 => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 0, ['cond1cond' => 'vid=12 ' . str_repeat('y', 240)]), []],
        ];
    }


    /** @param Closure(Package): Package $mutate @param list<string> $expected @param list<string> $taken */
    #[DataProvider('mutations')]
    public function test_mutation(Closure $mutate, array $expected, array $taken = []): void
    {
        $plan = self::plan($mutate(self::example()), $taken);
        $renamed = $taken ? ['I group-renamed ' . self::G . ' ct_rubriky => ct_rubriky_1'] : [];
        sort($expected);

        self::assertSame(array_merge($expected, $renamed), self::findings($plan));
        self::assertSame(!array_filter($expected, static fn(string $f): bool => $f[0] === 'E'), $plan->importable());
    }

    public function test_an_unknown_module_charset_is_an_error(): void
    {
        $plan = (new Planner(new FakeImportTarget()))->plan(self::lang(self::example(), self::SLICE, 'xx_news_lang.php'), new ValidationResult([]));

        self::assertContains('E lang-file ' . self::S . '/module[#0]/lang_file xx_news_lang.php', self::findings($plan));
    }

    public function test_findings_are_limited_per_code(): void
    {
        $package = self::rows(self::latin2(self::example()), self::SLICE, 'view', static fn(): array => array_map(static fn(int $i): array => ['id' => (string) (100 + $i), 'name' => '€'], range(1, 60)));
        $package = self::cell($package, self::SLICE, 'slice', 0, ['vid' => '101']);
        $package = self::cell($package, self::SLICE, 'profile', 0, ['value' => '101']);
        $package = self::cell($package, self::ALERTS, 'alerts_filter', 0, ['vid' => '101']);
        $plan    = self::plan($package);

        $charset = array_values(array_filter($plan->findings, static fn(array $f): bool => $f[1]->code === TargetCharset::CHARSET));
        self::assertCount(FindingList::MAX_PER_CODE + 1, $charset, '50 of the views + "+10 more"');
        self::assertSame('+10 more (charset)', end($charset)[1]->ref);
    }
}
