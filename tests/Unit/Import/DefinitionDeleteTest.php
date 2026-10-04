<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA;
use AA\Cache\PageCache;
use AA\Import\Catalog;
use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use AA\Import\Definition;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA\Later\Toexecute;
use AA\Managed_Object;
use AA\Manager;
use AA\Manageraction\Import_Delete;
use AA\Manageraction\Manageractions;
use AA\Util\FormToken;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;
use Tests\Unit\Import\Fixtures\TestRun;
use Tests\Unit\Legacy\Fixtures\FakeStore;
use Tests\Unit\Legacy\Fixtures\FormTarget;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use Tests\Unit\Legacy\Fixtures\SearchlessManager;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * Deleting an import definition deletes what it owns - its runs, its stream state, its catalog and
 * the queued steps - in one transaction, and only for a user who manages the feeding of its slice.
 * Object_Delete would delete the definition alone and leave those records and queue rows orphaned.
 *
 * Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides with
 * the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class DefinitionDeleteTest extends TestCase
{
    use RunSteps {
        setUp as private stepsSetUp;
        tearDown as private stepsTearDown;
    }

    /** another import of SLICE, which the delete must leave alone */
    private const SIBLING = 'fedcba9876543210fedcba9876543210';
    /** an import of OTHER */
    private const FOREIGN = '0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f';

    protected function setUp(): void
    {
        $this->stepsSetUp();
        require_once __DIR__ . '/../Legacy/fixtures/object_form_fixtures.php';
        session_id('test-session');   // FormToken keys its tokens with the session
    }

    protected function tearDown(): void
    {
        session_id('');
        RecordingDb::reset();
        [$_GET, $_POST, $_REQUEST] = [[], [], []];
        unset($_SERVER['REQUEST_METHOD'], $GLOBALS['r_state']);
        $this->stepsTearDown();
    }

    // ------------------------------------------------------------ deleteWithRuns()

    public function test_deleteWithRuns_removesAllAndCancelsQueue(): void
    {
        $def     = self::definition(self::DEF, self::SLICE);
        $ended   = self::importRun(self::DEF);
        DB_AA::transaction(static fn() => Run::lockForUpdate($ended)?->end(AuthGetUid()));
        $running = self::importRun(self::DEF);
        self::sibling();
        self::ownedRecords(self::DEF);
        // a queue runner took the step of the running run just before the delete
        $held    = self::stepOf(self::rowsOf('Import_' . self::DEF)[0]);
        $kept    = self::rowsOtherThan([self::DEF, $ended, $running, StreamState::idOf(self::DEF), Catalog::idOf(self::DEF)]);

        $def->deleteWithRuns(AuthGetUid());

        $this->assertSame([], self::objectRows([self::DEF, $ended, $running, StreamState::idOf(self::DEF), Catalog::idOf(self::DEF)]));
        $this->assertSame([], self::rowsOf('Import_' . self::DEF), 'no step of the definition is left in the queue');
        $this->assertSame([], self::rowsOf('BatchClose_' . $ended), 'nor the close row of its ended run');
        $this->assertSame([[[self::SLICE], 'M']], self::$invalidated, 'the pages of the slice once, for what a step stored meanwhile');
        $this->assertSame($kept, self::rowsOtherThan([]), 'the other import of the slice keeps its runs, state and catalog');
        $this->assertCount(1, self::rowsOf('Import_' . self::SIBLING));

        $this->assertSame('stale', $held->toexecutelater());
        $this->assertSame([], self::objectRows([self::DEF, $running, StreamState::idOf(self::DEF), Catalog::idOf(self::DEF)]), 'the step wrote nothing back');

        $def->deleteWithRuns(AuthGetUid());   // a reload of the page after the delete
        $this->assertSame($kept, self::rowsOtherThan([]));
    }

    public function test_deleteWithRuns_withoutFeedingForbidden(): void
    {
        $def = self::definition(self::DEF, self::SLICE);
        self::importRun(self::DEF);
        self::ownedRecords(self::DEF);
        $before = TableDb::$tables;
        self::perms(PS_EDIT_ALL_ITEMS . PS_EDIT, PS_FEEDING);

        try {
            $def->deleteWithRuns(AuthGetUid());
            $this->fail('deleted without the right to manage the imports of the slice');
        } catch (BatchFailed $e) {
            $this->assertSame('FORBIDDEN', $e->reason);
        }
        $this->assertSame($before['object_text'], TableDb::$tables['object_text']);
        $this->assertSame($before['toexecute'], TableDb::$tables['toexecute']);
        $this->assertSame([], self::$invalidated);
    }

    public function test_deleteWithRuns_neverRun_noInvalidation(): void
    {
        $def = self::definition(self::DEF, self::SLICE);
        self::ownedRecords(self::DEF);

        $def->deleteWithRuns(AuthGetUid());

        $this->assertSame([], self::objectRows([self::DEF, StreamState::idOf(self::DEF), Catalog::idOf(self::DEF)]));
        $this->assertSame([], self::$invalidated, 'a definition never run stored nothing');
    }

    /** the delete has committed when the page cache fails - it is logged, not reported as not deleted */
    public function test_deleteWithRuns_invalidationFails_deletedAndLogged(): void
    {
        $def = self::definition(self::DEF, self::SLICE);
        $run = self::importRun(self::DEF);
        $cache = new class extends PageCache {
            public function invalidateFor($strings, $type = 'M', ?int $stored_before = null): int
            {
                throw new \RuntimeException('cache down at token=s3cr3t');
            }
        };
        (new \ReflectionProperty(AA::class, 'pagecache'))->setValue(null, $cache);

        $def->deleteWithRuns(AuthGetUid());

        $this->assertSame([], self::objectRows([self::DEF, $run]));
        $logged = array_values(array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Definition::ERROR_EVENT));
        $this->assertCount(1, $logged);
        $this->assertSame('Import_' . self::DEF, $logged[0]['selector']);
        $this->assertMatchesRegularExpression('/^#' . self::DEF . ' RuntimeException \\S+:\\d+$/D', (string) $logged[0]['params']);
    }

    public function test_deleteWithRuns_idOfAnotherClassDeletesNothing(): void
    {
        self::definition(self::DEF, self::SLICE);
        $run = self::importRun(self::DEF);
        self::ownedRecords(self::DEF);
        $before = TableDb::$tables;

        foreach ([$run, StreamState::idOf(self::DEF), Catalog::idOf(self::DEF)] as $id) {
            $posing = new Definition();
            $posing->setNew($id, self::SLICE, 'posing');
            $posing->deleteWithRuns(AuthGetUid());
        }

        $this->assertSame($before['object_text'], TableDb::$tables['object_text']);
        $this->assertSame($before['toexecute'], TableDb::$tables['toexecute']);
    }

    /**
     * The delete comes while a step works - between two jobs, as the source's jobs() hands out the
     * next one. The step goes on storing items till its end, then finds no run: it writes back
     * neither its cursor nor the stream state, and queues nothing.
     */
    public function test_deleteDuringStep_stepWritesNothingBack(): void
    {
        $source             = self::source(streams: 1, jobs: 0, items: 0);
        $source->minJob     = 0;
        $source->jobs['s1'] = static function (): \Generator {
            yield ['key' => 's1:a', 'items' => [['key' => 'k-a', 'val' => 'v']]];
            // what deleteWithRuns() deletes - Run's look-ups do not see a run of the test class
            DB_AA::transaction(static function (): void {
                TestRun::deleteOfSubject(self::DEF);
                StreamState::deleteFor(self::DEF);
                Catalog::deleteFor(self::DEF);
                Definition::deleteObjects([self::DEF]);
            });
            yield ['key' => 's1:b', 'items' => [['key' => 'k-b', 'val' => 'v']]];
        };
        self::streamVersions(['s1' => 'old']);
        $run = self::startRun($source);

        $this->assertSame('run gone', self::runOne());

        $this->assertSame(['k-a', 'k-b'], TestRun::storedValues('relation........'), 'items till the end of the step');
        $this->assertSame([], self::objectRows([$run->getId(), self::DEF, StreamState::idOf(self::DEF), Catalog::idOf(self::DEF)]));
        $this->assertSame([], self::queue());
    }

    // ------------------------------------------------------------ Import_Delete
    // The Manager finds the checked objects by AA_Object::querySet(), whose JOIN is more than TableDb
    // reads - these run over FakeStore; what deleteWithRuns() deletes is tested above.

    public function test_importDelete_deletesWithRuns(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class]]);

        $this->assertSame('', self::delete([self::DEF]));
        $this->assertSame([self::DEF], self::deleted('object_text', 'object_id'));
        $this->assertSame(['Import_' . self::DEF], self::deleted('toexecute', 'selector'));
    }

    public function test_importDelete_needsFeeding(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class]]);
        // Object_Delete asks for PS_EDIT - not enough for an import
        self::perms(PS_EDIT_ALL_ITEMS . PS_EDIT, PS_FEEDING);

        self::delete([self::DEF]);

        $this->assertFalse((new Import_Delete('DeleteObject'))->isPerm4Action(self::manager()));
        $this->assertSame([], self::deleted('object_text', 'object_id'));
        $this->assertSame([], self::deleted('toexecute', 'selector'));
    }

    public function test_importDelete_foreignSliceRefused(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class], self::FOREIGN => [self::OTHER, Definition::class]]);
        // feeding on both slices: still only the imports of the slice of the page
        self::perms(PS_FEEDING, PS_FEEDING);

        $msg = self::delete([self::FOREIGN, self::DEF]);

        $this->assertStringContainsString('not deleted', $msg);
        $this->assertSame([self::DEF], self::deleted('object_text', 'object_id'), 'the import of the slice of the page goes');
        $this->assertSame(['Import_' . self::DEF], self::deleted('toexecute', 'selector'));
    }

    public function test_importDelete_allowGetFalse(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class]]);

        self::delete([self::DEF], 'GET');

        $this->assertFalse((new Import_Delete('DeleteObject'))->allowGet());
        $this->assertSame([], self::deleted('object_text', 'object_id'));
    }

    public function test_importDelete_allModules_onlyWhereFeeding(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class], self::FOREIGN => [self::OTHER, Definition::class]]);
        // PS_EDIT would do for Object_Delete
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS . PS_EDIT);
        // the overview of all slices is a superadmin's page; each import is still checked on its slice
        $GLOBALS['r_superuser'] = [AA_ID => true];

        $msg = self::delete([self::FOREIGN, self::DEF], allModules: true);
        unset($GLOBALS['r_superuser']);

        $this->assertStringContainsString('not deleted', $msg);
        $this->assertSame([self::DEF], self::deleted('object_text', 'object_id'));
    }

    public function test_importDelete_pageOfAnotherClassRefused(): void
    {
        self::store([self::DEF => [self::SLICE, FormTarget::class]]);
        $actions = new Manageractions();
        $actions->addAction(new Import_Delete('DeleteObject'));
        $managed = new Managed_Object(FormTarget::class);

        $msg = self::delete([self::DEF], managed: $managed->setSettings(['actions' => $actions, 'switches' => null] + $managed->getSettings()));

        $this->assertSame('This action does not work on this page.', $msg);
        $this->assertSame([], self::deleted('object_text', 'object_id'));
    }

    public function test_importDelete_idOfAnotherClassNotDeleted(): void
    {
        $run   = 'abababababababababababababababab';
        $state = StreamState::idOf(self::DEF);
        // owned by the slice of the page, as no run or state ever is - the class alone keeps them
        self::store([$run => [self::SLICE, Run::class], $state => [self::SLICE, StreamState::class]]);

        $msg = self::delete([$run, $state]);

        $this->assertStringContainsString('not deleted', $msg);
        $this->assertSame([], self::deleted('object_text', 'object_id'));
        $this->assertSame([], self::deleted('toexecute', 'selector'));
    }

    public function test_importDelete_oneFailing_othersDeleted(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class], self::SIBLING => [self::SLICE, Definition::class]]);
        self::failOnLock(self::DEF, new \RuntimeException('the database failed at token=s3cr3t'));

        $msg = self::delete([self::DEF, self::SIBLING]);

        $this->assertSame('Some of the selected imports were not deleted.', $msg);
        $this->assertSame([self::SIBLING], self::deleted('object_text', 'object_id'));
        $this->assertSame([], self::logged(Toexecute::FAILED_EVENT), 'no failed task of the queue');
        $logged = self::logged(Definition::ERROR_EVENT);
        $this->assertCount(1, $logged);
        $this->assertSame('Import_' . self::DEF, $logged[0]['selector']);
        // the class and file:line only - never the text, nor anything of the definition
        $this->assertMatchesRegularExpression('/^#' . self::DEF . ' RuntimeException \\S+:\\d+$/D', (string) $logged[0]['params']);
        $this->assertStringNotContainsString('s3cr3t', implode(' ', array_map('strval', $logged[0])));
    }

    /** a right lost between the page and the lock - a refusal, not a fault */
    public function test_importDelete_refusalNotLogged(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class], self::SIBLING => [self::SLICE, Definition::class]]);
        self::failOnLock(self::DEF, new BatchFailed('FORBIDDEN'));

        $msg = self::delete([self::DEF, self::SIBLING]);

        $this->assertSame('Some of the selected imports were not deleted.', $msg);
        $this->assertSame([self::SIBLING], self::deleted('object_text', 'object_id'));
        $this->assertSame([], [...self::logged(Definition::ERROR_EVENT), ...self::logged(Toexecute::FAILED_EVENT)]);
    }

    // ------------------------------------------------------------ helpers

    /** the locking read of the definition $id throws $e */
    private static function failOnLock(string $id, \Throwable $e): void
    {
        $store               = RecordingDb::$answer;
        RecordingDb::$answer = static function (string $sql) use ($store, $id, $e): array {
            if (str_ends_with($sql, ' FOR UPDATE') && str_contains($sql, $id)) {
                throw $e;
            }
            return $store($sql);
        };
    }

    /** @return list<array<string, mixed>> the log rows of $type RecordingDb got */
    private static function logged(string $type): array
    {
        return array_values(array_filter(RecordingDb::inserts('log'), static fn(array $r): bool => ($r['type'] ?? '') === $type));
    }

    private static function definition(string $id, string $slice): Definition
    {
        $def = new Definition();
        $def->setNew($id, $slice, 'prices ' . $id);
        $def->setProperty('source', 'custom:scripted');
        $def->setProperty('mapping', self::NO_PARAM_MAPPING);
        $def->setProperty('key_fields', '["relation........"]');
        $def->setProperty('store_mode', 'update/insert');
        self::assertSame($id, $def->save());
        return $def;
    }

    /** @return string the id of a run of $defId as Service starts it, its first step queued */
    private static function importRun(string $defId): string
    {
        $def = Definition::fresh($defId) ?? throw new \LogicException("No definition $defId");
        return (string) DB_AA::transaction(static fn() => Run::start([
            'subject'     => $defId,
            'user'        => self::USER,
            'position'    => RunCursor::START,
            'mode'        => 'run',
            'snapshot'    => json_encode($def->snapshot(), JSON_THROW_ON_ERROR),
            'stream_keys' => '["s1"]',
            'trigger'     => 'manual',
        ]))->getId();
    }

    /** the stream state and catalog of $defId */
    private static function ownedRecords(string $defId): void
    {
        $state = StreamState::create($defId);
        $state->record('s1', ['v' => '2026-10']);
        $state->store();
        $catalog = Catalog::create($defId);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha')]), self::NOW);
        $catalog->store();
    }

    /** another import of SLICE with a run, a stream state and a catalog */
    private static function sibling(): void
    {
        self::definition(self::SIBLING, self::SLICE);
        self::importRun(self::SIBLING);
        self::ownedRecords(self::SIBLING);
    }

    /** @param list<string> $ids  @return list<array<string, mixed>> the stored rows of the objects $ids */
    private static function objectRows(array $ids): array
    {
        return array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => in_array($r['object_id'], $ids, true)));
    }

    /** @param list<string> $ids  @return list<array<string, mixed>> the stored rows of all objects but $ids */
    private static function rowsOtherThan(array $ids): array
    {
        return array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => !in_array($r['object_id'], $ids, true)));
    }

    /** @return list<array<string, mixed>> the queue rows of $selector */
    private static function rowsOf(string $selector): array
    {
        return array_values(array_filter(self::queue(), static fn(array $r): bool => $r['selector'] === $selector));
    }

    /** @param array<string, array{string, string}> $objects  the only stored objects: id => [aa_owner, aa_type] */
    private static function store(array $objects): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        FakeStore::install($objects);
    }

    /** @return list<string> the values of $column the DELETEs of $table named */
    private static function deleted(string $table, string $column): array
    {
        $values = [];
        foreach (RecordingDb::$log as $sql) {
            if (preg_match('/^DELETE (?:LOW_PRIORITY )?FROM `?' . $table . '`?\s/', $sql)) {
                $values = [...$values, ...(FakeStore::idsOf($sql, $column) ?? [])];
            }
        }
        return array_values(array_unique($values));
    }

    /** The Manager of the imports of SLICE - as Definition::getManagerConf() makes it, or of $managed;
     *  over all modules with $allModules - with the checked objects $ids sent to its Delete by
     *  $method; the message the Manager shows. */
    private static function delete(array $ids, string $method = 'POST', bool $allModules = false, ?Managed_Object $managed = null): string
    {
        $manager = self::manager($managed);
        if ($allModules) {
            $manager->setAllModules();
        }
        $request = ['chb' => array_fill_keys(array_map(static fn(string $id): string => "x$id", $ids), ''), 'akce' => 'DeleteObject', 'token' => FormToken::get(Manager::FORM_TOKEN)];
        $_GET     = ['scr_st_Go' => '', 'scr_st_Mv' => ''] + (($method === 'GET') ? $request : []);
        $_POST    = ($method === 'POST') ? $request : [];
        $_REQUEST = $_POST + $_GET + ['srchbr_akce' => ''];
        $_SERVER['REQUEST_METHOD'] = $method;
        $manager->performActions();
        return trim(strip_tags((string) $manager->getAndClearMessages()));
    }

    /** @param ?Managed_Object $managed null = the Manager of the imports */
    private static function manager(?Managed_Object $managed = null): Manager
    {
        $GLOBALS['r_state'] = ['manager' => ['test' => null]];
        $managed ??= Definition::getManagerConf();
        $managed->setSettings(array_merge($managed->getSettings(), ['module_id' => self::SLICE, 'searchbar' => null, 'use_profile' => false, 'actions_hint' => '', 'actions_hint_url' => '', 'scroller' => ['listlen' => 10]]));
        return new SearchlessManager('test', $managed);
    }
}
