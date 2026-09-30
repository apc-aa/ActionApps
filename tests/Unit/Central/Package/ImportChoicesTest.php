<?php

declare(strict_types=1);

/**
 * ImportChoices: what the preview form sends, how the run keeps it, and what the planner makes of it -
 * names, slices left out with what named them, a new owner, settings values typed.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Planner;
use AA\Central\Package\ValueCodec;
use AA\Central\Package\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeImportTarget;
use Tests\Unit\Central\Package\Fixtures\PackageMutations;

require_once __DIR__ . '/fixtures/PackageMutations.php';

final class ImportChoicesTest extends TestCase
{
    use PackageMutations;

    private const OWNER = '0123456789abcdef0123456789abcdef';
    /** @var \WeakMap<ImportPlan, list<array{string, \AA\Central\Package\LintFinding}>> choose()'s findings of each plan() */
    private static \WeakMap $chosen;

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
        self::$chosen = new \WeakMap();
    }

    private static function plan(ImportChoices $choices, ?Package $package = null, bool $transfer = false): ImportPlan
    {
        $target         = new FakeImportTarget();
        $target->owners = [self::OWNER => 'Ecn'];
        $planner        = new Planner($target);
        [$package, $chosen] = $planner->choose($package ?? self::example(), $choices);
        $validation     = (new Validator())->validate($package, $transfer, array_map('array_keys', $choices->settings));
        self::assertTrue($validation->importable(), json_encode(array_map(static fn(array $f): string => "{$f[1]->code} $f[0]", [...$chosen, ...$validation->findings])));
        $plan           = $planner->plan($package, $validation, $transfer, [], [], false, $transfer ? '' : self::OWNER, $choices);
        self::$chosen[$plan] = $chosen;
        return $plan;
    }

    /** @return list<string> "<level> <code> <path>" of choose(), the validation and the planner */
    private static function findings(ImportPlan $plan): array
    {
        return array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0]", [...self::$chosen[$plan], ...$plan->allFindings()]);
    }

    /** the example's slice with one more settings field @param array<string, string> $columns */
    private static function withField(string $id, array $columns): Package
    {
        return self::rows(self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows, ['id' => $id, 'type' => 'text', 'name' => "Name of $id"] + $columns]);
    }

    private static function planned(ImportPlan $plan, string $id): ?Module
    {
        foreach ($plan->package->modules as $m) {
            if ($m->id === $id) {
                return $m;
            }
        }
        return null;
    }

    public function test_the_form_is_read_as_the_run_keeps_it(): void
    {
        $choices = ImportChoices::fromPost([
            'name'            => [strtoupper(self::SLICE) => '  News  ', self::SITE => '   ', 'x' => 'no id'],
            // the hidden field says "left out", a checked box after it says "keep"
            'skip'            => [self::SLICE => '', self::ALERTS => '1'],
            'owner'           => self::OWNER,
            'setting'         => [self::SLICE => ['_email..........' => 'a@b.cz', '_empty..........' => ' ', 'headline........' => 'no setting']],
        ]);

        self::assertSame([self::SLICE => 'News'], $choices->names);
        self::assertSame([self::ALERTS], $choices->skip);
        self::assertSame(self::OWNER, $choices->owner);
        self::assertNull($choices->newOwner);
        self::assertSame([self::SLICE => ['_email..........' => 'a@b.cz']], $choices->settings);
        self::assertTrue(ImportChoices::fromJson($choices->toJson())->equals($choices));
        self::assertSame('', (new ImportChoices())->owner);
    }

    public function test_a_new_owner_wins_over_the_list(): void
    {
        $choices = ImportChoices::fromPost(['owner' => self::OWNER, 'new_owner' => ' Ecn ', 'new_owner_email' => 'ecn@ecn.cz']);

        self::assertSame(['', ['name' => 'Ecn', 'email' => 'ecn@ecn.cz']], [$choices->owner, $choices->newOwner]);
    }

    public function test_an_id_of_no_shape_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImportChoices(['X' => 'a']);
    }

    public function test_the_names_go_to_the_module_and_slice_rows(): void
    {
        $plan = self::plan(new ImportChoices([self::SLICE => 'News', self::SITE => 'Web']));

        self::assertSame('News', ValueCodec::bytes(self::planned($plan, self::SLICE)->rows['module'][0]['name']));
        self::assertSame('News', ValueCodec::bytes(self::planned($plan, self::SLICE)->rows['slice'][0]['name']));
        self::assertSame('Web', ValueCodec::bytes(self::planned($plan, self::SITE)->rows['module'][0]['name']));
    }

    public function test_a_slice_left_out_takes_what_names_it_along(): void
    {
        $plan = self::plan(new ImportChoices([], [self::SLICE]));

        self::assertNull(self::planned($plan, self::SLICE));
        self::assertSame([], self::planned($plan, self::SITE)->rows['uses'] ?? [], 'the site no longer uses it');
        self::assertSame('', ValueCodec::bytes(self::planned($plan, self::ALERTS)->rows['alerts_collection'][0]['slice_id']), 'the Alerts stay, without readers');
        self::assertContains('W reader-left-out ' . self::A . '/alerts_collection/slice_id', self::findings($plan));
        self::assertContains('W reader-left-out ' . self::A . '/alerts_filter[3]/vid', self::findings($plan), 'a filter by a view of it');
        self::assertSame([], $plan->package->constantGroups, 'a group only it used goes with it');
        self::assertTrue($plan->importable(), implode("\n", self::findings($plan)));
    }

    public function test_a_new_owner_needs_a_name_and_an_e_mail(): void
    {
        $bad  = self::plan(new ImportChoices(newOwner: ['name' => 'Ecn', 'email' => 'no mail']));
        $good = self::plan(new ImportChoices(newOwner: ['name' => 'Ecn', 'email' => 'ecn@ecn.cz']));

        self::assertContains('E owner-invalid target', self::findings($bad));
        self::assertContains('E owner-invalid target', self::findings(self::plan(new ImportChoices(newOwner: ['name' => str_repeat('x', 81), 'email' => 'ecn@ecn.cz']))), 'slice_owner.name is char(80)');
        self::assertSame(['', ['name' => 'Ecn', 'email' => 'ecn@ecn.cz']], [$good->owner, $good->newOwner]);
        self::assertTrue($good->importable());
    }

    public function test_a_typed_setting_is_written_and_linted(): void
    {
        $plan = self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => '{include:/etc/passwd}']]));

        self::assertSame('{include:/etc/passwd}', $plan->settingDefaults[self::SLICE]['_email..........'][0]['value']);
        self::assertContains('S include ' . self::S . '/setting-values/_email..........[0]', self::findings($plan));
        self::assertSame([], $plan->settingsToFill(self::planned($plan, self::SLICE) ?? throw new \LogicException()), 'a typed value fills it');
    }

    public function test_a_typed_setting_is_checked_as_the_values_of_the_source(): void
    {
        $lang = self::lang(self::example(), self::SLICE, 'cz_news_lang.php');

        self::assertContains('E charset ' . self::S . '/setting-values/_email..........[0]', self::findings(self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => 'Иван']]), $lang)));
        self::assertNotContains('E charset ' . self::S . '/setting-values/_email..........[0]', self::findings(self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => 'Příliš']]), $lang)));
    }

    public function test_the_settings_of_the_source_are_written_and_a_typed_one_wins(): void
    {
        $values  = ['_email..........' => [['value' => 'redakce@example.org', 'flag' => 64]]];
        $package = self::module2(self::example(), self::SLICE, ['setting-values' => $values]);

        $kept  = self::plan(new ImportChoices(), $package);
        $typed = self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]), $package);

        self::assertSame('redakce@example.org', $kept->settingDefaults[self::SLICE]['_email..........'][0]['value'], 'written as the source has it');
        self::assertSame([], self::planned($kept, self::SLICE)->transfer, 'the writer takes the defaults - an install carries no transfer part');
        self::assertSame('jiny@example.org', $typed->settingDefaults[self::SLICE]['_email..........'][0]['value']);
    }

    public function test_a_typed_setting_replaces_the_value_of_the_source_in_the_checks(): void
    {
        $values  = ['_email..........' => [['value' => '{include:http://example.org/x}', 'flag' => 64]]];
        $package = self::module2(self::example(), self::SLICE, ['setting-values' => $values]);

        $kept  = self::plan(new ImportChoices(), $package);
        $typed = self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]), $package);

        self::assertContains('S include ' . self::S . '/setting-values/_email..........[0]', self::findings($kept));
        self::assertNotContains('S include ' . self::S . '/setting-values/_email..........[0]', self::findings($typed), 'the value of the source is not written - nothing to confirm');
        self::assertSame([], $typed->ofLevel(\AA\Central\Package\LintFinding::SECURITY));
    }

    public function test_a_transfer_writes_a_typed_setting_over_the_value_of_the_source(): void
    {
        $package = self::module2(self::example(), self::SLICE, ['setting-values' => ['_email..........' => [['value' => 'redakce@example.org', 'flag' => 64]]]]);

        $plan = self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]), $package, true);

        self::assertSame('jiny@example.org', self::planned($plan, self::SLICE)->transfer['setting-values']['_email..........'][0]['value']);
        self::assertSame([], $plan->settingDefaults, 'a transfer writes no defaults');
    }

    public function test_an_app_of_the_store_takes_typed_settings(): void
    {
        $published = self::with(self::example(), ['published' => true]);

        $plan = self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]), $published);

        self::assertSame('jiny@example.org', $plan->settingDefaults[self::SLICE]['_email..........'][0]['value']);
    }

    public function test_an_app_of_the_store_brings_no_setting_values(): void
    {
        $published = self::with(self::module2(self::example(), self::SLICE, ['setting-values' => ['_email..........' => [['value' => 'from the store', 'flag' => 64]]]]), ['published' => true]);
        $choices   = new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]);
        [$chosen]  = (new Planner(new FakeImportTarget()))->choose($published, $choices);

        self::assertTrue((new Validator())->validate($chosen, false, array_map('array_keys', $choices->settings))->importable(), 'a typed value replaces it');
        $untyped = (new Validator())->validate($published, false, []);
        self::assertContains('E items-published meta/publish', array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0]", $untyped->findings));
    }

    public function test_a_typed_number_is_a_whole_number(): void
    {
        $package = self::withField('_count..........', ['text_stored' => '0']);
        $number  = static fn(string $typed): ImportPlan => self::plan(new ImportChoices(settings: [self::SLICE => ['_count..........' => $typed]]), $package);

        foreach (['2.5', '1e30', 'x', '99999999999999999999', '0x1A'] as $typed) {
            self::assertContains('E setting-not-number ' . self::S . '/setting-values/_count..........[0]', self::findings($number($typed)), $typed);
        }
        self::assertSame([['value' => '42', 'flag' => 0]], $number(' 42 ')->settingDefaults[self::SLICE]['_count..........']);
        self::assertSame([['value' => '-7', 'flag' => 0]], $number('-007')->settingDefaults[self::SLICE]['_count..........']);
        self::assertSame((string) PHP_INT_MAX, $number((string) PHP_INT_MAX)->settingDefaults[self::SLICE]['_count..........'][0]['value'], 'bigint');
    }

    public function test_a_number_or_bool_check_takes_the_typed_value(): void
    {
        $package = self::withField('_count..........', ['text_stored' => '0', 'input_validate' => 'number']);
        $package = self::rows($package, self::SLICE, 'field', static fn(array $rows): array => [...$rows, ['id' => '_show...........', 'type' => 'text', 'name' => 'Show', 'input_insert_func' => 'boo', 'input_validate' => 'bool']]);
        $plan    = self::plan(new ImportChoices(settings: [self::SLICE => ['_count..........' => '5', '_show...........' => '1']]), $package);

        self::assertSame('5', $plan->settingDefaults[self::SLICE]['_count..........'][0]['value']);
        self::assertSame('1', $plan->settingDefaults[self::SLICE]['_show...........'][0]['value']);
    }

    public function test_a_typed_value_passes_the_check_of_its_field(): void
    {
        foreach (['email', 'e-mail'] as $validate) {    // e-mail: the item form's alias of email
            $package = self::cell(self::example(), self::SLICE, 'field', self::fieldIndex('_email..........'), ['input_validate' => $validate]);
            $email   = static fn(string $typed): ImportPlan => self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => $typed]]), $package);

            self::assertContains('E ' . Planner::SETTING_INVALID . ' ' . self::S . '/setting-values/_email..........[0]', self::findings($email('no e-mail')), $validate);
            self::assertSame('jiny@example.org', $email('jiny@example.org')->settingDefaults[self::SLICE]['_email..........'][0]['value']);
        }
    }

    public function test_an_app_of_the_store_brings_no_translation_of_a_typed_setting(): void
    {
        $values    = ['_email..........' => [0 => ['value' => 'cs@example.org', 'flag' => 64], 1000001 => ['value' => 'from the store', 'flag' => 64]]];
        $published = self::with(self::module2(self::example(), self::SLICE, ['setting-values' => $values]), ['published' => true]);
        $choices   = new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]);
        [$chosen]  = (new Planner(new FakeImportTarget()))->choose($published, $choices);

        $validation = (new Validator())->validate($chosen, false, array_map('array_keys', $choices->settings));
        self::assertContains('E items-published meta/publish', array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0]", $validation->findings));
    }

    public function test_a_typed_setting_keeps_the_translations_of_the_source(): void
    {
        $values  = ['_email..........' => [0 => ['value' => 'cs@example.org', 'flag' => 64], 1000001 => ['value' => 'en@example.org', 'flag' => 64]]];
        $package = self::module2(self::example(), self::SLICE, ['setting-values' => $values]);

        $plan = self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]), $package);

        self::assertSame([0 => ['value' => 'jiny@example.org', 'flag' => 64], 1000001 => ['value' => 'en@example.org', 'flag' => 64]], $plan->settingDefaults[self::SLICE]['_email..........']);
    }

    /** the position of a field row of the example's slice */
    private static function fieldIndex(string $id): int
    {
        foreach (self::example()->modules as $m) {
            foreach ($m->id === self::SLICE ? ($m->rows['field'] ?? []) : [] as $i => $row) {
                if (ValueCodec::bytes($row['id'] ?? '') === $id) {
                    return $i;
                }
            }
        }
        throw new \LogicException("No field $id");
    }

    public function test_a_typed_value_is_stored_as_the_insert_function_of_the_field_stores_it(): void
    {
        $package = self::rows(self::withField('_flag...........', ['input_insert_func' => 'boo:']), self::SLICE, 'field', static fn(array $rows): array => [...$rows, ['id' => '_pass...........', 'type' => 'text', 'name' => 'Password', 'input_insert_func' => 'pwd:']]);
        $plan    = static fn(array $typed): ImportPlan => self::plan(new ImportChoices(settings: [self::SLICE => $typed]), $package);

        self::assertSame('1', $plan(['_flag...........' => '1'])->settingDefaults[self::SLICE]['_flag...........'][0]['value'], 'boo stores 0 or 1');
        self::assertSame('0', $plan(['_flag...........' => '0 '])->settingDefaults[self::SLICE]['_flag...........'][0]['value']);
        self::assertContains('E ' . Planner::SETTING_INVALID . ' ' . self::S . '/setting-values/_flag...........[0]', self::findings($plan(['_flag...........' => 'no'])), 'PHP would take "no" for true');
        $refused = $plan(['_pass...........' => 'my secret']);
        self::assertContains('E ' . Planner::SETTING_INSERT . ' ' . self::S . '/setting-values/_pass...........[0]', self::findings($refused), 'a password would be stored unhashed');
        self::assertArrayNotHasKey('_pass...........', $refused->settingDefaults[self::SLICE] ?? []);
        foreach ([...self::$chosen[$refused], ...$refused->allFindings()] as [, $f]) {
            self::assertStringNotContainsString('my secret', $f->ref, 'a finding never quotes a value of a password field');
        }
    }

    public function test_a_name_the_form_only_sends_back_renames_nothing(): void
    {
        $package = self::cell(self::cell(self::example(), self::SLICE, 'module', 0, ['name' => 'News ']), self::SLICE, 'slice', 0, ['name' => 'News archive']);

        [$chosen] = (new Planner(new FakeImportTarget()))->choose($package, ImportChoices::fromPost(['name' => [self::SLICE => 'News ', self::SITE => ' ']]));

        self::assertSame($package, $chosen, 'the form fills every name in - only another one is a rename');
    }

    public function test_a_choice_of_no_valid_text_is_left_out(): void
    {
        $choices = ImportChoices::fromPost(['name' => [self::SLICE => "News \xC3"], 'setting' => [self::SLICE => ['_email..........' => "a\xFF@b.cz"]],
            'new_owner' => "Ecn\xC3", 'new_owner_email' => 'ecn@ecn.cz']);

        self::assertSame([[], []], [$choices->names, $choices->settings]);
        self::assertSame(['name' => '', 'email' => 'ecn@ecn.cz'], $choices->newOwner, 'still a new owner - the planner refuses it, the list is not used instead');
        self::assertJson($choices->toJson(), 'the run keeps them as JSON');
    }

    /** @param array<string, mixed> $transfer */
    private static function module2(Package $p, string $id, array $transfer): Package
    {
        return self::module($p, $id, static fn(Module $m): Module => new Module($m->type, $m->id, $m->rows, $m->settings, $m->objects, $m->spotTree, $m->items, $m->doc, $transfer));
    }

    public function test_no_choice_changes_nothing(): void
    {
        self::assertEquals(self::plan(new ImportChoices())->package, self::plan(new ImportChoices(), self::example())->package);
    }
}
