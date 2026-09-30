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
    /** @var list<array{string, \AA\Central\Package\LintFinding}> choose()'s findings of the last plan() */
    private static array $chosen = [];

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
    }

    private static function plan(ImportChoices $choices, ?Package $package = null): ImportPlan
    {
        $target         = new FakeImportTarget();
        $target->owners = [self::OWNER => 'Ecn'];
        $planner        = new Planner($target);
        [$package, $chosen] = $planner->choose($package ?? self::example(), $choices);
        $validation     = (new Validator())->validate($package);
        self::assertTrue($validation->importable(), json_encode(array_map(static fn(array $f): string => "{$f[1]->code} $f[0]", $validation->findings)));
        self::$chosen   = $chosen;
        return $planner->plan($package, $validation, false, [], [], false, self::OWNER, $choices);
    }

    /** @return list<string> "<level> <code> <path>" of the planner */
    private static function findings(ImportPlan $plan): array
    {
        return array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0]", [...self::$chosen, ...$plan->findings]);
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

    public function test_a_typed_setting_is_written_linted_and_the_app_value_kept_aside(): void
    {
        $plan = self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => '{include:/etc/passwd}']]));

        self::assertSame('{include:/etc/passwd}', $plan->settingDefaults[self::SLICE]['_email..........'][0]['value']);
        self::assertArrayNotHasKey('_email..........', array_filter($plan->settingsOfApp[self::SLICE] ?? [], static fn(array $v): bool => $v[0]['value'] === '{include:/etc/passwd}'));
        self::assertContains('S include ' . self::S . '/setting-values/_email..........[0]', self::findings($plan));
        self::assertSame([], $plan->settingsToFill(self::planned($plan, self::SLICE) ?? throw new \LogicException()), 'a typed value fills it');
    }

    public function test_the_settings_of_the_source_are_the_values_of_the_app_and_a_typed_one_wins(): void
    {
        $values  = ['_email..........' => [['value' => 'redakce@example.org', 'flag' => 64]]];
        $package = self::module2(self::example(), self::SLICE, ['setting-values' => $values]);

        $kept  = self::plan(new ImportChoices(), $package);
        $typed = self::plan(new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]), $package);

        self::assertSame('redakce@example.org', $kept->settingsOfApp[self::SLICE]['_email..........'][0]['value']);
        self::assertSame('redakce@example.org', $kept->settingDefaults[self::SLICE]['_email..........'][0]['value'], 'written as the source has it');
        self::assertSame([], self::planned($kept, self::SLICE)->transfer, 'the writer takes the defaults - an install carries no transfer part');
        self::assertSame('jiny@example.org', $typed->settingDefaults[self::SLICE]['_email..........'][0]['value']);
        self::assertSame('redakce@example.org', $typed->settingsOfApp[self::SLICE]['_email..........'][0]['value']);
    }

    public function test_a_transfer_writes_a_typed_setting_over_the_value_of_the_source(): void
    {
        $package    = self::module2(self::example(), self::SLICE, ['setting-values' => ['_email..........' => [['value' => 'redakce@example.org', 'flag' => 64]]]]);
        $choices    = new ImportChoices(settings: [self::SLICE => ['_email..........' => 'jiny@example.org']]);
        $validation = (new Validator())->validate($package, true);

        $plan = (new Planner(new FakeImportTarget()))->plan($package, $validation, true, [], [], false, '', $choices);

        self::assertSame('jiny@example.org', self::planned($plan, self::SLICE)->transfer['setting-values']['_email..........'][0]['value']);
        self::assertSame([], $plan->settingDefaults, 'a transfer writes no defaults');
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
