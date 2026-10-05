<?php

declare(strict_types=1);

/**
 * Links of an app to modules outside it on the target: a module this AA has keeps them (I), one it lacks
 * is W - unless the preview sends them to a slice of this AA (ImportChoices::$links), which the plan maps
 * and the writer writes like the ids of the app.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\ExistingSlice;
use AA\Central\Package\FindingList;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Package;
use AA\Central\Package\OutsideLinks;
use AA\Central\Package\Planner;
use AA\Central\Package\ReferenceLint;
use AA\Central\Package\Validator;
use AA\Central\Package\WriteError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/ImportFixture.php';

final class OutsideLinksTest extends TestCase
{
    use ImportFixture;

    /** a slice the app links to, of no module here */
    private const GONE = 'abababababababababababababababab';
    /** a slice of this AA */
    private const HERE = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    /** the example linking to GONE from an e-mail and from the slice's mlxctrl */
    private static function linking(): Package
    {
        $p = self::cell(self::example(), self::SLICE, 'email', 0, ['body' => 'see {ids:' . self::GONE . '}']);
        return self::cell($p, self::SLICE, 'slice', 0, ['mlxctrl' => self::GONE]);
    }

    private function planWith(ImportChoices $choices, ?Package $package = null): ImportPlan
    {
        $package ??= self::linking();
        $this->target->slices[self::HERE] = new ExistingSlice(self::HERE, 'Importers', 'S', 'cz-utf8_news_lang.php', 'utf-8', [], [], [], []);
        return (new Planner($this->target))->plan($package, (new Validator())->validate($package), false, [], [], false, '', $choices);
    }

    /** "<level> <code> <path> <ref>" of the links outside @return list<string> */
    private static function links(ImportPlan $plan): array
    {
        $codes = [ReferenceLint::MODULE_OUTSIDE, OutsideLinks::HERE, OutsideLinks::LINKED, Planner::LINK_UNKNOWN];
        $list  = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", array_filter($plan->allFindings(), static fn(array $f): bool => in_array($f[1]->code, $codes, true)));
        sort($list);
        return $list;
    }

    public function test_a_link_to_a_slice_this_aa_lacks_is_a_warning(): void
    {
        self::assertSame([
            'W module-outside ' . self::S . '/email[5]/body ' . self::GONE,
            'W module-outside ' . self::S . '/slice[#0]/mlxctrl ' . self::GONE,
        ], self::links($this->planWith(new ImportChoices())));
    }

    public function test_a_link_to_a_module_this_aa_has_keeps_going_there(): void
    {
        $this->target->moduleOwners = [self::GONE => ''];

        $plan = $this->planWith(new ImportChoices());

        self::assertSame([
            'I module-here ' . self::S . '/email[5]/body ' . self::GONE,
            'W module-outside ' . self::S . '/slice[#0]/mlxctrl ' . self::GONE,      // the writer empties it
        ], self::links($plan));
        self::assertNull($plan->map->longId(self::GONE), 'the id stays');
        self::assertSame([], $plan->outside->missing($plan->allFindings()), 'no slice to choose for it');

        $this->import($plan);

        $slice = $plan->map->module(self::SLICE);
        self::assertSame(['see {ids:' . self::GONE . '}'], array_column(array_filter(TableDb::rows('email'), static fn(array $r): bool => $r['owner_module_id'] === $slice), 'body'));
        self::assertSame([''], array_column(array_filter(TableDb::rows('slice'), static fn(array $r): bool => $r['id'] === $slice), 'mlxctrl'));
    }

    public function test_links_sent_to_a_slice_of_this_aa_are_written_to_it(): void
    {
        $plan = $this->planWith(new ImportChoices(links: [self::GONE => self::HERE]));

        self::assertSame([
            'I module-linked ' . self::S . '/email[5]/body ' . self::GONE . ' => ' . self::HERE,
            'I module-linked ' . self::S . '/slice[#0]/mlxctrl ' . self::GONE . ' => ' . self::HERE,
        ], self::links($plan));
        self::assertSame([self::GONE => self::HERE], $plan->outside->links);
        self::assertSame(self::HERE, $plan->map->longId(self::GONE));
        self::assertNull($plan->map->module(self::GONE), 'no module of the app: no rights, no log of it');

        $this->import($plan);

        $slice = $plan->map->module(self::SLICE);
        self::assertSame(['see {ids:' . self::HERE . '}'], array_column(array_filter(TableDb::rows('email'), static fn(array $r): bool => $r['owner_module_id'] === $slice), 'body'));
        self::assertSame([self::HERE], array_column(array_filter(TableDb::rows('slice'), static fn(array $r): bool => $r['id'] === $slice), 'mlxctrl'));
    }

    public function test_a_link_kept_or_of_a_module_of_the_app_maps_nothing(): void
    {
        $plan = $this->planWith(new ImportChoices(links: [self::GONE => '', self::SITE => self::HERE]));

        self::assertContains('W module-outside ' . self::S . '/email[5]/body ' . self::GONE, self::links($plan), 'kept: still a warning');
        self::assertSame([], $plan->outside->links);
        self::assertSame($plan->map->module(self::SITE), $plan->map->longId(self::SITE), 'the site of the app stays the new site');
    }

    /** @return array<string, array{string, bool}> slice the links go to, it is editable */
    public static function refusedSlices(): array
    {
        return [
            'not editable'          => [self::HERE, false],
            'of no ExistingSlice'   => [self::FS, true],
        ];
    }

    #[DataProvider('refusedSlices')]
    public function test_a_link_to_a_slice_the_user_may_not_edit_or_none_is_an_error(string $slice, bool $editable): void
    {
        $this->target->notEditable = $editable ? [] : [$slice];

        $plan = $this->planWith(new ImportChoices(links: [self::GONE => $slice]));

        self::assertFalse($plan->importable());
        self::assertContains('E link-unknown target ' . self::GONE . ' => ' . $slice, self::links($plan));
        self::assertNull($plan->map->longId(self::GONE));
    }

    public function test_a_link_of_an_id_the_app_does_not_link_to_is_left_out(): void
    {
        $item = str_repeat('9', 32);      // an id of any kind - the rewrite would replace it everywhere

        $plan = $this->planWith(new ImportChoices(links: [$item => self::HERE]), self::cell(self::linking(), self::SLICE, 'view', 1, ['odd' => "item $item"]));

        self::assertSame([], $plan->outside->links);
        self::assertNull($plan->map->longId($item));
        self::assertTrue($plan->importable());
    }

    public function test_every_id_the_app_links_to_may_be_linked_though_its_findings_are_cut(): void
    {
        $ids  = array_map(static fn(int $i): string => sprintf('%032x', 0xa000 + $i), range(1, FindingList::MAX_PER_CODE + 5));
        $last = end($ids);
        $plan = $this->planWith(new ImportChoices(links: [$last => self::HERE]), self::cell(self::example(), self::SLICE, 'email', 0, ['body' => '{ids:' . implode('}{ids:', $ids) . '}']));

        self::assertSame([$last => self::HERE], $plan->outside->links);
        $missing = $plan->outside->missing($plan->allFindings());
        self::assertSame($ids, array_keys($missing));
        self::assertSame([self::S . '/email[5]/body'], $missing[$ids[0]]);
        self::assertSame([], $missing[$last], 'no finding of its own - still offered');
    }

    public function test_a_slice_gone_since_the_plan_stops_the_write(): void
    {
        $plan = $this->planWith(new ImportChoices(links: [self::GONE => self::HERE]));
        unset($this->target->slices[self::HERE]);

        $e = $this->failure($plan);

        self::assertInstanceOf(WriteError::class, $e->getPrevious());
        self::assertSame([WriteError::GONE, 'link[' . self::GONE . ']'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
    }

    public function test_classify_leaves_other_findings_and_levels_alone(): void
    {
        $outside  = new OutsideLinks([self::GONE => self::HERE], [self::FS => true], [self::GONE => true, self::FS => true]);
        $findings = [
            ['a', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, strtoupper(self::GONE), 3)],
            ['b', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, self::FS, 4)],
            ['c', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, '+5 more', 5)],
            ['d', new LintFinding(LintFinding::WARNING, ReferenceLint::VIEW_OUTSIDE, '12', 6)],
            ['e', new LintFinding(LintFinding::INFO, ReferenceLint::MODULE_OUTSIDE, self::GONE, 7)],
        ];

        $classified = $outside->classify($findings);

        self::assertSame([
            'a I module-linked ' . self::GONE . ' => ' . self::HERE . ' 3',
            'b I module-here ' . self::FS . ' 4',
            'c I module-here +5 more 5',      // every module outside works here
            'd W view-outside 12 6',
            'e I module-outside ' . self::GONE . ' 7',
        ], array_map(static fn(array $f): string => "$f[0] {$f[1]->level} {$f[1]->code} {$f[1]->ref} {$f[1]->offset}", $classified));
        self::assertSame($classified, $outside->classify($classified), 'once is enough');
        $missing = new OutsideLinks([], [], [self::GONE => true]);
        self::assertSame(LintFinding::WARNING, $missing->classify([$findings[2]])[0][1]->level, 'one points nowhere - the rest may too');
    }

    public function test_many_links_to_modules_of_this_aa_warn_of_nothing(): void
    {
        $ids = array_map(static fn(int $i): string => sprintf('%032x', 0xa000 + $i), range(1, FindingList::MAX_PER_CODE + 5));
        $this->target->moduleOwners = array_fill_keys($ids, '');

        $plan = $this->planWith(new ImportChoices(), self::cell(self::example(), self::SLICE, 'email', 0, ['body' => '{ids:' . implode('}{ids:', $ids) . '}']));

        self::assertSame([], array_filter($plan->allFindings(), static fn(array $f): bool => ($f[1]->level === LintFinding::WARNING) && ($f[1]->code === ReferenceLint::MODULE_OUTSIDE)));
        self::assertContains('I module-here  +5 more (module-outside)', array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", $plan->allFindings()));
    }

    public function test_a_link_in_a_relation_field_and_a_setting_goes_to_the_slice(): void
    {
        $package = self::rows(self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows, ['id' => 'relation.......4', 'type' => 'relation', 'name' => 'Importer', 'input_show_func' => 'iso:#sLiCe-' . self::GONE]]);
        $package = self::setting($package, self::SLICE, 'fieldslice', self::GONE);

        $plan = $this->planWith(new ImportChoices(links: [self::GONE => self::HERE]), $package);
        self::assertTrue($plan->importable(), json_encode(self::links($plan)));
        $this->import($plan);

        $slice = $plan->map->module(self::SLICE);
        self::assertSame(['iso:#sLiCe-' . self::HERE], array_column(array_filter(TableDb::rows('field'), static fn(array $r): bool => ($r['slice_id'] === $slice) && ($r['id'] === 'relation.......4')), 'input_show_func'));
        self::assertContains(self::HERE, array_column(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['property'] === 'fieldslice'), 'value'));
        self::assertNotContains(self::GONE, array_column(TableDb::rows('object_text'), 'value'));
    }

    public function test_missing_lists_the_slices_this_aa_lacks_with_their_places(): void
    {
        $outside  = new OutsideLinks([self::GONE => self::HERE], [self::FS => true], [self::GONE => true, self::FS => true, str_repeat('9', 32) => true, str_repeat('8', 32) => true]);
        $findings = $outside->classify([
            ['a', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, self::GONE, 0)],
            ['a', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, self::GONE, 9)],
            ['b', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, self::GONE, 0)],
            ['c', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, self::FS, 0)],
            ['c/mlxctrl', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, self::FS, 0)],
            ['d', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, str_repeat('9', 32), 0)],
            ['e', new LintFinding(LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, '+5 more', 0)],
        ]);

        self::assertSame([self::GONE => ['a', 'b'], str_repeat('9', 32) => ['d'], str_repeat('8', 32) => []], $outside->missing($findings));
    }

    public function test_the_form_sends_the_links_and_the_run_keeps_them(): void
    {
        $choices = ImportChoices::fromPost(['link' => [strtoupper(self::GONE) => strtoupper(self::HERE), self::FS => '', self::SITE => 'nonsense', 'x' => self::HERE, self::SLICE => ['array']]]);

        self::assertSame([self::GONE => self::HERE, self::FS => ''], $choices->links, '"keep" is a choice too');
        self::assertTrue(ImportChoices::fromJson($choices->toJson())->equals($choices));
        self::assertFalse((new ImportChoices())->equals(new ImportChoices(links: [self::GONE => ''])), 'the sum of the preview knows a link kept');
    }

    public function test_an_earlier_link_never_replaces_a_choice(): void
    {
        $choices = (new ImportChoices(links: [self::GONE => '']))->withLinks([self::GONE => self::HERE, self::FS => self::HERE]);

        self::assertSame([self::GONE => '', self::FS => self::HERE], $choices->links);
        self::assertSame($choices->links, $choices->withoutSettings([])->links);
    }

    public function test_a_link_of_no_shape_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImportChoices(links: [self::GONE => 'nope']);
    }
}
