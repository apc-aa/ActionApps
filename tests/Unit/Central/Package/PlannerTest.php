<?php

declare(strict_types=1);

/**
 * Planner, C4.4 part 3 (design balicek-v1.md § 7.1, § 7.2 step 3): the maps of the example package
 * and of its mutations, group collisions, the shared alerts filter, the preview. No DB - a fake target.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\FixUp;
use AA\Central\Package\GroupPlan;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use AA\Central\Package\Planner;
use AA\Central\Package\ValidationResult;
use AA\Central\Package\Validator;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeImportTarget;
use Tests\Unit\Central\Package\Fixtures\PackageMutations;

require_once __DIR__ . '/fixtures/PackageMutations.php';

final class PlannerTest extends TestCase
{
    use PackageMutations;

    private const ALERTS2 = '6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b';
    private const SLICE_B = '7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c';

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
    }

    private static function plan(Package $package, ?FakeImportTarget $target = null): ImportPlan
    {
        $validation = (new Validator())->validate($package);
        self::assertTrue($validation->importable(), 'the mutation must stay valid: ' . json_encode(array_map(static fn(array $f): string => "{$f[1]->code} $f[0]", $validation->ofLevel(LintFinding::ERROR))));
        return (new Planner($target ?? new FakeImportTarget()))->plan($package, $validation);
    }

    /** n-th id of FakeImportTarget::newId() */
    private static function id(int $n): string
    {
        return sprintf('%032x', 0xa000 + $n);
    }

    /** @return list<string> "<level> <code> <path>" of the planner, sorted; only codes starting with $prefix */
    private static function findings(ImportPlan $plan, string $prefix = ''): array
    {
        $list = array_filter($plan->findings, static fn(array $f): bool => str_starts_with($f[1]->code, $prefix));
        $list = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0]", $list);
        sort($list);
        return $list;
    }

    // ---------------------------------------------------------------- the example

    public function test_the_example_plans_cleanly(): void
    {
        $plan = self::plan(self::example());

        self::assertTrue($plan->importable());
        self::assertSame([], $plan->findings, 'no planner finding');
        self::assertSame([self::SITE => self::id(1), self::SLICE => self::id(2), self::ALERTS => self::id(3)], $plan->map->modules(), 'modules first, in package order');
        self::assertSame(['ct_rubriky' => 'ct_rubriky'], $plan->map->groups(), 'a free name stays');
        self::assertSame(['AbC12' => 'K0001'], $plan->map->collections());
        self::assertSame([[], [], []], [$plan->map->views(), $plan->map->emails(), $plan->map->filters()], 'numbers come from AUTO_INCREMENT - the writer');
        self::assertSame([self::SITE => 'utf-8', self::SLICE => 'utf-8', self::ALERTS => 'utf-8'], $plan->charsets);
        self::assertSame(['ct_rubriky' => 'utf-8'], $plan->groupCharsets);
        self::assertSame([['meta/publish', FixUp::PUBLISH, 'no', 'users']], self::fixUps($plan));
    }

    public function test_long_ids_of_objects_subobjects_constants_and_settings(): void
    {
        $plan = self::plan(self::example());
        $long = $plan->map->longIds();

        // modules 1-3, objects of the site, of the slice (the set, its condition and sort order), constants
        foreach ([self::ALIAS => 4, self::SET => 5, self::COND => 6, self::SORT => 7, self::C1 => 8, self::C2 => 9] as $old => $n) {
            self::assertSame(self::id($n), $long[$old] ?? null, $old);
        }
        // settings: derived from the new module id, old => new in the long map as well
        $site  = string2id('AA_Modulesettings_Site' . self::id(1));
        $slice = string2id('AA_Modulesettings_Slice' . self::id(2));
        self::assertSame([self::SITE => $site, self::SLICE => $slice], $plan->settingsIds);
        self::assertSame($site, $long[string2id('AA_Modulesettings_Site' . self::SITE)]);
        self::assertSame($slice, $long[string2id('AA_Modulesettings_Slice' . self::SLICE)]);
        self::assertCount(3 + 2 + 4 + 2, $long);
    }

    public function test_items_get_new_ids(): void
    {
        $item1 = '1234567890abcdef1234567890abcdef';
        $item2 = 'fedcba0987654321fedcba0987654321';
        $plan  = self::plan(self::with(self::items(self::example(), [self::item($item1), self::item($item2)]), ['publish' => 'no']));

        self::assertSame(self::id(8), $plan->map->longId($item1), 'after the slice objects');
        self::assertSame(self::id(9), $plan->map->longId($item2));
        self::assertSame(self::id(10), $plan->map->longId(self::C1), 'constants last');
    }

    public function test_a_new_id_handed_out_twice_is_asked_again(): void
    {
        $plan = self::plan(self::example(), new FakeImportTarget([], [self::id(50), self::id(50), self::id(50), self::id(51)]));

        self::assertSame([self::SITE => self::id(50), self::SLICE => self::id(51), self::ALERTS => self::id(1)], $plan->map->modules());
    }

    public function test_collections_are_unique_in_the_plan(): void
    {
        $plan = self::plan(self::alerts2(self::example(), self::ALERTS2, 'XyZ98'), new FakeImportTarget([], [], ['Dup01', 'Dup01', 'New02']));

        self::assertSame(['AbC12' => 'Dup01', 'XyZ98' => 'New02'], $plan->map->collections());
    }

    // ---------------------------------------------------------------- constant groups (§ 7.1)

    /** @return array<string, array{list<string>, list<string>, array<string, string>}> package groups,
     *          taken on the target, expected map */
    public static function groupCollisions(): array
    {
        return [
            'free'                           => [['ct_rubriky'], [], ['ct_rubriky' => 'ct_rubriky']],
            'taken on the target'            => [['ct_rubriky'], ['ct_rubriky'], ['ct_rubriky' => 'ct_rubriky_1']],
            'suffixes taken too'             => [['ct_rubriky'], ['ct_rubriky', 'ct_rubriky_1', 'ct_rubriky_2'], ['ct_rubriky' => 'ct_rubriky_3']],
            // the import's own names are never merged either: `ct` must not become the `ct_1` it brings
            'suffix of another package group' => [['ct', 'ct_1'], ['ct'], ['ct' => 'ct_2', 'ct_1' => 'ct_1']],
            'both taken'                     => [['ct', 'ct_1'], ['ct', 'ct_1'], ['ct' => 'ct_2', 'ct_1' => 'ct_1_1']],
            // at most 16 bytes, cut as CopyConstants() cuts (include/constedit_util.php:520-530)
            '16 bytes taken'                 => [['abcdefghijklmnop'], ['abcdefghijklmnop'], ['abcdefghijklmnop' => 'abcdefghijklmn_1']],
            'two-digit suffix'               => [['abcdefghijklmnop'], ['abcdefghijklmnop', ...array_map(static fn(int $i): string => "abcdefghijklmn_$i", range(1, 9))], ['abcdefghijklmnop' => 'abcdefghijklm_10']],
            // a new name is spliced into templates: [A-Za-z0-9_.-] only (IdMap)
            'odd name free stays'            => [['my grp'], [], ['my grp' => 'my grp']],
            'odd name taken'                 => [['my grp'], ['my grp'], ['my grp' => 'my_grp']],
            'odd name taken, clean one too'  => [['my grp'], ['my grp', 'my_grp'], ['my grp' => 'my_grp_1']],
            // bytes of a non-ASCII name differ by charset (constant.group_id vs field.input_show_func)
            'non-ASCII name'                 => [['rubriky_čísla'], [], ['rubriky_čísla' => 'rubriky___sla']],
            'core groups are not mapped'     => [['lt_languages'], [], []],
            // one group per name as lt_groupNames compares it: case and trailing spaces do not count
            'case in one package'            => [['Foo', 'foo'], [], ['Foo' => 'Foo', 'foo' => 'foo_1']],
            'suffix of another, in any case' => [['ct', 'CT_1'], ['ct'], ['ct' => 'ct_2', 'CT_1' => 'CT_1']],
        ];
    }

    /** @param list<string> $groups @param list<string> $taken @param array<string, string> $expected */
    #[DataProvider('groupCollisions')]
    public function test_group_collisions(array $groups, array $taken, array $expected): void
    {
        $package = self::with(self::example(), ['constantGroups' => array_map(static fn(string $name): ConstantGroup => new ConstantGroup($name, self::SLICE), $groups)]);
        // the core group is Validator's E - planned anyway it is skipped, never renamed
        $validation = new ValidationResult([]);
        $target     = new FakeImportTarget($taken);
        $plan       = (new Planner($target))->plan($package, $validation);

        self::assertSame($expected, $plan->map->groups());
        $renamed = array_filter($expected, static fn(string $new, string $old): bool => $new !== $old, ARRAY_FILTER_USE_BOTH);
        self::assertSame(array_map(static fn(string $old): string => "I group-renamed constant-group[$old]", array_keys($renamed)), self::findings($plan, Planner::GROUP_RENAMED));
        self::assertSame(array_map(static fn(string $old, string $new): array => ['old' => $old, 'new' => $new], array_keys($renamed), $renamed), $plan->preview()['groups']);
        self::assertSame([], array_filter($target->asked, static fn(string $name): bool => !mb_check_encoding($name, 'ASCII')), 'no non-ASCII name reaches the target');
    }

    public function test_no_free_group_name_is_an_error(): void
    {
        $taken = ['g', ...array_map(static fn(int $i): string => "g_$i", range(1, GroupPlan::MAX_SUFFIX))];
        $plan  = (new Planner(new FakeImportTarget($taken)))->plan(self::with(self::example(), ['constantGroups' => [new ConstantGroup('g', self::SLICE)]]), new ValidationResult([]));

        self::assertFalse($plan->importable());
        self::assertSame(['E group-name-exhausted constant-group[g]'], self::findings($plan, Planner::GROUP_NAME_EXHAUSTED));
        self::assertSame([], $plan->map->groups());
    }

    /** @return array<string, array{ConstantGroup, string}> the group, its charset */
    public static function groupCharsets(): array
    {
        return [
            // site windows-1250, slice utf-8 (the example's), slice B iso-8859-2, alerts windows-1251
            'owner, though B names it'  => [new ConstantGroup('g_owner', self::SLICE), 'utf-8'],
            'widget user'               => [new ConstantGroup('g_widget'), 'iso-8859-2'],
            'text user of alerts'       => [new ConstantGroup('g_alerts'), 'windows-1251'],
            'text user of the site'     => [new ConstantGroup('g_site'), 'windows-1250'],
            'a slice before the site'   => [new ConstantGroup('g_both'), 'iso-8859-2'],
            'object code'               => [new ConstantGroup('g_alias'), 'windows-1250'],
            'unused: the first slice'   => [new ConstantGroup('g_unused'), 'utf-8'],
        ];
    }

    /** the export's rule (Collector::groups()): the owner's charset, else the first module naming the group
     *  in a widget or a text, a slice preferred */
    #[DataProvider('groupCharsets')]
    public function test_group_charset_mirrors_the_export(ConstantGroup $group, string $charset): void
    {
        $package = self::lang(self::lang(self::example(), self::SITE, 'cz_site_lang.php'), self::ALERTS, 'bg_alerts_lang.php');
        $package = self::with($package, ['modules' => [...$package->modules, new Module(Module::SLICE, self::SLICE_B, [
            'module' => [['name' => 'B', 'lang_file' => 'hu_news_lang.php']],
            'slice'  => [['name' => 'B']],
            'field'  => [['id' => 'category........', 'input_show_func' => 'sel:g_widget:'], ['id' => 'category.......2', 'input_show_func' => 'mch:g_owner:']],
            'view'   => [['id' => '50', 'odd' => '{constant:g_both:x:name}']],
        ])]]);
        $package = self::cell($package, self::ALERTS, 'email', 0, ['body' => '{constants:g_alerts:,}']);
        $package = self::cell($package, self::SITE, 'site_spot', 1, ['content' => '{options:g_site:x} {constant:g_both:y}']);
        $package = self::object($package, self::SITE, 0, static fn(array $o): array => ['props' => ['code' => '{constant:g_alias:x}'] + $o['props']] + $o);
        $plan    = (new Planner(new FakeImportTarget()))->plan(self::with($package, ['constantGroups' => [$group]]), new ValidationResult([]));

        self::assertSame([self::SITE => 'windows-1250', self::SLICE => 'utf-8', self::ALERTS => 'windows-1251', self::SLICE_B => 'iso-8859-2'], $plan->charsets);
        self::assertSame([$group->name => $charset], $plan->groupCharsets);
    }

    public function test_lang_files_are_normalised_for_the_writer(): void
    {
        $plan = self::plan(self::lang(self::example(), self::SLICE, 'cz-utf8_news_lang.php3'));

        self::assertSame([self::SITE => 'cz-utf8_site_lang.php', self::SLICE => 'cz-utf8_news_lang.php', self::ALERTS => 'cz-utf8_alerts_lang.php'], $plan->langFiles);
    }

    public function test_a_package_id_equal_to_a_new_settings_id_is_an_error(): void
    {
        $settings = string2id('AA_Modulesettings_Slice' . self::id(2));
        $plan     = self::plan(self::object(self::example(), self::SITE, 0, static fn(array $o): array => ['id' => $settings] + $o));

        self::assertSame(['E duplicate-id ' . self::W], self::findings($plan, Planner::SETTINGS_ID));
        self::assertFalse($plan->importable());
    }

    // ---------------------------------------------------------------- alerts filter shared by Alerts modules

    public function test_a_shared_filter_is_planned_once(): void
    {
        $plan    = self::plan(self::alerts2(self::example(), self::ALERTS2, 'XyZ98'));
        $modules = array_column(array_map(static fn(Module $m): array => [$m->id, $m], $plan->package->modules), 1, 0);

        self::assertSame(['3'], array_column($modules[self::ALERTS]->rows['alerts_filter'], 'id'));
        self::assertArrayNotHasKey('alerts_filter', $modules[self::ALERTS2]->rows, 'the second module creates no row');
        self::assertSame('3', $modules[self::ALERTS2]->rows['alerts_collection_filter'][0]['filterid'], 'its collection still uses the filter');
        self::assertContains(['alerts-module[' . self::ALERTS2 . ']/alerts_filter[3]', FixUp::FILTER_SHARED, '3', self::A], self::fixUps($plan));
    }

    // ---------------------------------------------------------------- the reference lint (§ 8.4)

    /** @return array<string, array{Closure(Package): Package, list<string>}> */
    public static function references(): array
    {
        $S = self::S;
        $W = self::W;
        return [
            'view outside'         => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 1, ['odd' => '{view:99}']), ["W view-outside $S/view[13]/odd"]],
            'computed view'        => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 1, ['odd' => '{view.php?vid={x}}']), ["W computed $S/view[13]/odd"]],
            'module outside'       => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['body' => '{ids:ffffffffffffffffffffffffffffffff}']), ["W module-outside $S/email[5]/body"]],
            'e-mail outside'       => [static fn(Package $p): Package => self::cell($p, self::SITE, 'site_spot', 1, ['content' => '{mailtemplate:8}']), ["W email-outside $W/site_spot[2]/content"]],
            'group outside'        => [static fn(Package $p): Package => self::cell($p, self::SLICE, 'view', 1, ['odd' => '{constant:ct_other:x:name}']), ["I group-outside $S/view[13]/odd"]],
            'in a spot condition'  => [static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                $t['spots'][2]['c'] = ['x' => '{view:99}'];
                return $t;
            }), ["W view-outside $W/spot-tree/spot[3]/cond[#0]"]],
            'in an alias function' => [static fn(Package $p): Package => self::object($p, self::SITE, 0, static function (array $o): array {
                $o['props']['code'] = '{view:99}';
                return $o;
            }), ["W view-outside $W/object[" . self::ALIAS . ']/code[0]']],
            'widget group is no text reference' => [static fn(Package $p): Package => $p, []],
            'raw bytes are linted as bytes' => [static fn(Package $p): Package => self::cell(self::lang($p, self::SLICE, 'cz_news_lang.php'), self::SLICE, 'view', 1, ['odd' => new RawBytes("\xA9 {ids:ffffffffffffffffffffffffffffffff} {ids:" . self::SLICE . '}')]), ["W module-outside $S/view[13]/odd"]],
            'item values are not linted' => [static fn(Package $p): Package => self::items($p, [self::item('1234567890abcdef1234567890abcdef', '{view:99}')]), []],
        ];
    }

    /** @param Closure(Package): Package $mutate @param list<string> $expected */
    #[DataProvider('references')]
    public function test_reference_lint(Closure $mutate, array $expected): void
    {
        self::assertSame($expected, self::findings(self::plan($mutate(self::example()))));
    }

    // ---------------------------------------------------------------- the plan and its preview

    public function test_an_alerts_field_of_a_collection_outside_the_package_is_kept_with_a_note(): void
    {
        $plan = self::plan(self::rows(self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows,
            ['id' => 'alerts1....AbC12', 'type' => 'alerts', 'name' => 'Of the package'],
            ['id' => 'alerts2....Zz999', 'type' => 'alerts', 'name' => 'Of a collection not in the package'],
            ['id' => 'alerts1.........', 'type' => 'alerts', 'name' => 'No collection id'],
        ]));

        self::assertTrue($plan->importable());
        self::assertSame(['I alerts-field-kept ' . self::S . '/field[alerts2....Zz999]'], self::findings($plan, 'alerts-'));
        self::assertSame(['Zz999'], array_values(array_map(static fn(array $f): string => $f[1]->ref, array_filter($plan->findings, static fn(array $f): bool => $f[1]->code === Planner::ALERTS_FIELD_KEPT))));
    }

    public function test_a_package_with_errors_is_not_planned(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Planner(new FakeImportTarget()))->plan(self::example(), new ValidationResult([['x', new LintFinding(LintFinding::ERROR, 'y', '', 0)]]));
    }

    public function test_the_planner_changes_a_copy(): void
    {
        $package = self::setting(self::example(), self::SITE, 'ga_id', 'G-1');
        $plan    = self::plan($package);

        self::assertSame('G-1', $package->modules[0]->settings['ga_id'] ?? null, 'the model passed in stays');
        self::assertArrayNotHasKey('ga_id', $plan->package->modules[0]->settings ?? []);
        self::assertSame('users', $plan->package->publish);
    }

    public function test_preview(): void
    {
        $package = self::with(self::example(), ['doc' => 'Read me <b>first</b>']);
        $package = self::module($package, self::SLICE, static fn(Module $m): Module => self::with($m, ['doc' => 'Articles']));
        $package = self::cell(self::lang($package, self::SLICE, 'hu_news_lang.php'), self::SLICE, 'view', 0, ['name' => 'Cena €']);   // iso-8859-2 has no €
        $package = self::cell($package, self::SLICE, 'slice', 0, ['fulltext_format' => '<h1>漢字</h1>']);
        $plan    = self::plan($package, new FakeImportTarget(['ct_rubriky']));
        $preview = $plan->preview();

        self::assertSame('Central Test', $preview['name']);
        self::assertSame('0.1.0', $preview['version']);
        self::assertSame('Read me <b>first</b>', $preview['doc'], 'as the package has it - the UI escapes');
        self::assertSame('https://actionapps.org/aadev/', $preview['source']['url']);
        self::assertSame([
            ['type' => Module::SITE, 'name' => 'Central Test - web', 'old' => self::SITE, 'new' => self::id(1), 'charset' => 'utf-8', 'doc' => 'Web appky. Spot 1 = kostra stránky.'],
            ['type' => Module::SLICE, 'name' => 'Central Test - články', 'old' => self::SLICE, 'new' => self::id(2), 'charset' => 'iso-8859-2', 'doc' => 'Articles'],
            ['type' => Module::ALERTS, 'name' => 'Upozornění', 'old' => self::ALERTS, 'new' => self::id(3), 'charset' => 'utf-8', 'doc' => null],
        ], $preview['modules']);
        self::assertSame([['old' => 'ct_rubriky', 'new' => 'ct_rubriky_1']], $preview['groups']);
        self::assertSame($plan->fixUps, $preview['fixUps']);
        // validator and planner in one list, by danger: E (the charset), W (perm-mode of the example), I
        self::assertSame(
            ['E charset', 'E charset', 'W perm-mode', 'I group-renamed'],
            array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code}", $preview['findings']),
        );
        self::assertFalse($plan->importable());
    }

    /** § 7.2 step 4: tables the import writes without a transaction are an error - the rollback is the only
     *  cleanup of a failed import; the finding names them and the optimization that converts them */
    public function test_tables_without_transactions_are_an_error_of_the_plan(): void
    {
        $target          = new FakeImportTarget();
        $target->engines = ['view' => 'MyISAM', 'object_float' => 'Aria', 'content' => 'MyISAM', 'item' => 'MyISAM', 'nothing' => 'MyISAM'];
        $advice          = ' - no transaction would roll a failed import back: run "Convert database engine to InnoDB" (State Check, Optimize) and plan again';

        $plan = self::plan(self::example(), $target);

        $found = array_values(array_filter($plan->findings, static fn(array $f): bool => $f[1]->code === Planner::NO_TRANSACTION));
        self::assertSame([[Planner::TARGET, 'E', 'view (MyISAM), object_float (Aria)' . $advice]], array_map(static fn(array $f): array => [$f[0], $f[1]->level, $f[1]->ref], $found), 'the item tables only with items');
        self::assertFalse($plan->importable(), 'an error');
        self::assertContains('E no-transaction', array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code}", $plan->preview()['findings']));

        $items = self::plan(self::items(self::example(), [self::item('1234567890abcdef1234567890abcdef')]), $target);
        self::assertStringEndsWith('item (MyISAM), content (MyISAM)' . $advice, array_values(array_filter($items->findings, static fn(array $f): bool => $f[1]->code === Planner::NO_TRANSACTION))[0][1]->ref);
        self::assertSame([], array_filter(self::plan(self::example())->findings, static fn(array $f): bool => $f[1]->code === Planner::NO_TRANSACTION), 'all InnoDB: nothing');
    }

    /** @return list<array{string, string, string, string}> */
    private static function fixUps(ImportPlan $plan): array
    {
        return array_map(static fn(FixUp $f): array => [$f->path, $f->code, $f->old, $f->new], $plan->fixUps);
    }
}
