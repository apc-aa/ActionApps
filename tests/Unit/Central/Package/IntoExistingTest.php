<?php

declare(strict_types=1);

/**
 * A slice of the package into an existing slice of the target: the planner keeps what the target has
 * (fields by id, views and e-mails by name, objects by class and name, the slice row and its state), the
 * writer adds only what is missing and points the package's references at the existing slice; the items of
 * a transfer go there too. The target is read through DbImportTarget over TableDb.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\AppExport;
use AA\Central\InstallRun;
use AA\Central\TransferCheck;
use AA\Central\Package\BatchResult;
use AA\Central\Package\DbImportTarget;
use AA\Central\Package\ExistingSlice;
use AA\Central\Package\ExistingSlices;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\ItemLint;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Batch;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use AA\Central\Package\Planner;
use AA\Central\Package\Validator;
use AA\IO\DB\DB_AA;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeImportTarget;
use Tests\Unit\Central\Package\Fixtures\FakeSource;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Central\Package\Fixtures\TableDbTarget;

require_once __DIR__ . '/fixtures/ImportFixture.php';
require_once __DIR__ . '/fixtures/FakeSource.php';

final class IntoExistingTest extends TestCase
{
    use ImportFixture {
        setUp as private fixtureSetUp;
    }

    /** the existing slice of the target the example's slice goes into */
    private const T = 'e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0';
    /** an item of the source slice */
    private const ITEM_A = '1234567890abcdef1234567890abcdef';

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        self::foreign();
        self::existing();
    }

    /** The target slice T: the example's headline as it is, its e-mail field under another name, a view
     *  named as view 12 (field3), the e-mail 5 by its description, an item of its own. */
    private static function existing(): void
    {
        $t = self::T;
        TableDb::$tables['module'][] = ['id' => $t, 'type' => 'S', 'name' => 'Existing', 'lang_file' => 'cz-utf8_news_lang.php', 'deleted' => 0];
        TableDb::$tables['slice'][]  = ['id' => $t, 'name' => 'Existing', 'type' => 'S', 'deleted' => 0, 'vid' => 20];
        TableDb::$tables['field'][]  = ['id' => 'headline........', 'slice_id' => $t, 'type' => 'headline', 'name' => 'Titulek', 'input_pri' => 100, 'input_show_func' => 'fld:',
            'alias1' => '_#HEADLINE', 'alias1_func' => 'f_h:', 'text_stored' => 1, 'input_insert_func' => ''];
        TableDb::$tables['field'][]  = ['id' => '_email..........', 'slice_id' => $t, 'type' => 'email', 'name' => 'Mail', 'input_help' => 'Kam chodí upozornění',
            'input_default' => 'txt:redakce@example.org', 'required' => 1, 'text_stored' => 1, 'input_insert_func' => ''];
        TableDb::$tables['view'][]   = ['id' => 20, 'slice_id' => $t, 'name' => 'Jiné jméno', 'field3' => 'clanky', 'odd' => 'mine'];
        TableDb::$tables['email'][]  = ['id' => 21, 'owner_module_id' => $t, 'description' => 'Upozornění redakci', 'body' => 'mine'];
        TableDb::$tables['item'][]   = ['id' => str_repeat('7', 32), 'short_id' => 2, 'slice_id' => $t, 'status_code' => 1];
    }

    private function db(): TableDbTarget
    {
        return new TableDbTarget($this->target);
    }

    /** the plan of $package with $into, through the DB target @param array<string, string> $into */
    private function planInto(?Package $package = null, array $into = [self::SLICE => self::T], bool $transfer = false, bool $sameAa = false): ImportPlan
    {
        $package ??= self::example();
        return (new Planner($this->db()))->plan($package, (new Validator())->validate($package, $transfer), $transfer, [], $into, $sameAa);
    }

    /** "<level> <code> <path> <ref>", sorted @param list<array{string, LintFinding}> $findings @return list<string> */
    private static function codes(array $findings, string $prefix = ''): array
    {
        $list = array_filter($findings, static fn(array $f): bool => str_starts_with($f[1]->code, $prefix));
        $list = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", $list);
        sort($list);
        return $list;
    }

    /** the rows of a table, only the named columns */
    private static function cols(string $table, string ...$columns): array
    {
        return array_map(static fn(array $row): array => array_combine($columns, array_map(static fn(string $c): mixed => $row[$c] ?? null, $columns)), TableDb::rows($table));
    }

    /** [plan, importer, token] of the DB target, the S findings confirmed @return array{ImportPlan, Importer, string|null} */
    private function confirmed(ImportPlan $plan): array
    {
        $confirmation = new ImportConfirmation('preview-session');
        return [$plan, $this->importer($this->db(), $confirmation), $confirmation->token($plan, self::USER)];
    }

    // ---------------------------------------------------------------- the plan

    public function test_the_slice_is_mapped_to_the_existing_one(): void
    {
        $plan = $this->planInto();
        $s    = self::S;

        self::assertTrue($plan->importable());
        self::assertSame([self::SITE => self::id(1), self::SLICE => self::T, self::ALERTS => self::id(2)], $plan->map->modules(), 'no new id for the slice');
        self::assertSame([12 => 20], $plan->map->views(), 'the view named as the target\'s - the new one gets its number from the write');
        self::assertSame([5 => 21], $plan->map->emails());
        self::assertSame('utf-8', $plan->charsets[self::SLICE], 'the charset of the target slice');
        self::assertSame(['1a2b3c4d5e6f708192a3b4c5d6e7f801' => string2id('AA_Modulesettings_Site' . self::id(1))], $plan->settingsIds, 'the existing slice gets no settings object');
        self::assertSame(string2id('AA_Modulesettings_Slice' . self::T), $plan->map->longId(string2id('AA_Modulesettings_Slice' . self::SLICE)), 'references to the settings go to the existing ones');
        self::assertSame([self::SLICE], array_keys($plan->existing));
        self::assertSame('Existing', $plan->existing[self::SLICE]->name);
        self::assertSame([
            'I ' . ExistingSlices::EMAIL_EXISTS . " $s/email[5] 21",
            'I group-renamed constant-group[ct_rubriky] ct_rubriky => ct_rubriky_1',
            'I ' . ExistingSlices::INTO_EXISTING . " $s " . self::T,
            'I ' . ExistingSlices::VIEW_EXISTS . " $s/view[12] 20",
            'W ' . ExistingSlices::FIELD_DIFFERS . " $s/field[_email..........] name",
            'W ' . ExistingSlices::OBJECT_UNMATCHED . " $s/object[" . self::SET . '] AA_Set',
            'W ' . ExistingSlices::VIEW_DIFFERS . " $s/view[12] type, odd",
        ], self::codes($plan->findings));
    }

    public function test_the_package_to_write_has_only_what_the_slice_lacks(): void
    {
        $plan  = $this->planInto();
        $slice = array_values(array_filter($plan->package->modules, static fn(Module $m): bool => $m->id === self::SLICE))[0];

        self::assertSame(['category.......1'], array_column($slice->rows['field'], 'id'));
        self::assertSame(['13'], array_map('strval', array_column($slice->rows['view'], 'id')));
        self::assertArrayNotHasKey('email', $slice->rows);
        foreach (['module', 'slice', 'profile'] as $element) {
            self::assertArrayNotHasKey($element, $slice->rows, "the $element row stays as the target has it");
        }
        self::assertNull($slice->settings);
        self::assertSame([self::SET], array_column($slice->objects, 'id'));
    }

    public function test_the_existing_slice_gets_only_what_it_lacks(): void
    {
        $report = $this->import($this->planInto(), $this->importer($this->db()));
        [$t, $w, $a] = [self::T, self::id(1), self::id(2)];

        self::assertSame([self::FS, self::FW, self::FA, $t, $w, $a], array_column(TableDb::rows('module'), 'id'), 'no module row for the slice');
        self::assertSame([['id' => $t, 'name' => 'Existing', 'vid' => 20]], array_values(array_filter(self::cols('slice', 'id', 'name', 'vid'), static fn(array $r): bool => $r['id'] === $t)));
        self::assertSame([
            ['id' => 'headline........', 'name' => 'Titulek', 'input_show_func' => 'fld:'],
            ['id' => '_email..........', 'name' => 'Mail', 'input_show_func' => null],
            ['id' => 'category.......1', 'name' => 'Rubrika', 'input_show_func' => 'sel:ct_rubriky_1:'],
        ], array_map(static fn(array $r): array => array_diff_key($r, ['slice_id' => 1]), array_values(array_filter(self::cols('field', 'id', 'slice_id', 'name', 'input_show_func'), static fn(array $r): bool => $r['slice_id'] === $t))));
        self::assertSame([
            ['id' => 20, 'name' => 'Jiné jméno', 'odd' => 'mine'],
            ['id' => 1000, 'name' => 'Detail', 'odd' => '{constant:ct_rubriky_1:{category.......1}:name}'],
        ], array_map(static fn(array $r): array => array_diff_key($r, ['slice_id' => 1]), array_values(array_filter(self::cols('view', 'id', 'slice_id', 'name', 'odd'), static fn(array $r): bool => $r['slice_id'] === $t))));
        self::assertSame([['id' => 21, 'owner_module_id' => $t, 'body' => 'mine']], array_values(array_filter(self::cols('email', 'id', 'owner_module_id', 'body'), static fn(array $r): bool => $r['owner_module_id'] === $t)));
        self::assertSame([12 => 20, 13 => 1000], $report->maps['views']);
        self::assertSame([5 => 21, 7 => 2000], $report->maps['emails']);

        // the references of the new rows point at the existing slice and its view
        self::assertStringContainsString('{view:20::listlen-5}', (string) TableDb::rows('site_spot')[1]['content']);
        self::assertSame(['id' => 'K0001', 'slice_id' => $t], self::cols('alerts_collection', 'id', 'slice_id')[1]);
        self::assertSame(20, array_values(array_filter(TableDb::rows('alerts_filter'), static fn(array $r): bool => $r['id'] === 3000))[0]['vid']);
        self::assertSame([['source_id' => $w, 'destination_id' => $t, 'flag' => 1]], array_values(array_filter(TableDb::rows('relation'), static fn(array $r): bool => $r['source_id'] === $w)));
        self::assertSame([['group_id' => 'ct_rubriky_1', 'slice_id' => $t]], array_values(array_filter(self::cols('constant_slice', 'group_id', 'slice_id'), static fn(array $r): bool => $r['group_id'] === 'ct_rubriky_1')));

        // no profile, no settings object; the set is new, owned by the existing slice
        self::assertCount(1, TableDb::rows('profile'), 'the foreign one only');
        $types = array_column(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['property'] === 'aa_type'), 'value', 'object_id');
        self::assertNotContains('AA_Modulesettings_Slice', $types);
        self::assertSame($t, array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['object_id'] === self::id(4)) && ($r['property'] === 'aa_owner')))[0]['value']);

        self::assertSame(['type' => Module::SLICE, 'name' => 'Existing', 'old' => self::SLICE, 'new' => $t, 'existing' => true], $report->modules[1]);
        self::assertSame(['type' => Module::SITE, 'name' => 'Central Test - web', 'old' => self::SITE, 'new' => $w, 'existing' => false], $report->modules[0]);
    }

    public function test_the_rights_are_given_on_the_new_modules_only(): void
    {
        $this->import($this->planInto(), $this->importer($this->db()));
        [$t, $w, $a] = [self::T, self::id(1), self::id(2)];

        self::assertSame([
            "logInstall Central Test $w,$t,$a",
            'permissions ' . $w . ' ' . self::USER,
            'permissions ' . $a . ' ' . self::USER,
            'constantsChanged',
            "invalidatePagecache $w",
            "invalidatePagecache $t",
            "invalidatePagecache $a",
        ], $this->after->calls);
    }

    public function test_the_existing_slice_gone_since_the_plan_stops_the_write(): void
    {
        $plan = $this->planInto();
        TableDb::$tables['slice'] = array_values(array_filter(TableDb::$tables['slice'], static fn(array $r): bool => $r['id'] !== self::T));
        $before = self::tables();

        try {
            $this->import($plan, $this->importer($this->db()));
            self::fail('written into a slice that is gone');
        } catch (ImportFailed $e) {
            self::assertSame(['gone', self::S], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        }
        self::assertSame($before, self::tables());
    }

    public function test_a_group_only_kept_rows_use_is_left_out(): void
    {
        TableDb::$tables['field'][] = ['id' => 'category.......1', 'slice_id' => self::T, 'type' => 'category', 'name' => 'Rubrika', 'input_show_func' => 'fld:', 'text_stored' => 1];
        TableDb::$tables['view'][]  = ['id' => 22, 'slice_id' => self::T, 'name' => 'Detail'];

        $plan = $this->planInto();

        self::assertSame([], $plan->package->constantGroups);
        self::assertSame([], $plan->map->groups());
        self::assertSame([], array_intersect([self::C1, self::C2], array_keys($plan->map->longIds())), 'its constants get no ids');
        self::assertSame(['I ' . ExistingSlices::GROUP_LEFT_OUT . ' constant-group[ct_rubriky] ct_rubriky'], self::codes($plan->findings, 'group'));
        self::assertContains('I ' . ExistingSlices::VIEW_EXISTS . ' ' . self::S . '/view[13] 22', self::codes($plan->findings, ExistingSlices::VIEW_EXISTS));

        $this->import($plan, $this->importer($this->db()));
        self::assertSame([], array_filter(TableDb::rows('constant'), static fn(array $r): bool => str_starts_with((string) $r['group_id'], 'ct_rubriky')));
    }

    public function test_the_group_of_a_kept_field_is_the_one_the_slice_names(): void
    {
        TableDb::$tables['field'][] = ['id' => 'category.......1', 'slice_id' => self::T, 'type' => 'category', 'name' => 'Rubrika', 'input_show_func' => 'sel:rubriky:', 'text_stored' => 1, 'input_insert_func' => ''];
        TableDb::$tables['constant'][] = ['id' => str_repeat('6', 32), 'group_id' => 'lt_groupNames', 'name' => 'rubriky', 'value' => 'rubriky'];

        $plan = $this->planInto();

        self::assertSame(['ct_rubriky' => 'rubriky'], $plan->map->groups(), 'not a renamed copy');
        self::assertSame([], $plan->package->constantGroups);
        self::assertSame(['I ' . ExistingSlices::GROUP_KEPT . ' constant-group[ct_rubriky] ct_rubriky => rubriky'], self::codes($plan->findings, 'group'));
        self::assertNotContains('W ' . ExistingSlices::FIELD_DIFFERS . ' ' . self::S . '/field[category.......1] input_show_func', self::codes($plan->findings), 'the field names its group');

        $this->import($plan, $this->importer($this->db()));
        self::assertSame('{constant:rubriky:{category.......1}:name}', array_values(array_filter(TableDb::rows('view'), static fn(array $r): bool => $r['name'] === 'Detail'))[0]['odd']);
        self::assertSame([], array_values(array_filter(TableDb::rows('constant'), static fn(array $r): bool => in_array($r['group_id'], ['ct_rubriky', 'ct_rubriky_1'], true) || ($r['value'] === 'ct_rubriky_1'))));
    }

    public function test_a_group_the_target_does_not_have_is_not_mapped(): void
    {
        TableDb::$tables['field'][] = ['id' => 'category.......1', 'slice_id' => self::T, 'type' => 'category', 'name' => 'Rubrika', 'input_show_func' => 'sel:missing:', 'text_stored' => 1, 'input_insert_func' => ''];

        $plan = $this->planInto();

        self::assertSame(['ct_rubriky' => 'ct_rubriky_1'], $plan->map->groups(), 'written as without the slice - no second package group can take the name');
        self::assertSame(['ct_rubriky'], array_map(static fn($g): string => $g->name, $plan->package->constantGroups));
        self::assertSame([], self::codes($plan->findings, ExistingSlices::GROUP_KEPT));
    }

    public function test_a_kept_group_gone_since_the_plan_stops_the_write(): void
    {
        TableDb::$tables['field'][] = ['id' => 'category.......1', 'slice_id' => self::T, 'type' => 'category', 'name' => 'Rubrika', 'input_show_func' => 'sel:rubriky:', 'text_stored' => 1, 'input_insert_func' => ''];
        TableDb::$tables['constant'][] = ['id' => str_repeat('6', 32), 'group_id' => 'lt_groupNames', 'name' => 'rubriky', 'value' => 'rubriky'];
        $plan = $this->planInto();
        array_pop(TableDb::$tables['constant']);

        try {
            $this->import($plan, $this->importer($this->db()));
            self::fail('references mapped to a group that is gone');
        } catch (ImportFailed $e) {
            self::assertSame(['gone', 'constant-group[ct_rubriky]'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        }
    }

    public function test_a_group_the_package_does_not_carry_is_redirected_with_a_note(): void
    {
        TableDb::$tables['field'][] = ['id' => 'category.......1', 'slice_id' => self::T, 'type' => 'category', 'name' => 'Rubrika', 'input_show_func' => 'sel:rubriky:', 'text_stored' => 1, 'input_insert_func' => ''];
        TableDb::$tables['constant'][] = ['id' => str_repeat('6', 32), 'group_id' => 'lt_groupNames', 'name' => 'rubriky', 'value' => 'rubriky'];
        $package = self::with(self::example(), ['constantGroups' => []]);          // shared on the target, not carried

        $plan = $this->planInto($package);

        self::assertSame(['I ' . ExistingSlices::GROUP_REDIRECTED . ' constant-group[ct_rubriky] ct_rubriky => rubriky'], self::codes($plan->findings, ExistingSlices::GROUP_REDIRECTED));
        $this->import($plan, $this->importer($this->db()));
        self::assertSame('{constant:rubriky:{category.......1}:name}', array_values(array_filter(TableDb::rows('view'), static fn(array $r): bool => $r['name'] === 'Detail'))[0]['odd']);
    }

    public function test_a_new_slice_of_the_package_points_at_the_existing_slice(): void
    {
        $second  = str_repeat('3', 32);
        $package = self::with(self::example(), ['modules' => [...self::example()->modules, new Module(Module::SLICE, $second, [
            'module' => [['name' => 'Druhá', 'lang_file' => 'cz-utf8_news_lang.php']],
            'slice'  => [['name' => 'Druhá']],
            'field'  => [['id' => 'relation.......1', 'type' => 'relation', 'name' => 'Článek', 'input_show_func' => 'mse:#sLiCe-' . self::SLICE . ':']],
            'view'   => [['id' => 30, 'name' => 'Vazby', 'type' => 'digest', 'cond1field' => 'relation.......1', 'cond1cond' => self::SLICE, 'odd' => '{ids:' . self::SLICE . '}']],
        ])]]);

        $this->import($this->planInto($package), $this->importer($this->db()));

        $new = self::id(3);
        self::assertSame([['id' => 'relation.......1', 'slice_id' => $new, 'input_show_func' => 'mse:#sLiCe-' . self::T . ':']], array_values(array_filter(self::cols('field', 'id', 'slice_id', 'input_show_func'), static fn(array $r): bool => $r['slice_id'] === $new)));
        self::assertSame([['cond1cond' => self::T, 'odd' => '{ids:' . self::T . '}']], array_values(array_map(static fn(array $r): array => ['cond1cond' => $r['cond1cond'], 'odd' => $r['odd']], array_filter(TableDb::rows('view'), static fn(array $r): bool => $r['slice_id'] === $new))));
    }

    public function test_a_value_the_slice_charset_cannot_read_is_compared_as_bytes(): void
    {
        foreach (TableDb::$tables['field'] as $k => $row) {
            if (($row['slice_id'] === self::T) && ($row['id'] === 'headline........')) {
                TableDb::$tables['field'][$k]['name'] = "Titulek \xFF";
            }
        }
        $same  = self::cell(self::example(), self::SLICE, 'field', 0, ['name' => new RawBytes("Titulek \xFF")]);
        $other = self::cell(self::example(), self::SLICE, 'field', 0, ['name' => 'Titulek ÿ']);

        self::assertInstanceOf(RawBytes::class, (new DbImportTarget())->existingSlice(self::T)->fields['headline........']['name']);
        self::assertNotContains('W ' . ExistingSlices::FIELD_DIFFERS . ' ' . self::S . '/field[headline........] name', self::codes($this->planInto($same)->findings));
        self::assertContains('W ' . ExistingSlices::FIELD_DIFFERS . ' ' . self::S . '/field[headline........] name', self::codes($this->planInto($other)->findings));
    }

    public function test_objects_are_matched_by_class_and_name(): void
    {
        $package = self::object(self::example(), self::SLICE, 0, static fn(array $o): array => ['name' => 'Zprávy'] + $o);
        TableDb::$tables['object_text'][] = ['id' => 50, 'object_id' => str_repeat('9', 32), 'property' => 'aa_type', 'priority' => 0, 'value' => 'AA_Set'];
        TableDb::$tables['object_text'][] = ['id' => 51, 'object_id' => str_repeat('9', 32), 'property' => 'aa_owner', 'priority' => 0, 'value' => self::T];
        TableDb::$tables['object_text'][] = ['id' => 52, 'object_id' => str_repeat('9', 32), 'property' => 'aa_name', 'priority' => 0, 'value' => 'Zprávy'];

        $plan = $this->planInto($package);

        self::assertSame(['I ' . ExistingSlices::OBJECT_EXISTS . ' ' . self::S . '/object[' . self::SET . '] ' . str_repeat('9', 32)], self::codes($plan->findings, 'object'));
        self::assertSame(str_repeat('9', 32), $plan->map->longId(self::SET), '{set:<id>} goes to the existing set');

        $rows = count(TableDb::rows('object_text'));
        $this->import($plan, $this->importer($this->db()));
        self::assertSame([], array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['property'] === 'aa_type') && ($r['value'] === 'AA_Set') && ($r['object_id'] !== self::FSET) && ($r['object_id'] !== str_repeat('9', 32))), 'no second set');
        self::assertGreaterThan($rows, count(TableDb::rows('object_text')), 'the site objects are written');
    }

    public function test_an_alias_function_is_matched_by_its_alias(): void
    {
        $target = new FakeImportTarget();
        $target->slices[self::T] = new ExistingSlice(self::T, 'Existing', 'S', 'cz-utf8_news_lang.php', 'utf-8', [], [], [], [str_repeat('9', 32) => ['AA_Aliasfunc', 'Hlavicka']]);
        $package = self::module(self::example(), self::SLICE, static fn(Module $m): Module => self::with($m, ['objects' => [
            ['id' => self::ALIAS, 'class' => 'AA_Aliasfunc', 'props' => ['alias' => 'Hlavicka', 'code' => 'x']],
        ]]));
        $package = self::module($package, self::SITE, static fn(Module $m): Module => self::with($m, ['objects' => []]));

        $plan = (new Planner($target))->plan($package, (new Validator())->validate($package), false, [], [self::SLICE => self::T]);

        self::assertSame(str_repeat('9', 32), $plan->map->longId(self::ALIAS));
    }

    public function test_repeated_or_empty_names_are_added_with_a_warning(): void
    {
        TableDb::$tables['view'][] = ['id' => 23, 'slice_id' => self::T, 'name' => 'Other', 'field3' => 'clanky'];
        TableDb::$tables['email'][] = ['id' => 24, 'owner_module_id' => self::T, 'description' => ''];
        $package = self::cell(self::example(), self::SLICE, 'email', 0, ['description' => '']);

        $plan = $this->planInto($package);

        self::assertSame([], $plan->map->views());
        self::assertSame([], $plan->map->emails());
        self::assertSame([
            'W ' . ExistingSlices::EMAIL_UNMATCHED . ' ' . self::S . '/email[5] ',
            'W ' . ExistingSlices::VIEW_UNMATCHED . ' ' . self::S . '/view[12] field3:clanky',
        ], [...self::codes($plan->findings, ExistingSlices::EMAIL_UNMATCHED), ...self::codes($plan->findings, ExistingSlices::VIEW_UNMATCHED)]);
    }

    // ---------------------------------------------------------------- the choice refused

    /** @return array<string, array{array<string, string>, string, bool}> into, finding, target editable */
    public static function refusedTargets(): array
    {
        $s = 'slice-module[0f1e2d3c4b5a69788796a5b4c3d2e1f0]';
        return [
            'no such slice'        => [[self::SLICE => str_repeat('4', 32)], "E target-slice $s " . str_repeat('4', 32) . ': no such slice', true],
            'a deleted slice'      => [[self::SLICE => self::FS], "E target-slice $s " . self::FS . ': no such slice', true],
            'not editable'         => [[self::SLICE => self::T], "E target-slice $s " . self::T . ': you may not edit it', false],
            'no slice of the package' => [[self::ALERTS => self::T], 'E target-slice alerts-module[' . self::ALERTS . '] ' . self::T . ': not a slice of the package', true],
        ];
    }

    /** @param array<string, string> $into */
    #[DataProvider('refusedTargets')]
    public function test_the_target_must_be_an_editable_slice(array $into, string $finding, bool $editable): void
    {
        [TableDb::$tables['module'][0]['deleted'], TableDb::$tables['slice'][0]['deleted']] = [0, 1];       // the foreign slice
        if (!$editable) {
            $this->target->notEditable[] = self::T;
        }

        $plan = $this->planInto(null, $into);

        self::assertFalse($plan->importable());
        self::assertSame([$finding], self::codes($plan->findings, 'target-slice'));
    }

    public function test_a_slice_chosen_twice_is_an_error(): void
    {
        $package = self::with(self::example(), ['modules' => [...self::example()->modules, new Module(Module::SLICE, str_repeat('3', 32), ['module' => [['name' => 'Druhá', 'lang_file' => 'cz-utf8_news_lang.php']], 'slice' => [['name' => 'Druhá']]])]]);

        $plan = $this->planInto($package, [self::SLICE => self::T, str_repeat('3', 32) => self::T]);

        self::assertSame(['E target-slice slice-module[' . str_repeat('3', 32) . '] ' . self::T . ': chosen twice'], self::codes($plan->findings, 'target-slice'));
    }

    public function test_a_reader_slice_goes_only_into_a_reader_slice(): void
    {
        $package = self::cell(self::example(), self::SLICE, 'slice', 0, ['type' => ItemLint::READER_SLICE]);

        $plan = $this->planInto($package);

        self::assertSame(['E ' . ExistingSlices::SLICE_TYPE . ' ' . self::S . ' ReaderManagement => S'], self::codes($plan->findings, ExistingSlices::SLICE_TYPE));
    }

    public function test_a_transfer_into_its_own_source_on_the_same_aa_is_an_error(): void
    {
        TableDb::$tables['module'][] = ['id' => self::SLICE, 'type' => 'S', 'name' => 'Source', 'lang_file' => 'cz-utf8_news_lang.php', 'deleted' => 0];
        TableDb::$tables['slice'][]  = ['id' => self::SLICE, 'name' => 'Source', 'type' => 'S', 'deleted' => 0];

        $transfer = $this->planInto(null, [self::SLICE => self::SLICE], true, true);
        $install  = $this->planInto(null, [self::SLICE => self::SLICE], false, true);
        $remote   = $this->planInto(null, [self::SLICE => self::SLICE], true, false);

        self::assertSame(['E ' . ExistingSlices::INTO_SOURCE . ' ' . self::S . ' ' . self::SLICE], self::codes($transfer->findings, ExistingSlices::INTO_SOURCE), 'its items would be copied into it');
        self::assertSame([], self::codes($install->findings, ExistingSlices::INTO_SOURCE), 'another site using the slice');
        self::assertSame([], self::codes($remote->findings, ExistingSlices::INTO_SOURCE), 'another AA: a slice of the same id is no source');
    }

    public function test_a_same_aa_transfer_into_another_slice_of_the_package_is_an_error(): void
    {
        $b = str_repeat('3', 32);
        TableDb::$tables['module'][] = ['id' => $b, 'type' => 'S', 'name' => 'B', 'lang_file' => 'cz-utf8_news_lang.php', 'deleted' => 0];
        TableDb::$tables['slice'][]  = ['id' => $b, 'name' => 'B', 'type' => 'S', 'deleted' => 0];
        $package = self::with(self::example(), ['modules' => [...self::example()->modules, new Module(Module::SLICE, $b, ['module' => [['name' => 'B', 'lang_file' => 'cz-utf8_news_lang.php']], 'slice' => [['name' => 'B']]])]]);

        $plan = $this->planInto($package, [self::SLICE => $b], true, true);

        self::assertSame(['E ' . ExistingSlices::INTO_SOURCE . ' ' . self::S . " $b"], self::codes($plan->findings, ExistingSlices::INTO_SOURCE), 'B is copied from itself in the same run');
    }

    /** @return array<string, array{array<string, mixed>, bool, string}> the package's headline carries no text_stored nor input_insert_func: their defaults count */
    public static function fieldTypes(): array
    {
        return [
            'text stored, install'  => [['text_stored' => 0], false, 'W ' . ExistingSlices::FIELD_DIFFERS],
            'text stored, transfer' => [['text_stored' => 0], true, 'E ' . ExistingSlices::FIELD_TYPE],
            'password, transfer'    => [['input_insert_func' => 'pwd:'], true, 'E ' . ExistingSlices::FIELD_TYPE],
            'a name, transfer'      => [['name' => 'Nadpis'], true, 'W ' . ExistingSlices::FIELD_DIFFERS],
        ];
    }

    /** @param array<string, mixed> $target the target's headline changed */
    #[DataProvider('fieldTypes')]
    public function test_a_field_stored_otherwise_breaks_the_items_of_a_transfer(array $target, bool $transfer, string $finding): void
    {
        foreach (TableDb::$tables['field'] as $k => $row) {
            if (($row['slice_id'] === self::T) && ($row['id'] === 'headline........')) {
                TableDb::$tables['field'][$k] = $target + $row;
            }
        }

        $plan = $this->planInto(null, [self::SLICE => self::T], $transfer);

        self::assertContains($finding . ' ' . self::S . '/field[headline........] ' . implode(', ', array_keys($target)), self::codes($plan->findings, 'field-'));
    }

    // ---------------------------------------------------------------- a transfer

    public function test_the_state_of_the_source_slice_stays_out(): void
    {
        $package = self::module(self::example(), self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => ['owner' => ['name' => 'O', 'email' => 'o@example.org'], 'reading-password' => 'secret']]));
        $run     = $this->newRun(InstallRun::TRANSFER);

        $plan = $this->planInto($package, [self::SLICE => self::T], true);
        $this->import(...$this->confirmed($plan), run: $run);

        self::assertSame(['I ' . ExistingSlices::STATE_KEPT . ' ' . self::S . ' owner, reading-password'], self::codes($plan->findings, ExistingSlices::STATE_KEPT));
        self::assertSame([], TableDb::rows('slice_owner'));
        self::assertNull(array_values(array_filter(TableDb::rows('slice'), static fn(array $r): bool => $r['id'] === self::T))[0]['reading_password'] ?? null);
    }

    public function test_the_items_go_into_the_existing_slice(): void
    {
        $run = $this->newRun(InstallRun::TRANSFER);
        $this->import(...$this->confirmed($this->planInto(null, [self::SLICE => self::T], true)), run: $run);

        $result = $this->batch($run);

        self::assertSame(BatchResult::WRITTEN, $result->status, json_encode(self::codes($result->findings)));
        self::assertSame([str_repeat('7', 32), self::ITEM_A], array_values(array_column(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['slice_id'] === self::T), 'id')));
    }

    public function test_a_copy_in_the_same_aa_into_an_existing_slice_gets_new_item_ids(): void
    {
        $run = $this->newRun(InstallRun::TRANSFER, InstallRun::LOCAL);
        $this->import(...$this->confirmed($this->planInto(null, [self::SLICE => self::T], true, true)), run: $run);

        $this->batch($run);

        self::assertSame([str_repeat('7', 32), string2id($run . self::ITEM_A)], array_values(array_column(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['slice_id'] === self::T), 'id')));
    }

    /** the first and last batch of the slice: ITEM_A */
    private function batch(string $run): BatchResult
    {
        $item  = ['id' => self::ITEM_A, 'content' => ['status_code.....' => [0 => ['value' => '1', 'flag' => 0]], 'headline........' => [0 => ['value' => 'A', 'flag' => 0]]]];
        $batch = new Package('Články', self::example()->source, [new Module(Module::SLICE, self::SLICE, [], items: [$item])], withItems: true, batch: new Batch('', self::ITEM_A, true));
        return $this->importer()->writeBatch($run, $batch, [self::SLICE, '']);        // TableDb cannot run ItemLint's UNION
    }

    public function test_the_final_check_counts_the_written_items_of_an_existing_slice(): void
    {
        $run = $this->newRun(InstallRun::TRANSFER);
        DB_AA::transaction(static fn() => InstallRun::lockForUpdate($run)->previewed(hash('sha256', 'x'), self::NOW, false, [self::SLICE => self::T]));
        $this->import(...$this->confirmed($this->planInto(null, [self::SLICE => self::T], true)), run: $run);
        $this->batch($run);
        $source = new FakeSource([], [self::SLICE => [['id' => self::ITEM_A, 'last_edit' => self::NOW - 10, 'content' => ['status_code.....' => [['value' => '1', 'flag' => 0]]]]]]);

        $check = (new TransferCheck(new AppExport($source, [], static fn(): bool => true, true)))->check(InstallRun::get($run));

        self::assertSame([['source' => self::SLICE, 'target' => self::T, 'written' => 1, 'sourceCount' => 1, 'targetCount' => 2]], $check['slices']);
        self::assertSame([], $check['findings'], 'the items the slice had are no difference');
    }

    public function test_items_into_a_slice_with_unique_fields_are_a_warning(): void
    {
        TableDb::$tables['field'][] = ['id' => 'number.........1', 'slice_id' => self::T, 'type' => 'number', 'name' => 'Číslo', 'input_insert_func' => 'seq:', 'text_stored' => 0];
        TableDb::$tables['field'][] = ['id' => 'text...........1', 'slice_id' => self::T, 'type' => 'text', 'name' => 'Kód', 'input_validate' => 'e-unique:text...........1:1', 'text_stored' => 1];
        $package = self::rows(self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows, ['id' => 'seo.............', 'type' => 'seo', 'name' => 'SEO', 'input_insert_func' => 'seo:']]);

        $finding = 'W ' . ExistingSlices::UNIQUE_FIELD . ' ' . self::S . ' number.........1, text...........1, seo.............';

        self::assertSame([$finding], self::codes($this->planInto($package, [self::SLICE => self::T], true)->findings, ExistingSlices::UNIQUE_FIELD));
        self::assertSame([], self::codes($this->planInto($package)->findings, ExistingSlices::UNIQUE_FIELD), 'no items, nothing to collide');
    }

    public function test_rights_taken_since_the_plan_stop_the_write(): void
    {
        $plan = $this->planInto();
        $this->target->notEditable[] = self::T;
        $before = self::tables();

        try {
            $this->import($plan, $this->importer($this->db()));
            self::fail('written into a slice the user may not edit any more');
        } catch (ImportFailed $e) {
            self::assertSame(['denied', self::S], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        }
        self::assertSame($before, self::tables());
    }

    public function test_the_db_target_asks_the_rights_of_the_logged_in_user(): void
    {
        class_exists(\AA_Perm::class);
        $saved = [$GLOBALS['permission_uid'] ?? null, $GLOBALS['permission_to'] ?? null, $GLOBALS['auth'] ?? null, $GLOBALS['r_superuser'] ?? null];
        try {
            $GLOBALS['auth'] = new class {
                public function getUid(): string
                {
                    return 'u1';
                }
            };
            $may = static function (string $slice, bool $super = false): bool {
                [$GLOBALS['permission_uid'], $GLOBALS['r_superuser']] = ['u1', $super ? [AA_ID => true] : []];
                $GLOBALS['permission_to'] = ['aa' => [AA_ID => ''], 'slice' => [self::T => $slice]];
                return (new DbImportTarget())->mayEditSlice(self::T);
            };
            self::assertTrue($may(\PS_EDIT));
            self::assertFalse($may(\PS_EDIT_ALL_ITEMS), 'editing items is not enough');
            self::assertTrue($may('', true), 'a superadmin');
        } finally {
            [$GLOBALS['permission_uid'], $GLOBALS['permission_to'], $GLOBALS['auth'], $GLOBALS['r_superuser']] = $saved;
        }
    }

    public function test_a_write_form_of_other_targets_than_the_run_is_refused(): void
    {
        $id = InstallRun::preview(InstallRun::TRANSFER, false, '7', self::USER, '', self::NOW, hash('sha256', self::DEFINITIONS), [self::SLICE => self::T])->getId();
        DB_AA::transaction(static fn() => InstallRun::lockForUpdate($id)->previewed(hash('sha256', self::DEFINITIONS), self::NOW, false, []));
        $run = InstallRun::get($id);

        self::assertFalse($run->matchesPreview(self::DEFINITIONS, false, [self::SLICE => self::T]), 'the write form of the preview before');
        self::assertTrue($run->matchesPreview(self::DEFINITIONS, false, []));
    }

    public function test_a_kept_relation_to_another_slice_is_named(): void
    {
        TableDb::$tables['field'][] = ['id' => 'relation.......1', 'slice_id' => self::T, 'type' => 'relation', 'name' => 'Vazba', 'input_show_func' => 'mse:#sLiCe-' . self::FS . ':', 'text_stored' => 1, 'input_insert_func' => ''];
        $package = self::rows(self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows, ['id' => 'relation.......1', 'type' => 'relation', 'name' => 'Vazba', 'input_show_func' => 'mse:#sLiCe-' . self::SLICE . ':']]);
        $at      = self::S . '/field[relation.......1]';

        $transfer = self::codes($this->planInto($package, [self::SLICE => self::T], true)->findings);
        $install  = self::codes($this->planInto($package)->findings);

        self::assertContains('W ' . ExistingSlices::RELATION_TARGET . " $at the slice selects from " . self::FS . ', the items will point to ' . self::T, $transfer);
        self::assertNotContains('W ' . ExistingSlices::FIELD_DIFFERS . " $at input_show_func", $transfer, 'the specific warning replaces it');
        self::assertContains('W ' . ExistingSlices::FIELD_DIFFERS . " $at input_show_func", $install, 'no items come');
    }

    public function test_a_choice_shows_the_slice_with_its_short_id(): void
    {
        self::assertSame('Central Test - Articles (e0e0e0e0)', ExistingSlices::choiceLabel('Central Test - Articles', self::T));
    }

    public function test_the_run_tells_a_changed_choice_from_a_changed_source(): void
    {
        $id  = InstallRun::preview(InstallRun::INSTALL, false, InstallRun::LOCAL, self::USER, '', self::NOW, hash('sha256', self::DEFINITIONS), [self::SLICE => self::T])->getId();
        $run = InstallRun::get($id);

        self::assertTrue($run->chose([self::SLICE => self::T]));
        self::assertFalse($run->chose([]));
        self::assertFalse($run->chose([self::SLICE => self::FS]));
    }

    public function test_the_targets_of_the_form(): void
    {
        self::assertSame([self::SLICE => self::T], InstallRun::targetsFrom([
            strtoupper(self::SLICE) => strtoupper(self::T),
            str_repeat('3', 32)     => '',                    // a new slice
            'x'                     => self::T,
            str_repeat('4', 32)     => [self::T],
            str_repeat('5', 32)     => 'not an id',
        ]));
    }

    public function test_the_run_keeps_the_targets(): void
    {
        $run = InstallRun::preview(InstallRun::TRANSFER, false, InstallRun::LOCAL, self::USER, '', self::NOW, '', [self::SLICE => self::T]);

        self::assertSame([self::SLICE => self::T], InstallRun::get($run->getId())->targets());
        DB_AA::transaction(static fn() => InstallRun::lockForUpdate($run->getId())->previewed(hash('sha256', 'x'), self::NOW, true, []));
        self::assertSame([], InstallRun::get($run->getId())->targets());
    }
}
