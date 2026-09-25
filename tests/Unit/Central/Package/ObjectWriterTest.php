<?php

declare(strict_types=1);

/**
 * ObjectWriter, C4.4 part 4c (design balicek-v1.md § 5.4, § 7.2 step 5.6): the example's settings, alias
 * and set with its subobjects written by the Importer into TableDb - new ids, the owner, rewritten and
 * converted properties, subobject references and aa_subobjects computed; the rows equal to what
 * AA_Object::save() writes (AA saves, ObjectExport exports, ObjectWriter writes back); a package that tries
 * to set aa_subobjects or a class off the allowlist is never written, and the foreign objects of the target
 * are never touched - after a success or a rolled back failure.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\IdMap;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\ObjectExport;
use AA\Central\Package\ObjectWriter;
use AA\Central\Package\RowStore;
use AA\Central\Package\Schema;
use AA\Central\Package\Validator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\FakeSource;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/ImportFixture.php';
require_once __DIR__ . '/fixtures/FakeSource.php';

final class ObjectWriterTest extends TestCase
{
    use ImportFixture {
        setUp as private fixtureSetUp;
    }

    /** @var array<string, list<array<string, mixed>>> the target's rows before the import */
    private array $before;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        TableDb::$next['object_integer'] = 100;
        $this->before                    = self::foreign();
    }

    /** n-th id of FakeImportTarget::newId() - modules 1-3, objects 4-7 */
    private static function id(int $n): string
    {
        return sprintf('%032x', 0xa000 + $n);
    }

    /** [object_id, property, priority, value] of a table's rows (the value as a string) */
    private static function objectRows(string $table = 'object_text', ?array $of = null): array
    {
        $rows = array_filter(TableDb::rows($table), static fn(array $r): bool => ($of === null) || in_array($r['object_id'], $of, true));
        return array_values(array_map(static fn(array $r): array => [$r['object_id'], $r['property'], (int) $r['priority'], (string) $r['value']], $rows));
    }

    // ---------------------------------------------------------------- the example

    public function test_the_example_objects_get_new_ids_their_owner_and_rewritten_properties(): void
    {
        $this->importer()->write($this->plan());
        [$w, $s]   = [self::id(1), self::id(2)];
        [$sw, $ss] = [string2id('AA_Modulesettings_Site' . $w), string2id('AA_Modulesettings_Slice' . $s)];
        [$alias, $set, $cond, $sort] = [self::id(4), self::id(5), self::id(6), self::id(7)];

        self::assertSame([
            [$sw, 'translate_slice', 0, $s],                // a module id of the package: the new one
            [$sw, 'web_languages', 0, 'cz'],
            [$sw, 'page404', 0, '2'],
            [$sw, 'aa_type', 0, 'AA_Modulesettings_Site'],
            [$sw, 'aa_owner', 0, $w],
            [$alias, 'alias', 0, 'Hlavicka'],
            [$alias, 'code', 0, '<header><h1>_#P1</h1></header>'],
            [$alias, 'aa_type', 0, 'AA_Aliasfunc'],
            [$alias, 'aa_owner', 0, $w],
            [$ss, 'translations', 0, 'cz'],
            [$ss, 'translations', 1, 'en'],
            [$ss, 'aa_type', 0, 'AA_Modulesettings_Slice'],
            [$ss, 'aa_owner', 0, $s],
            [$cond, 'fields', 0, 'category.......1'],       // the subobject first, as save() - owner: the module
            [$cond, 'operator', 0, '='],
            [$cond, 'value', 0, 'zpravy'],
            [$cond, 'aa_type', 0, 'AA_Condition'],
            [$cond, 'aa_owner', 0, $s],
            [$set, 'conds', 0, $cond],                      // then the set's row with the NEW id
            [$sort, 'field', 0, 'publish_date....'],
            [$sort, 'desc', 0, '1'],
            [$sort, 'aa_type', 0, 'AA_Sortorder'],
            [$sort, 'aa_owner', 0, $s],
            [$set, 'sort', 0, $sort],
            [$set, 'slices', 0, $s],
            [$set, 'aa_type', 0, 'AA_Set'],
            [$set, 'aa_owner', 0, $s],
            [$set, 'aa_subobjects', 0, $cond],              // computed from what was written
            [$set, 'aa_subobjects', 1, $sort],
        ], self::objectRows('object_text', [$sw, $ss, $alias, $set, $cond, $sort]));
        self::assertSame($this->before['object_text'], array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['id'] < 100)), 'the target\'s objects');
        self::assertSame([], array_filter(array_column(TableDb::rows('object_text'), 'value'), static fn(string $v): bool => in_array($v, [self::SITE, self::SLICE, self::ALIAS, self::SET, self::COND, self::SORT], true)), 'no package id is written');
        self::assertCount(1, array_unique(TableDb::$handles), 'every statement on the transaction handle');
    }

    public function test_a_bool_property_goes_to_object_integer_and_code_is_rewritten_and_converted(): void
    {
        $package = self::setting(self::example(), self::SLICE, 'autofields', '1');
        $package = self::lang($package, self::SITE, 'cz_site_lang.php');
        $package = self::object($package, self::SITE, 0, static fn(array $o): array => ['name' => 'Hlavička'] + array_replace_recursive($o, ['props' => [
            'code'   => '<h1>č {view:12} {set:' . self::SET . '} {item:' . self::SLICE . '}</h1>',
            'ussage' => ['{_:Hlavicka:a}', '{_:Hlavicka:b}'],
        ]]));

        $this->importer()->write($this->plan($package));

        $ss = string2id('AA_Modulesettings_Slice' . self::id(2));
        self::assertSame([[$ss, 'autofields', 0, '1']], self::objectRows('object_integer', [$ss]));
        self::assertSame(1, TableDb::rows('object_integer')[1]['value'], 'a number - the column is bigint');
        $cp = static fn(string $utf): string => (string) iconv('UTF-8', 'windows-1250', $utf);
        self::assertSame([
            [self::id(4), 'alias', 0, 'Hlavicka'],
            [self::id(4), 'code', 0, $cp('<h1>č {view:1000} {set:' . self::id(5) . '} {item:' . self::id(2) . '}</h1>')],
            [self::id(4), 'ussage', 0, '{_:Hlavicka:a}'],
            [self::id(4), 'ussage', 1, '{_:Hlavicka:b}'],
            [self::id(4), 'aa_type', 0, 'AA_Aliasfunc'],
            [self::id(4), 'aa_owner', 0, self::id(1)],
            [self::id(4), 'aa_name', 0, $cp('Hlavička')],
        ], self::objectRows('object_text', [self::id(4)]));
    }

    public function test_a_module_without_settings_or_objects_writes_none(): void
    {
        $package = self::module(self::example(), self::SITE, static fn(Module $m): Module => self::with($m, ['settings' => null, 'objects' => []]));

        $this->importer()->write($this->plan($package));

        self::assertSame([], self::objectRows('object_text', [string2id('AA_Modulesettings_Site' . self::id(1))]));
    }

    // ---------------------------------------------------------------- the format of AA_Object::save()

    /** AA saves the objects of a slice, ObjectExport reads them as the Collector does, ObjectWriter writes
     *  them back under new ids: the rows are save()'s, table by table, in its order */
    public function test_the_rows_are_the_ones_aa_object_save_writes(): void
    {
        TableDb::reset();
        $source = '0123456789abcdef0123456789abcdef';
        $ids    = ['s' => string2id('AA_Modulesettings_Slice' . $source), 'alias' => str_repeat('1a', 16), 'set' => str_repeat('2b', 16),
            'c1' => str_repeat('3c', 16), 'c2' => str_repeat('4d', 16), 'sort' => str_repeat('5e', 16)];
        $settings = new \AA_Modulesettings_Slice();
        $settings->setNew($ids['s'], $source);
        $settings->setProperty('translations', ['cz', 'en'])->setProperty('autofields', 1)->setProperty('fieldslice', '')
            ->setProperty('historylog', '30')->setProperty('nocheck_pending', '{ifeq:1:1:1}')->setProperty('nocheck_expired', '');
        $settings->save();
        $alias = new \AA_Aliasfunc('Box', '<div>_#P1</div>', 'A box', ['{_:Box:x}', '{_:Box:y}']);
        $alias->setNew($ids['alias'], $source, 'boxname');
        $alias->save();
        $set = new \AA_Set();
        $set->setNew($ids['set'], $source);
        $set->setProperty('slices', [$source])->setProperty('conds', [
            (new \AA_Condition(['headline........'], '=', 'x'))->setId($ids['c1']),
            (new \AA_Condition(['category.......1', 'headline........'], 'BEGIN', 'a'))->setId($ids['c2']),
        ])->setProperty('sort', [(new \AA_Sortorder())->setId($ids['sort'])->setProperty('field', 'publish_date....')->setProperty('desc', '1')]);
        $set->save();
        $saved = TableDb::$tables;

        [$exported, $objects, $findings] = (new ObjectExport(new FakeSource($saved)))->collect($source, Module::SLICE, 'slice');
        self::assertSame([], $findings);
        $slice    = str_repeat('9f', 16);
        $settings = string2id('AA_Modulesettings_Slice' . $slice);
        $new      = [];            // the objects and subobjects: 0000..b001, ...
        foreach (array_slice(array_values($ids), 1) as $n => $old) {
            $new[$old] = sprintf('%032x', 0xb001 + $n);
        }
        $plan = new ImportPlan(
            new Package('p', [], [new Module(Module::SLICE, $source, [], $exported, $objects)]),
            new IdMap([$source => $slice], $new),
            [$source => 'utf-8'],
            [],
            [],
            [$source => $settings],
            [],
            [],
            [],
        );
        TableDb::reset();
        (new ObjectWriter(new RowStore(\AA_Metabase::singleton(), new Schema())))->write($plan, $plan->map);

        self::assertSame([], array_intersect(array_column(TableDb::rows('object_text'), 'object_id'), $ids), 'no id of the source');
        $back = array_flip($new) + [$settings => $ids['s'], $slice => $source];
        foreach (ObjectExport::TABLES as $table) {
            $expected = array_map(static fn(array $r): array => [$r['object_id'], $r['property'], (int) $r['priority'], (string) $r['value']], $saved[$table] ?? []);
            $written  = array_map(static fn(array $r): array => [$back[$r[0]], $r[1], $r[2], $back[$r[3]] ?? $r[3]], self::objectRows($table));
            self::assertSame($expected, $written, $table);
        }
        self::assertCount(41, $saved['object_text'], 'settings 8, alias 8, set 25 (with the empty `limit` of the sort order, aa_subobjects 3)');
        self::assertCount(1, $saved['object_integer'], 'autofields - a bool');
    }

    // ---------------------------------------------------------------- what is never written

    /** @return array<string, array{\Closure(Package): Package}> */
    public static function smuggled(): array
    {
        return [
            'aa_subobjects of a set'       => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static fn(array $o): array => array_replace_recursive($o, ['props' => ['aa_subobjects' => [self::FSET, self::FCOND]]]))],
            'aa_subobjects of a condition' => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static function (array $o): array {
                $o['props']['conds'][0]['props']['aa_subobjects'] = self::FCOND;
                return $o;
            })],
            'aa_subobjects of the settings' => [static fn(Package $p): Package => self::setting($p, self::SLICE, 'aa_subobjects', self::FSET)],
            'aa_owner'                     => [static fn(Package $p): Package => self::object($p, self::SITE, 0, static fn(array $o): array => array_replace_recursive($o, ['props' => ['aa_owner' => self::FS]]))],
            'a class off the allowlist'    => [static fn(Package $p): Package => self::object($p, self::SITE, 0, static fn(array $o): array => ['class' => 'AA_Plannedtask'] + $o)],
            'a subobject of another class' => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static function (array $o): array {
                $o['props']['conds'][0]['class'] = 'AA_Set';
                return $o;
            })],
            'a subobject of a subobject'   => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static function (array $o): array {
                $o['props']['conds'][0]['props']['fields'] = [['id' => self::FCOND, 'class' => 'AA_Condition', 'props' => []]];
                return $o;
            })],
            'a subobject id as a value'    => [static fn(Package $p): Package => self::object($p, self::SLICE, 0, static function (array $o): array {
                $o['props']['conds'] = [self::FCOND];
                return $o;
            })],
            'objects of an Alerts module'  => [static fn(Package $p): Package => self::module($p, self::ALERTS, static fn(Module $m): Module => self::with($m, ['objects' => [
                ['id' => self::FSET, 'class' => 'AA_Aliasfunc', 'props' => ['alias' => 'x']],
            ]]))],
        ];
    }

    /** The Validator refuses such a package (E); a plan forced past it: the writer throws before the object's
     *  first row, the rollback leaves the foreign objects - also the ones the package named - as they were */
    #[DataProvider('smuggled')]
    public function test_a_smuggled_property_or_class_is_never_written_and_nothing_foreign_is_touched(\Closure $mutate): void
    {
        $bad = $mutate(self::example());
        self::assertContains(LintFinding::ERROR, array_map(static fn(array $f): string => $f[1]->level, (new Validator())->validate($bad)->findings));
        $foreign = TableDb::$tables;
        $plan    = $this->plan();
        $forced  = new ImportPlan(...array_merge(get_object_vars($plan), ['package' => $bad]));

        $e = $this->failure($forced);

        self::assertInstanceOf(LogicException::class, $e->getPrevious());
        self::assertSame($foreign, array_filter(TableDb::$tables), 'the foreign set, its condition and the rest');
        $sent = array_merge(TableDb::$sent['object_text'] ?? [], TableDb::$sent['object_integer'] ?? []);
        self::assertSame([], array_filter($sent, static fn(array $r): bool => in_array($r['value'], [self::FSET, self::FCOND, self::FS], true) || in_array($r['object_id'], [self::FSET, self::FCOND], true)));
        self::assertNotContains('AA_Plannedtask', array_column($sent, 'value'));
    }

    /** ga_id, alternate_url, app_* of the site settings: FixUps drop them (the Validator: W); a plan forced
     *  past that - the writer refuses them as it refuses aa_* */
    public function test_a_left_out_setting_is_never_written(): void
    {
        $plan = $this->plan();
        foreach (['ga_id' => 'UA-1', 'alternate_url' => ['https://source.example.org'], 'app_version' => '1'] as $name => $value) {
            $forced = new ImportPlan(...array_merge(get_object_vars($plan), ['package' => self::setting($plan->package, self::SITE, $name, $value)]));

            $e = $this->failure($forced);

            self::assertInstanceOf(LogicException::class, $e->getPrevious(), $name);
            self::assertStringContainsString("settings/$name: AA_Modulesettings_Site has no such property", $e->getPrevious()->getMessage());
            self::assertSame($this->before, array_filter(TableDb::$tables), $name);
        }
    }

    /** save() writes aa_name only when PHP takes it for true: not '' nor '0' */
    public function test_an_object_named_0_gets_no_aa_name(): void
    {
        $this->importer()->write($this->plan(self::object(self::example(), self::SITE, 0, static fn(array $o): array => ['name' => '0'] + $o)));

        self::assertNotContains('aa_name', array_column(self::objectRows('object_text', [self::id(4)]), 1));
    }

    /** package object ids that are ids of objects on the target (and the old derived settings id): written
     *  under the plan's new ids; the target's objects stay */
    public function test_package_ids_of_target_objects_are_written_under_new_ids(): void
    {
        $old_settings = string2id('AA_Modulesettings_Slice' . self::SLICE);
        foreach ([self::ALIAS, self::SET, self::COND, self::SORT, $old_settings] as $k => $id) {
            TableDb::$tables['object_text'][] = ['id' => 50 + $k, 'object_id' => $id, 'property' => 'aa_owner', 'priority' => 0, 'value' => self::FS];
            TableDb::$tables['object_text'][] = ['id' => 60 + $k, 'object_id' => $id, 'property' => 'aa_subobjects', 'priority' => 0, 'value' => self::FCOND];
        }
        $foreign = TableDb::$tables;

        $this->importer()->write($this->plan());

        $theirs = static fn(): array => array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['id'] < 100));
        self::assertSame($foreign['object_text'], $theirs());
    }
}
