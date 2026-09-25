<?php

declare(strict_types=1);

/**
 * The cleanup after a failed import, C4.4 part 4b (design balicek-v1.md § 7.2 step 6): Compensation from the
 * journal - with a transaction, without one (MyISAM) and with mixed engines - at every failure point of
 * ImporterTest, next to rows the target had before (a foreign module, view, e-mail, collection, and a group
 * whose name differs from the imported one only in case and a trailing space); idempotence, a failing
 * delete, a step that cannot be built, unowned groups, a collection id taken since the rollback.
 * The lock and the shutdown handler: ImportGuardTest.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\Compensation;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Central\Package\Fixtures\CompensationFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/CompensationFixture.php';
require_once __DIR__ . '/ImporterTest.php';

final class CompensationTest extends TestCase
{
    use CompensationFixture;

    /** the engines of the target: InnoDB (TableDb::$engines empty), none (MyISAM), mixed - some tables roll back, some not */
    private const ENGINES = [
        'transaction' => [true, []],
        'MyISAM'      => [false, []],
        'mixed'       => [true, ['constant' => 'InnoDB', 'constant_slice' => 'MyISAM', 'view' => 'MyISAM', 'site_spot' => 'MyISAM',
            'alerts_collection_howoften' => 'MyISAM', 'module' => 'MyISAM']],
    ];

    // ---------------------------------------------------------------- every failure point

    /** @return iterable<string, array{string, string, string}> */
    public static function failurePoints(): iterable
    {
        foreach (ImporterTest::failures() as $name => [$statement]) {
            foreach (array_keys(self::ENGINES) as $engine) {
                foreach (['false', 'throw'] as $mode) {
                    yield "$name, $engine, $mode" => [$statement, $engine, $mode];
                }
            }
        }
    }

    #[DataProvider('failurePoints')]
    public function test_after_a_failure_nothing_of_the_import_remains_and_the_target_is_untouched(string $statement, string $engine, string $mode): void
    {
        [TableDb::$transactions, TableDb::$engines] = self::ENGINES[$engine];
        $foreign = self::foreign();
        $plan    = $this->plan();
        self::failAt($statement, $mode);

        $e = $this->failure($plan);

        self::assertSame($foreign, array_filter(TableDb::$tables), 'the rows of the target, nothing else');
        self::assertTrue($e->cleanup->clean(), $e->cleanup->summary());
        self::assertSame([], TableDb::$halted, 'no statement would have halted (die()) the request');
        self::assertNotContains('COMMIT', TableDb::$log);
        if (!$e->journal->isEmpty()) {
            self::assertNotEmpty(self::compensationStatements(), 'the compensation runs also after a successful rollback');
            $j = $e->journal;
            self::assertCount(count($j->modules()) + count($j->numbers()) + count($j->relations()) + count($j->collections()) + count($j->groups()), $e->cleanup->steps());
        }
        self::assertSame(['lock ' . self::LOCK . ' 3', 'unlock ' . self::LOCK], $this->target->locks);
    }

    public function test_the_report_names_each_step(): void
    {
        TableDb::$transactions = false;
        self::failAt(self::LAST);

        $e = $this->failure($this->plan());

        [$w, $s, $a] = array_keys($e->journal->modules());
        self::assertSame([
            Module::SITE . "[$w]", Module::SLICE . "[$s]", Module::ALERTS . "[$a]",
            'view 1000, 1001', 'email 2000, 2001', 'alerts_filter 3000', 'site_spot 4000, 4001', 'profile 5000',
            "relation $w $s", 'alerts_collection K0001', 'constant-group[ct_rubriky]',
        ], array_column($e->cleanup->steps(), 0));
        self::assertSame([], array_merge(...array_column($e->cleanup->steps(), 1)));
    }

    public function test_the_compensation_is_idempotent(): void
    {
        TableDb::$transactions = false;
        $foreign               = self::foreign();
        $plan                  = $this->plan();
        self::failAt(self::LAST);
        $e = $this->failure($plan);
        TableDb::$log = [];

        $again = (new Compensation($plan))->run($e->journal);

        self::assertTrue($again->clean(), $again->summary());
        self::assertCount(count($e->cleanup->steps()), $again->steps());
        self::assertSame($foreign, array_filter(TableDb::$tables));
        self::assertNotEmpty(TableDb::writes(), 'it deletes again - nothing is there');
        self::assertSame([], array_filter(TableDb::writes(), static fn(string $q): bool => !str_starts_with($q, 'DELETE')));
    }

    public function test_a_failing_delete_stops_nothing_else_and_is_reported(): void
    {
        TableDb::$transactions = false;
        $foreign               = self::foreign();
        $plan                  = $this->plan();
        TableDb::$fail         = static function (string $sql): bool {
            if (preg_match('/^DELETE .*FROM [`"]site_spot[`"]/', $sql)) {
                throw new RuntimeException('site_spot is locked');
            }
            return preg_match(self::LAST, $sql) || preg_match('/^DELETE .*FROM [`"]view[`"]/', $sql);
        };

        $e = $this->failure($plan);

        $left = array_filter(TableDb::$tables);
        self::assertSame([1, 1000, 1001], array_column($left['view'], 'id'), 'only the views could not be deleted');
        self::assertSame([1, 4000, 4001], array_column($left['site_spot'], 'id'));
        self::assertCount(2, $left['site'], 'the site routine stopped at its site_spot delete');
        $kept = ['view' => 1, 'site_spot' => 1, 'site' => 1];
        self::assertSame(array_diff_key($foreign, $kept), array_diff_key($left, $kept), 'the rest is gone');
        self::assertSame([], TableDb::$halted, 'the legacy routines ran on quiet handles - no die()');

        [$w, $s] = array_keys($e->journal->modules());
        $failures = array_column($e->cleanup->failures(), 1, 0);
        self::assertSame([Module::SITE . "[$w]", Module::SLICE . "[$s]", 'view 1000, 1001', 'site_spot 4000, 4001'], array_keys($failures));
        self::assertSame('RuntimeException: site_spot is locked', $failures[Module::SITE . "[$w]"][0]);
        self::assertStringStartsWith('site: 1 rows left: WHERE id = 0x' . $w, $failures[Module::SITE . "[$w]"][1]);
        self::assertSame(['view: 2 rows left: WHERE slice_id = 0x' . $s], $failures[Module::SLICE . "[$s]"]);
        self::assertSame(['a statement failed', 'view: 2 rows left: WHERE id IN (1000,1001)'], $failures['view 1000, 1001']);
        self::assertFalse($e->cleanup->clean());
        self::assertStringContainsString('the cleanup left 4 steps for a manual action', $e->getMessage());
        foreach ($GLOBALS['spareDBs'] as $db) {
            self::assertSame('yes', $db->get_halt(), 'the halt modes are back');
        }
    }

    public function test_a_step_that_cannot_be_built_is_a_report_row_and_the_others_run(): void
    {
        TableDb::$transactions = false;
        $foreign               = self::foreign();
        $plan                  = $this->plan();
        self::failAt(self::LAST);
        $e = $this->failure($plan);
        TableDb::$tables['view'][] = ['id' => 1000, 'slice_id' => sprintf('%032x', 0xa002)];     // one of the import's rows once more
        // a constant the map does not know (a bug): the group step cannot be built
        $broken = new ImportPlan(...array_merge(get_object_vars($plan), ['package' => self::group($plan->package, static fn($g): array => [
            'rows' => ['constant' => [...$g->rows['constant'], ['id' => str_repeat('9', 32)]]] + $g->rows,
        ])]));

        $report = (new Compensation($broken))->run($e->journal);

        self::assertSame([['constant-group[ct_rubriky]', ['LogicException: Constant of ct_rubriky is not mapped']]], $report->failures());
        self::assertSame($foreign, array_filter(TableDb::$tables), 'the other steps ran');
    }

    // ---------------------------------------------------------------- constant groups

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return array_combine(array_keys(self::ENGINES), array_map(static fn(string $e): array => [$e], array_keys(self::ENGINES)));
    }

    #[DataProvider('engines')]
    public function test_a_group_without_owner_loses_the_constant_slice_row_the_journal_holds(string $engine): void
    {
        [TableDb::$transactions, TableDb::$engines] = self::ENGINES[$engine];
        $foreign = self::foreign();
        $plan    = $this->plan(self::group(self::example(), static fn(): array => ['owner' => '']));
        self::failAt(self::LAST);

        $e = $this->failure($plan);

        self::assertSame(['ct_rubriky'], $e->journal->groupSlices());
        $ours = array_values(array_filter(TableDb::$sent['constant_slice'], static fn(array $r): bool => $r['group_id'] === 'ct_rubriky'));
        self::assertArrayNotHasKey('slice_id', $ours[0], 'slice_id NULL');
        self::assertTrue($e->cleanup->clean(), $e->cleanup->summary());
        self::assertSame($foreign, array_filter(TableDb::$tables), 'the unowned CT_RUBRIKY row of the target stays');
    }

    /** MyISAM, no lock of the group admin: a foreign unowned row of the exact name came first - our insert fails, theirs stays */
    #[DataProvider('engines')]
    public function test_a_foreign_constant_slice_row_that_came_first_stays(string $engine): void
    {
        [TableDb::$transactions, TableDb::$engines] = self::ENGINES[$engine];
        self::foreign();
        TableDb::$tables['constant_slice'][] = ['group_id' => 'ct_rubriky', 'slice_id' => null, 'hierarch' => 0];
        $foreign = TableDb::$tables;
        $plan    = $this->plan(self::group(self::example(), static fn(): array => ['owner' => '']));

        $e = $this->failure($plan);

        self::assertSame('db at constant-group[ct_rubriky]/constant_slice[#0]: INSERT INTO constant_slice failed', $e->getPrevious()->getMessage());
        self::assertSame(['ct_rubriky'], $e->journal->groups());
        self::assertSame([], $e->journal->groupSlices());
        self::assertTrue($e->cleanup->clean(), $e->cleanup->summary());
        self::assertSame($foreign, array_filter(TableDb::$tables), 'the foreign row of the name survives');
    }

    // ---------------------------------------------------------------- alerts collections

    /** the rolled back collection id taken by the Alerts admin before the compensation (mixed engines: the
     *  periods and filters of ours stayed) - its rows stay, ours go where they can be told apart */
    public function test_a_collection_id_taken_since_the_rollback(): void
    {
        [TableDb::$transactions, TableDb::$engines] = [true, ['alerts_collection_filter' => 'MyISAM', 'alerts_collection_howoften' => 'MyISAM']];
        $foreign = self::foreign();
        $plan    = $this->plan();
        $theirs  = [
            'alerts_collection'          => ['id' => 'K0001', 'module_id' => self::FA],
            'alerts_collection_filter'   => ['collectionid' => 'K0001', 'filterid' => 1, 'myindex' => 0],
            'alerts_collection_howoften' => ['collectionid' => 'K0001', 'howoften' => 'weekly'],
        ];
        $taken = false;
        TableDb::$fail = static function (string $sql) use ($theirs, &$taken): bool {
            if (!$taken && str_starts_with($sql, 'DELETE')) {        // the first statement of the compensation
                foreach ($theirs as $table => $row) {
                    TableDb::$tables[$table][] = $row;
                }
                $taken = true;
            }
            return (bool) preg_match(self::LAST, $sql);
        };

        $e = $this->failure($plan);

        $left = array_filter(TableDb::$tables);
        self::assertSame([...$foreign['alerts_collection'], $theirs['alerts_collection']], $left['alerts_collection']);
        self::assertSame([...$foreign['alerts_collection_filter'], $theirs['alerts_collection_filter']], $left['alerts_collection_filter'], 'ours (filter 3000) deleted');
        self::assertSame(['daily', 'daily', 'weekly'], array_column($left['alerts_collection_howoften'], 'howoften'), 'ours cannot be told apart: left');
        self::assertSame([['alerts_collection K0001: the id is another module\'s now', [
            "alerts_collection_howoften: 2 rows left: WHERE collectionid = 'K0001'",
            "alerts_collection_filter: 1 rows left: WHERE collectionid = 'K0001' AND filterid <> '3000'",
        ]]], $e->cleanup->failures(), 'theirs, probably - never falsely clean');
        $mine = ['alerts_collection' => 1, 'alerts_collection_filter' => 1, 'alerts_collection_howoften' => 1];
        self::assertSame(array_diff_key($foreign, $mine), array_diff_key($left, $mine));
    }

    /** a filter row of our collection that names no filter of ours (filterid 0: "none") goes with the collection */
    public function test_every_filter_row_of_our_collection_goes(): void
    {
        TableDb::$transactions = false;
        $foreign               = self::foreign();
        $plan                  = $this->plan();
        TableDb::$fail         = static function (string $sql): bool {
            if (!preg_match(self::LAST, $sql)) {
                return false;
            }
            TableDb::$tables['alerts_collection_filter'][] = ['collectionid' => 'K0001', 'filterid' => 0, 'myindex' => 1];
            return true;
        };

        $e = $this->failure($plan);

        self::assertTrue($e->cleanup->clean(), $e->cleanup->summary());
        self::assertSame($foreign, array_filter(TableDb::$tables));
    }

    // ---------------------------------------------------------------- the legacy routines

    public function test_the_alerts_routine_deletes_by_the_collection_ids_of_the_module(): void
    {
        [$a, $b] = [str_repeat('a1', 16), str_repeat('b2', 16)];
        TableDb::$tables = [
            'alerts_collection'          => [['id' => 'Aa001', 'module_id' => $a], ['id' => 'Aa002', 'module_id' => $a], ['id' => 'Bb001', 'module_id' => $b]],
            'alerts_collection_filter'   => [['collectionid' => 'Aa001', 'filterid' => 1], ['collectionid' => 'Aa002', 'filterid' => 2], ['collectionid' => 'Bb001', 'filterid' => 1]],
            'alerts_collection_howoften' => [['collectionid' => 'Aa002', 'howoften' => 'daily'], ['collectionid' => 'Bb001', 'howoften' => 'daily']],
        ];

        self::assertTrue(\AA_Module_Alerts::_deleteModules([$a]));

        self::assertSame(['Bb001'], array_column(TableDb::$tables['alerts_collection'], 'id'));
        self::assertSame(['Bb001'], array_column(TableDb::$tables['alerts_collection_filter'], 'collectionid'));
        self::assertSame(['Bb001'], array_column(TableDb::$tables['alerts_collection_howoften'], 'collectionid'));
        self::assertContains("DELETE LOW_PRIORITY FROM `alerts_collection_filter`  WHERE collectionid IN ('Aa001','Aa002')", TableDb::$log, "select('') gives the first column: the ids");
    }
}
