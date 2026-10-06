<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Catalog;
use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use AA\Import\ChangeResult;
use AA\Import\Context;
use AA\Import\Definition;
use AA\Import\Mapping;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\Service;
use AA\Import\StartResult;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA\Later\BatchFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * The one way to start, continue and end an import run and to change its source, settings and
 * mapping. Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides
 * with the stub tests/Unit/IO/DB loads when that directory runs first; the source loads into the
 * global namespace.
 */
#[RunTestsInSeparateProcesses]
final class ServiceTest extends TestCase
{
    use RunSteps {
        setUp as private stepsSetUp;
        tearDown as private stepsTearDown;
    }

    private const SOURCE = 'custom:service';
    private const TASK   = '7d7d7d7d7d7d7d7d7d7d7d7d7d7d7d7d';
    /** a long id that names no module - an item, say */
    private const ITEM = '8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e';
    /** another import of SLICE */
    private const SIBLING = 'fedcba9876543210fedcba9876543210';
    /** a planned task of OTHER */
    private const OTHER_TASK = '9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f';
    /** a site module */
    private const SITE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    protected function setUp(): void
    {
        // a logged-in user: a planned start reads even his rights from the storage, which mirrors the session below
        $GLOBALS['auth'] = new class {
            public function getUid(): string
            {
                return 'uid-me';
            }
        };
        self::storedPerms([]);
        $this->stepsSetUp();
        $dir = __DIR__ . '/fixtures/service';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        Registry::sources();   // the tests set the source's statics
        \ServiceSource::$streamKeys  = ['s1', 's2', 's3'];
        \ServiceSource::$refuse      = null;
        \ServiceSource::$failStreams = null;
        \ServiceSource::$onStreams   = null;
        \ServiceSource::$created     = [];
        \ServiceSource::$mapping     = null;
        \ServiceSource::$failProperties = null;
        \ServiceSource::$failMapping    = null;
        TableDb::$tables['module'] = [['id' => self::SLICE, 'type' => 'S'], ['id' => self::OTHER, 'type' => 'S'], ['id' => self::SITE, 'type' => 'W']];
        $row = 900000;   // above the ids the tests' own writes get
        foreach ([self::TASK => self::SLICE, self::OTHER_TASK => self::OTHER] as $task => $slice) {
            foreach (['aa_type' => 'AA_Plannedtask', 'aa_owner' => $slice] as $property => $value) {
                TableDb::$tables['object_text'][] = ['id' => ++$row, 'object_id' => $task, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
            }
        }
    }

    protected function tearDown(): void
    {
        Registry::setCustomRootForTests(null);
        $this->stepsTearDown();
        unset($GLOBALS['auth']);
    }

    // ------------------------------------------------------------ start()

    public function test_start_queuesRunWithSnapshotAndFrozenStreams(): void
    {
        $def = self::definition(['settings' => '{"territory":"cz"}']);
        // the page's copy is older than the stored definition - the run takes what is stored, under the lock
        $stale = clone $def;
        $stale->setProperty('mapping', '{"rows":[]}');
        self::storedPerms(['uid-9' => [self::SLICE => PS_FEEDING]]);
        TableDb::$log = [];

        $r = Service::start($stale, 'changed', ['streams' => ['s3', 's1']], 'plan:' . self::TASK, 'uid-9');

        $this->assertSame('queued', $r->code);
        $run = self::stored($r->runId);
        $this->assertSame(Run::class, $run['aa_type']);
        $this->assertSame(self::DEF, $run['subject']);
        $this->assertSame('running', $run['state']);
        $this->assertSame('uid-9', $run['user']);
        $this->assertSame('changed', $run['mode']);
        $this->assertSame('plan:' . self::TASK, $run['trigger']);
        $this->assertSame('', $run['dry']);
        $this->assertSame('', $run['limit']);
        $this->assertSame(RunCursor::START, json_decode($run['position'], true));
        $this->assertSame($def->snapshot(), json_decode($run['snapshot'], true));
        $this->assertSame(['s1', 's3'], json_decode($run['stream_keys'], true), 'in the order of the source');
        $this->assertSame([['territory' => 'cz']], \ServiceSource::$created, 'the source of the stored settings');
        $this->assertTrue(self::lockedBeforeRun(self::DEF), 'decided under the lock of the definition');
        $queue = self::queue();
        $this->assertCount(1, $queue);
        $this->assertSame('Import_' . self::DEF, $queue[0]['selector']);
        $this->assertSame(5, (int) $queue[0]['priority']);

        \ServiceSource::$streamKeys = ['s1', 's2', 's1', 's3'];
        $all = Service::start(self::definition(['settings' => ''], self::SIBLING), 'run', [], 'manual', AuthGetUid());
        $this->assertSame(['s1', 's2', 's3'], json_decode(self::stored($all->runId)['stream_keys'], true), 'every stream when none is chosen, each once');
    }

    public function test_start_manualStillBusyOnError(): void
    {
        $def   = self::definition();
        $first = Service::start($def, 'run', [], 'manual', AuthGetUid());

        foreach (['running', 'error'] as $state) {
            self::storedSet($first->runId, 'state', $state);
            // hanging too: no step for days
            self::storedSet($first->runId, 'started', '1000');
            self::storedSet($first->runId, 'next_at', '');

            $r = Service::start($def, 'run', [], 'manual', AuthGetUid());

            $this->assertSame(['busy', $first->runId, $state], [$r->code, $r->runId, $r->reason], $state);
            $this->assertSame($state, self::stored($first->runId)['state']);
        }
        $this->assertCount(1, self::runIds());
    }

    /** one outage of the source must not stop the planned imports for good */
    public function test_start_planEndsErroredRunAndStarts(): void
    {
        $def = self::definition();
        $old = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($old, 'state', 'error');

        $r = Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid());

        $this->assertSame('queued', $r->code);
        $this->assertSame('ended', self::stored($old)['state']);
        $this->assertSame([$old, $r->runId], self::runIds());
        $this->assertSame('running', self::stored($r->runId)['state']);
    }

    public function test_start_planEndsStuckRunAndStarts(): void
    {
        $def = self::definition();
        $old = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        // no step for days, none queued
        self::storedSet($old, 'started', '1000');
        self::storedSet($old, 'next_at', '');

        $r = Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid());

        $this->assertSame('queued', $r->code);
        $this->assertSame('ended', self::stored($old)['state']);
        $this->assertCount(2, self::runIds());
    }

    public function test_start_planBlockedByLiveRun(): void
    {
        $def = self::definition();
        $old = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;

        $this->assertSame(['busy', 'running'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())), 'queued, not hanging');

        // in error, but the step that failed may still be storing items
        self::storedSet($old, 'state', 'error');
        foreach ([time() + 60, time() - Run::DEAD_GRACE + 5] as $lease) {
            self::storedSet($old, 'lease', (string) $lease);
            self::storedSet($old, 'lease_tok', 'tok');

            $this->assertSame(['busy', 'error'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())));
        }
        $this->assertSame([$old], self::runIds());
        $this->assertSame('error', self::stored($old)['state']);
    }

    public function test_start_endedRunNotedInNewReport(): void
    {
        $def = self::definition();
        $old = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($old, 'state', 'error');

        $new = Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())->runId;

        $this->assertSame([$old], json_decode(self::stored($new)['replaced'], true), 'the report of the new run names it');
        $this->assertSame('SCHEDULED_START', json_decode(self::stored($old)['progress'], true)['note']);
        $this->assertSame('', self::stored(Service::start(self::definition([], self::SIBLING), 'run', [], 'manual', AuthGetUid())->runId)['replaced'] ?? '');
    }

    /** the end and the start in one transaction: no new run, no end */
    public function test_start_planEndsOnlyWithTheStart(): void
    {
        $def = self::definition();
        $old = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($old, 'state', 'error');

        \ServiceSource::$failStreams = new SourceRefused('AUTH', 'the source refuses');
        $this->assertSame('invalid', Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())->code);
        $this->assertSame('error', self::stored($old)['state'], 'a start refused ends nothing');

        \ServiceSource::$failStreams = null;
        TableDb::$commitFails         = true;
        try {
            Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid());
            $this->fail('the commit fails');
        } catch (\RuntimeException) {
        } finally {
            TableDb::$commitFails = false;
        }
        $this->assertSame('error', self::stored($old)['state']);
        $this->assertSame([$old], self::runIds());
    }

    public function test_start_planOfAUserWhoMayNotEndItRefused(): void
    {
        $def = self::definition();
        $old = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($old, 'state', 'error');
        // whoever set the plan has lost the right to run the imports of the slice
        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);

        $this->assertSame(['invalid', 'FORBIDDEN'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())));
        $this->assertSame('error', self::stored($old)['state']);
        $this->assertSame([$old], self::runIds());
    }

    /** a planned start runs with the right of whoever saved the task - read as it is now, not from the
     *  permissions the session of the queue's request keeps for its own user */
    public function test_start_planChecksFeedingOfTaskAuthor(): void
    {
        $def = self::definition();
        self::storedPerms(['uid-author' => [self::SLICE => PS_FORMS . PS_EDIT_ALL_ITEMS, self::OTHER => PS_FEEDING]]);

        $this->assertSame(['invalid', 'FORBIDDEN'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, 'uid-author')));
        $this->assertSame([], self::runIds());
        $this->assertSame(AuthGetUid(), $GLOBALS['permission_uid'], 'the session keeps its own permissions');

        // the role of an administrator holds the feeding
        self::storedPerms(['uid-author' => [self::SLICE => \AA_Perm::ROLES['ADMINISTRATOR']['id']]]);
        $this->assertSame('queued', Service::start($def, 'run', [], 'plan:' . self::TASK, 'uid-author')->code);
    }

    /** the session keeps the rights the user had when he logged in - a plan asks the storage */
    public function test_start_planReadsRightsOfTheLoggedInUserFresh(): void
    {
        $def = self::definition();
        self::storedPerms(['uid-me' => [self::SLICE => PS_EDIT_ALL_ITEMS]]);

        $this->assertSame(['invalid', 'FORBIDDEN'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())));
        $this->assertSame('queued', Service::start($def, 'run', [], 'manual', AuthGetUid())->code, 'a page reads the session');
    }

    /** a plan runs every few minutes - its refusal is logged once a day */
    public function test_plannedStart_refusalLoggedOnceADay(): void
    {
        $def = self::definition(['enabled' => '1', 'mapping' => '{"rows":[{"to":"text...........9","t":"copyfield","p":{"field2copy":"key"}}]}', 'key_fields' => '["text...........9"]']);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(['invalid', 'DEFINITION'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())));
            \AA\Util\LogOnce::reset();   // as the next queue round, in another request
        }
        $this->assertSame(['#' . self::DEF . ' DEFINITION'], self::logged());
    }

    /** a planned start has no page to show why it was refused - State Check reads it from the log */
    public function test_plannedStart_refusalLogged(): void
    {
        $def = self::definition(['mapping' => '{"rows":[{"to":"text...........9","t":"copyfield","p":{"field2copy":"key"}}]}', 'key_fields' => '["text...........9"]']);

        $this->assertSame(['invalid', 'DEFINITION'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));
        $this->assertSame([], self::logged(), 'the page shows a manual refusal');

        $this->assertSame(['invalid', 'DEFINITION'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())));
        $this->assertSame(['#' . self::DEF . ' DEFINITION'], self::logged());

        // a bug of the source is logged once, by its class and line
        self::storedSet(self::DEF, 'mapping', self::NO_PARAM_MAPPING);
        self::storedSet(self::DEF, 'key_fields', '["relation........"]');
        \ServiceSource::$failStreams = new \RuntimeException('a bug');
        $this->assertSame(['invalid', 'SOURCE'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())));
        $this->assertCount(2, self::logged());
        $this->assertStringContainsString('RuntimeException', self::logged()[1]);
    }

    /** @return array<string, array{array<string, string>, string}> what another process writes into the run meanwhile, the reason */
    public static function takenMeanwhile(): array
    {
        return [
            'a step claimed it'      => [['lease' => '+60', 'lease_tok' => 'tok'], 'error'],
            'a Continue went through' => [['state' => 'running', 'next_at' => '+0'], 'running'],
            'ended, its step working' => [['state' => 'ended', 'lease' => '+60', 'lease_tok' => 'tok'], 'LEASE'],
        ];
    }

    /** @param array<string, string> $write */
    #[DataProvider('takenMeanwhile')]
    public function test_start_planDecidesAgainUnderTheLock(array $write, string $reason): void
    {
        $def = self::definition();
        $old = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($old, 'state', 'error');
        $written = [];
        \ServiceSource::$onStreams = static function () use ($old, $write, &$written): void {
            foreach ($write as $property => $value) {
                self::storedSet($old, $property, str_starts_with($value, '+') ? (string) (time() + (int) $value) : $value);
            }
            $written = self::stored($old);
        };

        $this->assertSame(['busy', $reason], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())));
        $this->assertSame($written, self::stored($old), 'left as the other process wrote it');
        $this->assertSame([$old], self::runIds());
    }

    public function test_start_planRunEndedMeanwhileNotNamed(): void
    {
        $def = self::definition();
        $old = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($old, 'state', 'error');
        \ServiceSource::$onStreams = static fn() => self::storedSet($old, 'state', 'ended');

        $r = Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid());

        $this->assertSame('queued', $r->code);
        $this->assertSame('', self::stored($r->runId)['replaced'] ?? '', 'the planned start did not end it');
        $this->assertArrayNotHasKey('note', (array) json_decode(self::stored($old)['progress'] ?? '', true));
    }

    /** every run is decided before the first one is ended: a refusal leaves all of them as they were */
    public function test_start_planEndsNoneWhenOneRefuses(): void
    {
        $def  = self::definition();
        $runs = [Service::start($def, 'run', [], 'manual', AuthGetUid())->runId, self::importRun()];
        foreach ([0, 1] as $refusing) {
            foreach ($runs as $id) {
                self::storedSet($id, 'state', 'error');
                self::storedSet($id, 'lease', '');
                self::storedSet($id, 'lease_tok', '');
            }
            \ServiceSource::$onStreams = static function () use ($runs, $refusing): void {
                self::storedSet($runs[$refusing], 'lease', (string) (time() + 60));
                self::storedSet($runs[$refusing], 'lease_tok', 'tok');
            };

            $this->assertSame(['busy', 'error'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())), "run $refusing refuses");
            $this->assertSame(['error', 'error'], array_map(static fn(string $id): string => self::stored($id)['state'], $runs), "run $refusing refuses");
        }
        $this->assertCount(2, self::runIds());
    }

    /** a double click, or a plan and a click at once - one run, the second is busy */
    public function test_start_doubleClickOneRun(): void
    {
        $def = self::definition();

        $one = Service::start($def, 'run', [], 'manual', AuthGetUid());
        TableDb::$log = [];
        $two = Service::start($def, 'run', [], 'manual', AuthGetUid());

        $lock = self::firstIndex(static fn(string $sql): bool => str_contains($sql, self::DEF) && str_ends_with($sql, ' FOR UPDATE'));
        $runs = array_keys(array_filter(TableDb::$log, static fn(string $sql): bool => str_contains($sql, "'subject'")));
        $this->assertNotNull($lock);
        $this->assertGreaterThan($lock, max($runs), 'the runs are looked up under the lock of the definition');

        $this->assertSame('queued', $one->code);
        $this->assertSame(['busy', $one->runId, 'running'], [$two->code, $two->runId, $two->reason]);
        $this->assertSame([$one->runId], self::runIds());
        $this->assertCount(1, self::queue());
    }

    public function test_start_busyWhileEndedRunHoldsLease(): void
    {
        $def   = self::definition();
        $ended = Service::start($def, 'run', [], 'manual', AuthGetUid());
        Service::end($ended->runId, AuthGetUid());
        // a step claimed before the end may still store items with the old snapshot
        self::storedSet($ended->runId, 'lease', (string) (time() + 60));
        self::storedSet($ended->runId, 'lease_tok', 'tok');

        $r = Service::start($def, 'run', [], 'manual', AuthGetUid());

        $this->assertSame(['busy', $ended->runId, 'LEASE'], [$r->code, $r->runId, $r->reason]);

        self::storedSet($ended->runId, 'lease', (string) (time() - 1));
        $this->assertSame('queued', Service::start($def, 'run', [], 'manual', AuthGetUid())->code, 'once the lease is out');
    }

    public function test_start_noInnodb(): void
    {
        $def = self::definition();
        TableDb::$engines['object_text'] = 'MyISAM';
        $before = TableDb::$tables;

        $r = Service::start($def, 'run', [], 'manual', AuthGetUid());

        $this->assertSame(['invalid', 'NO_TRANSACTION'], [$r->code, $r->reason]);
        $this->assertSame($before, TableDb::$tables);
    }

    /** a target field deleted from the slice after the definition was saved */
    public function test_start_invalidMapping(): void
    {
        $def = self::definition(['mapping' => '{"rows":[{"to":"text...........9","t":"copyfield","p":{"field2copy":"key"}}]}', 'key_fields' => '["text...........9"]']);

        $r = Service::start($def, 'run', [], 'manual', AuthGetUid());

        $this->assertSame(['invalid', 'DEFINITION'], [$r->code, $r->reason]);
        $this->assertSame([], self::runIds());
        $this->assertSame([], self::queue());

        self::storedSet(self::DEF, 'mapping', '{not json');
        $this->assertSame(['invalid', 'DEFINITION'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));

        self::storedSet(self::DEF, 'mapping', self::NO_PARAM_MAPPING);
        self::storedSet(self::DEF, 'after_stream', "{x:\xff}");   // no JSON for the snapshot
        $this->assertSame(['invalid', 'DEFINITION'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));
        $this->assertSame([], self::runIds());
    }

    public function test_start_unknownSource(): void
    {
        $def = self::definition(['source' => 'custom:gone']);

        $this->assertSame(['invalid', 'NO_SOURCE'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));
        $this->assertSame([], self::runIds());
    }

    public function test_start_sourceFailing(): void
    {
        $def = self::definition();
        \ServiceSource::$failStreams = new SourceUnavailable('TRANSPORT', 'down at token=s3cr3t');
        $this->assertSame(['invalid', 'TRANSPORT'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));

        \ServiceSource::$failStreams = new \RuntimeException('a bug of the source at token=s3cr3t');
        $this->assertSame(['invalid', 'SOURCE'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));

        \ServiceSource::$failStreams = null;
        \ServiceSource::$refuse      = new SourceRefused('NO_CREDENTIAL');
        $this->assertSame(['invalid', 'NO_CREDENTIAL'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));
        // the page shows a refusal; only the bug of the source is logged - by class and line
        $this->assertSame(['RuntimeException'], array_map(static fn(string $p): string => explode(' ', $p)[1], self::logged()));

        // a planned start has no page: its refusal is logged too, by its code - once a day for the import
        TableDb::$tables['log'] = [];
        $this->assertSame(['invalid', 'NO_CREDENTIAL'], self::codeOf(Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())));
        $this->assertSame(['#' . self::DEF . ' NO_CREDENTIAL'], self::logged());
        $this->assertSame([], self::runIds());
        $this->assertStringNotContainsString('s3cr3t', json_encode(TableDb::$tables, JSON_THROW_ON_ERROR));
    }

    public function test_start_disabledFromPlanIsOff(): void
    {
        $def = self::definition(['enabled' => '']);

        $this->assertSame('off', Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())->code);
        $this->assertSame([], self::runIds());
    }

    /** the trigger is not taken on its word: a task of the slice, and no task events yet */
    public function test_start_triggerOfAnotherTaskRefused(): void
    {
        $def = self::definition();

        // another task, no object, an object of the slice that is no task, a task run on an event
        foreach (['plan:' . self::OTHER_TASK, 'plan:' . self::ITEM, 'plan:' . self::DEF, 'event:' . self::TASK] as $trigger) {
            $this->assertSame(['invalid', 'TRIGGER'], self::codeOf(Service::start($def, 'run', [], $trigger, AuthGetUid())), $trigger);
        }
        $this->assertSame([], self::runIds());
        $this->assertSame('queued', Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())->code);
    }

    public function test_start_badTriggerThrows(): void
    {
        $def = self::definition();

        $this->expectException(\InvalidArgumentException::class);
        Service::start($def, 'run', [], 'plan:' . strtoupper(self::TASK), AuthGetUid());
    }

    /** a feeder of SLICE may not read OTHER through a setting another user stored; the plan keeps that user's grant */
    public function test_start_manualRechecksSliceSettings(): void
    {
        $def = self::definition(['settings' => '{"slice":"' . self::OTHER . '"}']);
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);

        $this->assertSame(['invalid', 'SLICE'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));
        $this->assertSame([], self::runIds());
        $this->assertSame('The setting "Slice of definitions" names a slice whose feeding you do not manage.', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame('queued', Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())->code);
    }

    public function test_start_disabledManualStarts(): void
    {
        $def = self::definition(['enabled' => '']);

        $this->assertSame('queued', Service::start($def, 'run', [], 'manual', AuthGetUid())->code);
    }

    public function test_start_manualWithoutFeedingRefused(): void
    {
        $def = self::definition();
        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);

        $this->assertSame(['invalid', 'FORBIDDEN'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));
        $this->assertSame([], self::runIds());
    }

    /** the definition of another slice is the same as none */
    public function test_start_definitionOfAnotherSliceUnknown(): void
    {
        self::definition([], self::DEF, self::OTHER);
        $posing = new Definition();
        $posing->setNew(self::DEF, self::SLICE, 'prices');

        $this->assertSame('unknown', Service::start($posing, 'run', [], 'manual', AuthGetUid())->code);
        $this->assertSame([], self::runIds());
    }

    public function test_start_streamsOptionMustBeKnown(): void
    {
        $def = self::definition();

        $this->assertSame(['invalid', 'STREAMS'], self::codeOf(Service::start($def, 'run', ['streams' => ['s1', 's9']], 'manual', AuthGetUid())));
        $this->assertSame([], self::runIds());
    }

    public function test_start_badOptionsInvalid(): void
    {
        $def = self::definition();

        $this->assertSame(['invalid', 'MODE'], self::codeOf(Service::start($def, 'all', [], 'manual', AuthGetUid())));
        foreach ([['limit' => 0], ['limit' => '5'], ['streams' => []], ['dry' => 1], ['ttl' => 5]] as $opt) {
            $this->assertSame(['invalid', 'OPTIONS'], self::codeOf(Service::start($def, 'run', $opt, 'manual', AuthGetUid())), json_encode($opt, JSON_THROW_ON_ERROR));
        }
        // the run would end it in error: a check stores nothing to try out
        $this->assertSame(['invalid', 'OPTIONS'], self::codeOf(Service::start($def, 'check', ['dry' => true], 'manual', AuthGetUid())));
        $this->assertSame([], self::runIds());
    }

    public function test_start_cleansOldRuns(): void
    {
        $def = self::definition();
        $old = [];
        for ($n = 1; $n <= 22; $n++) {
            $old[$n] = self::importRun();
            self::storedSet($old[$n], 'state', 'done');
            self::storedSet($old[$n], 'finished', (string) (1000 + $n));
        }
        TableDb::$tables['toexecute'] = [];

        $r = Service::start($def, 'run', [], 'manual', AuthGetUid());

        $this->assertSame('queued', $r->code);
        $this->assertEqualsCanonicalizing([...array_slice($old, 2), $r->runId], self::runIds(), 'the two oldest go, the new run is not counted');
    }

    /** a dry run reads 500 source items unless told; a real one all of them */
    public function test_start_dryRunDefaultLimit500(): void
    {
        $cases = [
            [['dry' => true], '1', '500'],
            [['dry' => true, 'limit' => 10], '1', '10'],
            [['dry' => false], '', ''],
            [['limit' => 7], '', '7'],
        ];
        foreach ($cases as $n => [$opt, $dry, $limit]) {
            $id = sprintf('%032x', $n + 1);
            $r  = Service::start(self::definition([], $id), 'run', $opt, 'manual', AuthGetUid());
            $this->assertSame([$dry, $limit], [self::stored($r->runId)['dry'], self::stored($r->runId)['limit']], json_encode($opt, JSON_THROW_ON_ERROR));
        }
    }

    // ------------------------------------------------------------ resume(), end()

    public function test_resume_andEnd_checkMayControl(): void
    {
        $def = self::definition();
        $id  = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($id, 'state', 'error');
        self::storedSet($id, 'error_code', 'TRANSPORT');
        TableDb::$tables['toexecute'] = [];
        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);
        $before = TableDb::$tables;

        $this->assertSame('unknown', Service::resume($id, AuthGetUid())->code, 'as a run that is not there');
        try {
            Service::end($id, AuthGetUid());
            $this->fail('ended without the right');
        } catch (BatchFailed $e) {
            $this->assertSame('UNKNOWN', $e->reason);
        }
        $this->assertSame($before, TableDb::$tables);
        $this->assertSame('unknown', Service::resume('ffffffffffffffffffffffffffffffff', AuthGetUid())->code);

        self::perms(PS_FEEDING, '');
        $this->assertSame(['queued', $id], [Service::resume($id, AuthGetUid())->code, $id]);
        $this->assertSame(['running', ''], [self::stored($id)['state'], self::stored($id)['error_code']]);
        $this->assertSame(['Import_' . self::DEF], array_column(self::queue(), 'selector'));
        $this->assertSame(['busy', 'WRONG_STATE'], self::codeOf(Service::resume($id, AuthGetUid())), 'its step is queued to run now');

        Service::end($id, AuthGetUid());
        $this->assertSame('ended', self::stored($id)['state']);
        $this->assertSame(['invalid', 'WRONG_STATE'], self::codeOf(Service::resume($id, AuthGetUid())), 'an ended run does not go on');
    }

    /** Continue is a manual action: the slices in the run's settings are checked for who continues */
    public function test_resume_rechecksSliceSettings(): void
    {
        $def = self::definition(['settings' => '{"slice":"' . self::OTHER . '"}']);
        $id  = Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid())->runId;
        self::storedSet($id, 'state', 'error');
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);

        $this->assertSame(['invalid', 'SLICE'], self::codeOf(Service::resume($id, AuthGetUid())));
        $this->assertSame('error', self::stored($id)['state']);

        self::perms(PS_FEEDING, PS_FEEDING);
        self::storedSet($id, 'snapshot', '{"slice":"' . self::SLICE . '","source":"' . self::SOURCE . '","settings":"{broken"}');
        $this->assertSame(['invalid', 'DEFINITION'], self::codeOf(Service::resume($id, AuthGetUid())));

        self::storedSet($id, 'snapshot', '{"slice":"' . self::SLICE . '","source":"' . self::SOURCE . '","settings":"{\\"slice\\":\\"' . self::OTHER . '\\"}"}');
        $this->assertSame('queued', Service::resume($id, AuthGetUid())->code);
    }

    public function test_end_ofEndedRunRefused(): void
    {
        $id = Service::start(self::definition(), 'run', [], 'manual', AuthGetUid())->runId;
        Service::end($id, AuthGetUid());

        $this->expectExceptionObject(new BatchFailed('WRONG_STATE'));
        Service::end($id, AuthGetUid());
    }

    /** waiting for its retry, or hanging with no step for hours: Continue tries now */
    public function test_resume_waitingOrStuckTriesNow(): void
    {
        $id = Service::start(self::definition(), 'run', [], 'manual', AuthGetUid())->runId;
        foreach (['waiting' => [(string) (time() + 1800), (string) time()], 'stuck' => ['', '1000']] as $case => [$nextAt, $started]) {
            TableDb::$tables['toexecute'] = [];
            self::storedSet($id, 'next_at', $nextAt);
            self::storedSet($id, 'started', $started);

            $this->assertSame(['queued', ''], self::codeOf(Service::resume($id, AuthGetUid())), $case);
            $this->assertSame(['Import_' . self::DEF], array_column(self::queue(), 'selector'), $case);
        }
    }

    /** its step died holding the lease: Continue tries now, the failures stay, and once only */
    public function test_resume_deadStepTriesNowKeepsFailures(): void
    {
        $id = Service::start(self::definition(), 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($id, 'lease', (string) (time() - Run::DEAD_GRACE));
        self::storedSet($id, 'lease_tok', 'dead-step');
        self::storedSet($id, 'failures', '2');

        $this->assertSame(['queued', ''], self::codeOf(Service::resume($id, AuthGetUid())));
        $this->assertSame(['running', '2'], [self::stored($id)['state'], self::stored($id)['failures']]);
        $this->assertSame(['Import_' . self::DEF], array_column(self::queue(), 'selector'));
        $this->assertSame(['busy', 'WRONG_STATE'], self::codeOf(Service::resume($id, AuthGetUid())), 'its step is queued to run now');
    }

    // ------------------------------------------------------------ changeSettings()

    public function test_changeSettings_endsRunAndDropsStateAtomically(): void
    {
        $def = self::definition(['settings' => '{"territory":"cz"}']);
        $run = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::ownedRecords();
        $before = TableDb::$tables;
        // the definition's own write fails last - the end of the run and the deletes must go with it
        TableDb::$fail = static fn(string $sql): bool => str_starts_with($sql, 'INSERT') && str_contains($sql, "'settings'");

        $thrown = null;
        try {
            Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'the failed write is reported');
        $this->assertSame($before, TableDb::$tables, 'all or nothing');

        TableDb::$fail = null;
        TableDb::$log  = [];
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['territory' => 'sk', 'from_year' => '2006'], AuthGetUid())->refusal());
        $this->assertTrue(self::lockedBeforeRun(self::DEF), 'under the lock a start takes');

        $stored = self::stored(self::DEF);
        $this->assertSame(['territory' => 'sk', 'from_year' => '2006'], json_decode($stored['settings'], true));
        $this->assertSame(self::SOURCE, $stored['source']);
        $this->assertSame('ended', self::stored($run)['state']);
        $this->assertSame('SETTINGS_CHANGED', json_decode(self::stored($run)['progress'], true)['note']);
        $this->assertNull(StreamState::forDefinition(self::DEF));
        $this->assertNull(Catalog::forDefinition(self::DEF));
        $this->assertSame(['BatchClose_' . $run], array_column(self::queue(), 'selector'), 'no step of the run is left');
    }

    public function test_changeSettings_sliceSettingWithoutFeedingRefused(): void
    {
        $def = self::definition();
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);
        $before = TableDb::$tables;

        $this->assertSame('The setting "Slice of definitions" names a slice whose feeding you do not manage.', Service::changeSettings($def, self::SOURCE, ['slice' => self::OTHER], AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);

        self::perms(PS_FEEDING, PS_FEEDING);
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['slice' => self::OTHER], AuthGetUid())->refusal());
        $this->assertSame(['slice' => self::OTHER], json_decode(self::stored(self::DEF)['settings'], true));
    }

    /** a slice setting goes into expressions of the source - only a slice id gets through, as it is stored */
    public function test_changeSettings_sliceSettingStrict(): void
    {
        $def = self::definition();
        self::perms(PS_FEEDING, PS_FEEDING);
        $before = TableDb::$tables;
        $bad    = [
            'expression' => self::SLICE . ':x}{item:' . self::ITEM . ':_#HEADLINE}{x:',
            'upper case' => strtoupper(self::SLICE),
            '33 digits'  => self::SLICE . 'a',
            'a site'     => self::SITE,
            'no module'  => self::ITEM,
            'two'        => self::SLICE . ',' . self::OTHER,
        ];
        foreach ($bad as $case => $value) {
            $this->assertSame('The setting "Slice of definitions" of the import source is not valid.', Service::changeSettings($def, self::SOURCE, ['slice' => $value], AuthGetUid())->refusal(), $case);
        }
        $this->assertSame($before, TableDb::$tables);
        $this->assertSame([], \ServiceSource::$created, 'no code of the source ran');

        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['slice' => " " . self::SLICE . "\n", 'territory' => self::OTHER], AuthGetUid())->refusal());
        $this->assertSame([['slice' => self::SLICE, 'territory' => self::OTHER]], \ServiceSource::$created, 'the source gets the id alone; free text is the source\'s to escape');
        $this->assertSame(['slice' => self::SLICE, 'territory' => self::OTHER], json_decode(self::stored(self::DEF)['settings'], true));
    }

    public function test_changeSettings_mappingOnlyKeepsRun(): void
    {
        $def = self::definition(['settings' => '{"territory":"cz","from_year":"2006"}']);
        $run = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::ownedRecords();
        $queue = self::queue();

        $this->assertNull(Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['from_year' => '2006', 'territory' => 'cz'], AuthGetUid())->refusal(), 'the same settings in another order');

        $this->assertSame('running', self::stored($run)['state'], 'the run works with its snapshot');
        $this->assertNotNull(StreamState::forDefinition(self::DEF));
        $this->assertNotNull(Catalog::forDefinition(self::DEF));
        $this->assertSame($queue, self::queue());
        $this->assertSame((new \ServiceSource())->defaultMapping()->toJson(), self::stored(self::DEF)['mapping']);
        $this->assertSame(['headline........'], json_decode(self::stored(self::DEF)['key_fields'], true));
        $this->assertSame('update', self::stored(self::DEF)['store_mode']);
        $this->assertSame(['1'], json_decode(self::stored(self::DEF)['validate'], true));
        $this->assertSame('{recompute:x}', self::stored(self::DEF)['after_stream']);
    }

    public function test_changeSettings_sourceChangeTakesItsMapping(): void
    {
        $def = self::definition(['source' => 'custom:gone', 'settings' => '{"old":"1"}']);
        $run = self::importRun();
        self::ownedRecords();

        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['territory' => 'cz'], AuthGetUid())->refusal());

        $stored  = self::stored(self::DEF);
        $default = (new \ServiceSource())->defaultMapping();
        $this->assertSame([self::SOURCE, '{"territory":"cz"}'], [$stored['source'], $stored['settings']]);
        $this->assertSame([$default->toJson(), '["headline........"]', 'update', '["1"]', '{recompute:x}'], [$stored['mapping'], $stored['key_fields'], $stored['store_mode'], $stored['validate'], $stored['after_stream']]);
        $this->assertSame('ended', self::stored($run)['state']);
        $this->assertNull(StreamState::forDefinition(self::DEF));
        $this->assertNull(Catalog::forDefinition(self::DEF));
    }

    public function test_changeSettings_sourceChangeNotFittingRefused(): void
    {
        $def = self::definition(['source' => 'custom:gone']);
        self::importRun();
        self::ownedRecords();
        \ServiceSource::$mapping = new Mapping([['to' => 'text...........9', 't' => 'copyfield', 'p' => ['field2copy' => 'key']]], ['text...........9']);
        $before = TableDb::$tables;

        $this->assertSame('The mapping fills a field the slice does not have: text...........9. (UNKNOWN_FIELD)', Service::changeSettings($def, self::SOURCE, [], AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
    }

    /** a run in error whose step still holds its lease ends too; a new run waits for the lease */
    public function test_changeSettings_erroredRunWithLeaseEnded(): void
    {
        $def = self::definition();
        $run = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($run, 'state', 'error');
        self::storedSet($run, 'lease', (string) (time() + 60));
        self::storedSet($run, 'lease_tok', 'tok');
        self::ownedRecords();

        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());

        $this->assertSame('ended', self::stored($run)['state']);
        $this->assertNull(StreamState::forDefinition(self::DEF));
        $this->assertSame(['busy', 'LEASE'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));
    }

    public function test_changeSettings_runFinishedMeanwhile(): void
    {
        $def = self::definition();
        $run = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        // its last step ends between the list of unfinished runs and the lock of the run
        TableDb::$answer = static function (string $sql) use ($run): ?array {
            if (str_contains($sql, $run) && str_ends_with($sql, ' FOR UPDATE')) {
                TableDb::$answer = null;
                self::storedSet($run, 'state', 'done');
            }
            return null;
        };

        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());

        $this->assertSame('done', self::stored($run)['state']);
        $this->assertSame('{"territory":"sk"}', self::stored(self::DEF)['settings']);
    }

    /** a run the user may not end stops the whole change */
    public function test_changeSettings_runNotToEndChangesNothing(): void
    {
        $def = self::definition();
        $run = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($run, 'snapshot', '{"slice":"' . self::OTHER . '"}');
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);
        $before = TableDb::$tables;

        try {
            Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal();
            $this->fail('changed while a run of it goes on');
        } catch (BatchFailed $e) {
            $this->assertSame('FORBIDDEN', $e->reason);
        }
        $this->assertSame($before, TableDb::$tables);
    }

    public function test_changeSettings_emptyOrBrokenStoredSettings(): void
    {
        $def = self::definition(['settings' => '{"territory":"cz"}']);

        $this->assertNull(Service::changeSettings($def, self::SOURCE, [], AuthGetUid())->refusal());
        $this->assertSame('', self::stored(self::DEF)['settings']);

        // what cannot be read is no setting the user keeps
        self::storedSet(self::DEF, 'settings', '{broken');
        $run = Service::start($def, 'run', [], 'plan:' . self::TASK, AuthGetUid());
        $this->assertSame(['invalid', 'DEFINITION'], self::codeOf($run));
        $this->assertNull(Service::changeSettings($def, self::SOURCE, [], AuthGetUid())->refusal());
        $this->assertSame('', self::stored(self::DEF)['settings']);
    }

    public function test_changeSettings_bugOfSourceLogged(): void
    {
        $def = self::definition();
        \ServiceSource::$refuse = new SourceRefused('AUTH');
        $this->assertSame('The import source refused these settings.', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertSame('The import source did not give its mapping.', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame([], self::logged(), 'a refusal is the page\'s to show');

        \ServiceSource::$refuse = new \RuntimeException('at token=s3cr3t');
        $this->assertSame('The import source refused these settings.', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertSame('The import source did not give its mapping.', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertCount(2, self::logged());
        $this->assertStringNotContainsString('s3cr3t', implode(' ', self::logged()));
    }

    // settings each valid alone, but not together - the source says so, its code did not fail
    public function test_changeSettings_settingsRefusedBySource(): void
    {
        $def    = self::definition(['settings' => '{"territory":"cz"}']);
        $before = TableDb::$tables;
        \ServiceSource::$refuse = new SourceRefused('SETTINGS', 'The key column must be one of the columns');

        $words = new ChangeResult('SETTINGS', '', 'The key column must be one of the columns');
        $this->assertEquals($words, Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid()));
        $this->assertEquals($words, Service::resetMapping($def, AuthGetUid()));
        $this->assertSame('The import source refused these settings: The key column must be one of the columns', $words->refusal());
        $this->assertSame($before, TableDb::$tables);
        $this->assertSame([], self::logged());

        \ServiceSource::$refuse = new SourceRefused('SETTINGS');
        $this->assertEquals(new ChangeResult('SETTINGS'), Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid()), 'no words of the source');
        $this->assertSame('A setting of the import source is not valid.', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
    }

    /** the source's words pass redact(), have control characters encoded and are cut; other refusals carry none */
    public function test_changeSettings_settingsRefusalTextIsCleaned(): void
    {
        if (!defined('AA_APIKEY_D4WORDS')) {
            define('AA_APIKEY_D4WORDS', 'w0rds-s3cr3t');
        }
        $def = self::definition();
        \ServiceSource::$credentials = [['vendor' => 'd4words', 'context' => '']];
        $GLOBALS['r_superuser']      = [AA_ID => true];
        \ServiceSource::$refuse      = new SourceRefused('SETTINGS', "missing: importer\nkey w0rds-s3cr3t " . str_repeat('x', 500));

        $message = Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->message;
        $this->assertStringStartsWith('missing: importer%0Akey *** x', $message);
        $this->assertSame(Context::REMARK_CHARS, mb_strlen($message));
        $this->assertSame($message, Service::resetMapping($def, AuthGetUid())->message);

        \ServiceSource::$refuse = new SourceRefused('AUTH', 'a text of another refusal');
        $this->assertSame('', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->message);
    }

    public function test_changeSettings_deniedWithoutFeeding(): void
    {
        $def = self::definition();
        self::perms(PS_EDIT_ALL_ITEMS . PS_EDIT, PS_FEEDING);
        $before = TableDb::$tables;

        $this->assertSame('Only a user who manages the feeding of the slice may change its imports.', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
        $this->assertSame([], \ServiceSource::$created, 'no code of the source ran');
    }

    public function test_changeSettings_badSettingsRefused(): void
    {
        $def    = self::definition();
        $before = TableDb::$tables;

        $this->assertSame('The setting "password" of the import source is not valid.', Service::changeSettings($def, self::SOURCE, ['password' => 'x'], AuthGetUid())->refusal());
        $this->assertSame('The setting "Territory" of the import source is not valid.', Service::changeSettings($def, self::SOURCE, ['territory' => ['a']], AuthGetUid())->refusal());
        $this->assertSame('The setting "Territory" of the import source is not valid.', Service::changeSettings($def, self::SOURCE, ['territory' => "\xff\xfe"], AuthGetUid())->refusal());
        $this->assertSame('The import source is not available.', Service::changeSettings($def, 'custom:gone', [], AuthGetUid())->refusal());
        \ServiceSource::$refuse = new SourceRefused('DEFINITION');
        $this->assertSame('The import source refused these settings.', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);

        $posing = new Definition();
        $posing->setNew(self::DEF, self::OTHER, 'prices');
        self::perms(PS_FEEDING, PS_FEEDING);
        \ServiceSource::$refuse = null;
        $this->assertSame('Only a user who manages the feeding of the slice may change its imports.', Service::changeSettings($posing, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
    }

    /** a slice id declared without SliceIdSetting is still a slice the step reads */
    public function test_changeSettings_looseSliceIdChecked(): void
    {
        $def = self::definition();
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);
        $before = TableDb::$tables;

        $this->assertSame('The setting "Another slice" names a slice whose feeding you do not manage.', Service::changeSettings($def, self::SOURCE, ['other' => strtoupper(self::OTHER)], AuthGetUid())->refusal());
        $this->assertSame('The setting "Territory" names a slice whose feeding you do not manage.', Service::changeSettings($def, self::SOURCE, ['territory' => ' ' . self::OTHER], AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);

        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['other' => self::ITEM, 'territory' => self::SITE], AuthGetUid())->refusal(), 'ids of no slice');
        self::perms(PS_FEEDING, PS_FEEDING);
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['other' => strtoupper(self::OTHER)], AuthGetUid())->refusal(), 'the right to the slice, however the id is written');
    }

    /** an id inside free text reaches a command that splits it - {ids:<A>-<B>} reads B */
    public function test_changeSettings_embeddedSliceIdsChecked(): void
    {
        $def = self::definition();
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);
        $before   = TableDb::$tables;
        $embedded = [self::SLICE . '-' . self::OTHER, self::SLICE . ',' . self::OTHER, 'x' . strtoupper(self::OTHER) . 'y'];

        foreach ($embedded as $value) {
            $this->assertSame('The setting "Territory" names a slice whose feeding you do not manage.', Service::changeSettings($def, self::SOURCE, ['territory' => $value], AuthGetUid())->refusal(), $value);
        }
        $this->assertSame($before, TableDb::$tables);
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['territory' => 'x' . self::ITEM . '-' . self::SLICE], AuthGetUid())->refusal(), 'an item and the own slice');

        self::perms(PS_FEEDING, PS_FEEDING);
        foreach ($embedded as $value) {
            $this->assertNull(Service::changeSettings($def, self::SOURCE, ['territory' => $value], AuthGetUid())->refusal(), $value);
        }
    }

    /** int, bool and date validators hand the value back as an int - it is stored, and goes on, as text */
    public function test_changeSettings_numberAndBoolSettings(): void
    {
        $def = self::definition();

        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['year' => '2006', 'active' => '1'], AuthGetUid())->refusal());
        $this->assertSame('{"year":"2006","active":"1"}', self::stored(self::DEF)['settings']);
        $this->assertSame(['year' => '2006', 'active' => '1'], \ServiceSource::$created[array_key_last(\ServiceSource::$created)]);
        $this->assertSame('The setting "From year" of the import source is not valid.', Service::changeSettings($def, self::SOURCE, ['year' => 'MMVI'], AuthGetUid())->refusal());

        $this->assertNull(Service::resetMapping($def, AuthGetUid())->refusal());
        $run = Service::start($def, 'run', [], 'manual', AuthGetUid());
        $this->assertSame('queued', $run->code);
        self::storedSet($run->runId, 'state', 'error');
        $this->assertSame('queued', Service::resume($run->runId, AuthGetUid())->code);
    }

    /** the mapping follows the settings: a slice another user set must not stay in its expressions */
    public function test_changeSettings_settingsRebuildMapping(): void
    {
        $def = self::definition();
        self::perms(PS_FEEDING, PS_FEEDING);
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['slice' => self::OTHER], AuthGetUid())->refusal());
        $this->assertNull(Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame(['{ids:' . self::OTHER . '}'], json_decode(self::stored(self::DEF)['validate'], true));

        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['slice' => self::SLICE], AuthGetUid())->refusal());

        $this->assertSame(['{ids:' . self::SLICE . '}'], json_decode(self::stored(self::DEF)['validate'], true));
        $this->assertStringNotContainsString(self::OTHER, json_encode(self::stored(self::DEF), JSON_THROW_ON_ERROR));

        \ServiceSource::$mapping = new Mapping([['to' => 'text...........9', 't' => 'copyfield', 'p' => ['field2copy' => 'key']]], ['text...........9']);
        $before = TableDb::$tables;
        $this->assertSame('The mapping fills a field the slice does not have: text...........9. (UNKNOWN_FIELD)', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
    }

    public function test_changeSettings_definitionOfAnotherSliceRunsNoSourceCode(): void
    {
        self::definition();
        $posing = new Definition();
        $posing->setNew(self::DEF, self::OTHER, 'prices');
        self::perms(PS_FEEDING, PS_FEEDING);
        \ServiceSource::$failProperties = new \RuntimeException('a bug');

        // answered as a missing right: the page must not learn which ids are imports of other slices
        $this->assertSame('Only a user who manages the feeding of the slice may change its imports.', Service::changeSettings($posing, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertSame([], \ServiceSource::$created);
        $this->assertSame([], self::logged());
    }

    public function test_changeSettings_blankAndLongValues(): void
    {
        $def = self::definition();

        // what a form posts for the fields left blank is not stored: nothing changed
        $this->assertSame('SAME', Service::changeSettings($def, self::SOURCE, ['territory' => "  \t", 'slice' => ''], AuthGetUid())->code, 'an optional slice may stay empty');
        $this->assertSame('', self::stored(self::DEF)['settings']);

        $this->assertSame('The setting "Territory" of the import source is not valid.', Service::changeSettings($def, self::SOURCE, ['territory' => str_repeat('a', 1001)], AuthGetUid())->refusal());
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['territory' => str_repeat('a', 1000)], AuthGetUid())->refusal());
        $this->assertNull(Service::changeSettings($def, self::SOURCE, ['code' => str_repeat('b', 1500)], AuthGetUid())->refusal(), 'a property with its own maximum');
        $this->assertSame('The setting "Code" of the import source is not valid.', Service::changeSettings($def, self::SOURCE, ['code' => str_repeat('b', 2001)], AuthGetUid())->refusal());
    }

    public function test_changeSettings_propertiesOfSourceFailing(): void
    {
        $def = self::definition();
        \ServiceSource::$failProperties = new \RuntimeException('at token=s3cr3t');

        $this->assertSame('The import source refused these settings.', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertCount(1, self::logged());
        $this->assertSame(['invalid', 'SOURCE'], self::codeOf(Service::start($def, 'run', [], 'manual', AuthGetUid())));
    }

    /** a form posts every field: blank ones and an unchecked checkbox change nothing, also against older stored settings */
    public function test_changeSettings_blankAndOffAreUnchanged(): void
    {
        $def = self::definition(['settings' => '{"territory":"cz","from_year":"","active":"0"}']);
        $run = Service::start($def, 'run', [], 'manual', AuthGetUid())->runId;
        $all = ['territory' => 'cz', 'from_year' => '', 'slice' => '', 'other' => '', 'code' => ' ', 'year' => '', 'active' => '0'];

        $this->assertSame('SAME', Service::changeSettings($def, self::SOURCE, $all, AuthGetUid())->code);
        $this->assertSame(Run::RUNNING, self::stored($run)['state']);

        $this->assertSame('CHANGED', Service::changeSettings($def, self::SOURCE, ['active' => '1'] + $all, AuthGetUid())->code);
        $this->assertSame(['active' => '1', 'territory' => 'cz'], json_decode(self::stored(self::DEF)['settings'], true));
        $this->assertSame('CHANGED', Service::changeSettings($def, self::SOURCE, $all, AuthGetUid())->code, 'switched off');
        $this->assertSame(['territory' => 'cz'], json_decode(self::stored(self::DEF)['settings'], true));
    }

    /** a form made from other stored settings - another user saved meanwhile - changes nothing */
    public function test_changeSettings_staleFormRefused(): void
    {
        $def  = self::definition(['settings' => '{"territory":"cz"}']);
        $hash = Service::settingsHash($def);
        $this->assertSame('CHANGED', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid(), $hash)->code);
        $before = TableDb::$tables;

        $result = Service::changeSettings($def, self::SOURCE, ['territory' => 'at'], AuthGetUid(), $hash);

        $this->assertSame('STALE', $result->code);
        $this->assertStringContainsString('changed meanwhile', (string) $result->refusal());
        $this->assertSame($before, TableDb::$tables);
        $fresh = Definition::fresh(self::DEF) ?? throw new \LogicException('No definition');
        $this->assertNotSame($hash, Service::settingsHash($fresh));
        $this->assertSame('CHANGED', Service::changeSettings($fresh, self::SOURCE, ['territory' => 'at'], AuthGetUid(), Service::settingsHash($fresh))->code);

        // the source changed meanwhile, its settings stayed empty
        $gone = self::definition(['source' => 'custom:gone'], 'abababababababababababababababab');
        $hash = Service::settingsHash($gone);
        $this->assertSame('CHANGED', Service::changeSettings($gone, self::SOURCE, [], AuthGetUid(), $hash)->code);
        $this->assertSame('STALE', Service::changeSettings($gone, self::SOURCE, ['territory' => 'cz'], AuthGetUid(), $hash)->code);
    }

    /** the feeding right runs an import whose source uses keys, a superadmin changes it - and the user learns why */
    public function test_changeSettings_keysSourceRefusedWithReason(): void
    {
        $def = self::definition();
        \ServiceSource::$credentials = [['vendor' => 'csu', 'context' => '']];

        $this->assertSame('KEYS', Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->code);
        $this->assertSame('KEYS', Service::resetMapping($def, AuthGetUid())->code);
        $this->assertSame('queued', Service::start($def, 'run', [], 'manual', AuthGetUid())->code, 'it still runs');

        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);
        $this->assertSame('FORBIDDEN', Service::resetMapping($def, AuthGetUid())->code, 'no feeding at all');
    }

    // ------------------------------------------------------------ baseline: the state of the source recorded

    /** it records versions no import fetched - a decision of a user at the page, never of a plan */
    public function test_baseline_notFromPlannedTask(): void
    {
        $def = self::definition();

        foreach (['plan:' . self::TASK, 'event:' . self::TASK] as $trigger) {
            $this->assertSame(['invalid', 'MODE'], self::codeOf(Service::start($def, 'baseline', [], $trigger, AuthGetUid())), $trigger);
        }
        $this->assertSame(['invalid', 'OPTIONS'], self::codeOf(Service::start($def, 'baseline', ['dry' => true], 'manual', AuthGetUid())), 'nothing to try out');
        $this->assertSame([], self::runIds());
        $this->assertSame([], self::baselinesLogged());

        $r = Service::start($def, 'baseline', [], 'manual', AuthGetUid());
        $this->assertSame('queued', $r->code);
        $this->assertSame(['baseline', 'manual', ''], [self::stored($r->runId)['mode'], self::stored($r->runId)['trigger'], self::stored($r->runId)['dry']]);
        $this->assertSame(['#' . self::DEF . ' ' . $r->runId . ' ' . AuthGetUid()], self::baselinesLogged(), 'who did it outlives the run');
    }

    public function test_baseline_busyRefused(): void
    {
        $def   = self::definition();
        $first = Service::start($def, 'run', [], 'manual', AuthGetUid());

        $this->assertSame(['busy', $first->runId, 'running'], [($r = Service::start($def, 'baseline', [], 'manual', AuthGetUid()))->code, $r->runId, $r->reason]);
        self::storedSet($first->runId, 'state', Run::ERROR);
        $this->assertSame(['busy', 'error'], [($r = Service::start($def, 'baseline', [], 'manual', AuthGetUid()))->code, $r->reason], 'as for any start by hand');
        $this->assertSame([], self::baselinesLogged());

        self::storedSet($first->runId, 'state', Run::DONE);
        $baseline = Service::start($def, 'baseline', [], 'manual', AuthGetUid());
        $this->assertSame('queued', $baseline->code);
        $this->assertSame(['busy', $baseline->runId], [($r = Service::start($def, 'changed', [], 'manual', AuthGetUid()))->code, $r->runId], 'a baseline is in the way of runs too');
    }

    /** as any change of what the runs are made of: whoever manages the import, a superadmin for a source with keys */
    public function test_baseline_keysSourceSuperadminOnly(): void
    {
        $def = self::definition();
        \ServiceSource::$credentials = [['vendor' => 'csu', 'context' => '']];

        $this->assertSame(['invalid', 'KEYS'], self::codeOf(Service::start($def, 'baseline', [], 'manual', AuthGetUid())));
        $this->assertSame([], self::runIds());
        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);
        $this->assertSame(['invalid', 'FORBIDDEN'], self::codeOf(Service::start($def, 'baseline', [], 'manual', AuthGetUid())));

        self::perms(PS_FEEDING . PS_EDIT_ALL_ITEMS, PS_EDIT_ALL_ITEMS);
        $GLOBALS['r_superuser'] = [AA_ID => true];
        $this->assertSame('queued', Service::start($def, 'baseline', [], 'manual', AuthGetUid())->code);
    }

    /** Continue of a baseline asks the same right as its start; of another run, the feeding right only */
    public function test_baseline_resumeNeedsTheRightToStartIt(): void
    {
        $def = self::definition();
        \ServiceSource::$credentials = [['vendor' => 'csu', 'context' => '']];
        $GLOBALS['r_superuser']      = [AA_ID => true];
        $r = Service::start($def, 'baseline', [], 'manual', AuthGetUid());
        $this->assertSame('queued', $r->code);
        self::storedSet($r->runId, 'state', Run::ERROR);
        $GLOBALS['r_superuser'] = [AA_ID => false];

        $this->assertSame(['invalid', $r->runId, 'KEYS'], [($c = Service::resume($r->runId, AuthGetUid()))->code, $c->runId, $c->reason]);
        $this->assertSame('error', self::stored($r->runId)['state']);

        self::storedSet($r->runId, 'mode', 'run');
        $this->assertSame('queued', Service::resume($r->runId, AuthGetUid())->code, 'a run of the import needs no more');
        self::storedSet($r->runId, 'mode', 'baseline');
        self::storedSet($r->runId, 'state', Run::ERROR);
        $GLOBALS['r_superuser'] = [AA_ID => true];
        $this->assertSame('queued', Service::resume($r->runId, AuthGetUid())->code);
    }

    /** a source that tells no versions has no state to record: a baseline would only drop the versions known */
    public function test_baseline_sourceWithoutProbeRefused(): void
    {
        $dir = __DIR__ . '/fixtures/definition';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        $def = self::definition(['source' => 'custom:definitionplain']);

        $this->assertSame(['invalid', 'NO_VERSIONS'], self::codeOf(Service::start($def, 'baseline', [], 'manual', AuthGetUid())));
        $this->assertSame([], self::runIds());
        $this->assertSame('queued', Service::start($def, 'check', [], 'manual', AuthGetUid())->code, 'other runs go');
    }

    // ------------------------------------------------------------ resetMapping()

    public function test_resetMapping_brokenStoredSettingsRefused(): void
    {
        $def = self::definition(['settings' => '{broken']);
        $before = TableDb::$tables;

        $this->assertSame('A setting of the import source is not valid.', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
    }

    public function test_resetMapping_failingDefaultMappingLogged(): void
    {
        $def = self::definition();
        \ServiceSource::$failMapping = new SourceRefused('AUTH');
        $this->assertSame('The import source did not give its mapping.', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame([], self::logged(), 'the page shows a refusal');

        \ServiceSource::$failMapping = new \RuntimeException('at token=s3cr3t');
        $this->assertSame('The import source did not give its mapping.', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertCount(1, self::logged());
    }

    public function test_resetMapping_deniedWithoutFeeding(): void
    {
        $def = self::definition();
        self::perms(PS_EDIT_ALL_ITEMS . PS_EDIT, PS_FEEDING);
        $before = TableDb::$tables;

        $this->assertSame('Only a user who manages the feeding of the slice may change its imports.', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
        $this->assertSame([], \ServiceSource::$created, 'no code of the source ran');
    }

    public function test_resetMapping_sourceRefusalKeepsMapping(): void
    {
        $def    = self::definition(['settings' => '{"territory":"cz"}']);
        $before = TableDb::$tables;
        \ServiceSource::$refuse = new SourceRefused('DEFINITION');

        $this->assertSame('The import source did not give its mapping.', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame([['territory' => 'cz']], \ServiceSource::$created, 'the source of the stored settings');
        $this->assertSame($before, TableDb::$tables);
    }

    public function test_resetMapping_notFittingKeepsMapping(): void
    {
        $def    = self::definition();
        $before = TableDb::$tables;
        \ServiceSource::$mapping = new Mapping([['to' => 'text...........9', 't' => 'copyfield', 'p' => ['field2copy' => 'key']]], ['text...........9']);

        $this->assertSame('The mapping fills a field the slice does not have: text...........9. (UNKNOWN_FIELD)', Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
    }

    /** a mapping without a key would refuse every start - neither a change nor a reset stores it */
    public function test_changeSettingsAndResetMapping_noKeyRefused(): void
    {
        $def    = self::definition();
        $before = TableDb::$tables;
        \ServiceSource::$mapping = new Mapping([['to' => 'relation........', 't' => 'copyfield', 'p' => ['field2copy' => 'key']]], []);
        $no_key = 'The mapping has no key field, so an item cannot be found again. (NO_KEY)';

        $this->assertSame($no_key, Service::changeSettings($def, self::SOURCE, ['territory' => 'sk'], AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
        $this->assertSame($no_key, Service::resetMapping($def, AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
    }

    public function test_resetMapping_definitionOfAnotherSliceRefused(): void
    {
        self::definition();
        $posing = new Definition();
        $posing->setNew(self::DEF, self::OTHER, 'prices');
        self::perms(PS_FEEDING, PS_FEEDING);
        $before = TableDb::$tables;

        $this->assertSame('The import was not saved - it is not an import of this slice.', Service::resetMapping($posing, AuthGetUid())->refusal());
        $this->assertSame($before, TableDb::$tables);
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, string> $props  a stored definition over ServiceSource */
    private static function definition(array $props = [], string $id = self::DEF, string $slice = self::SLICE): Definition
    {
        $def = new Definition();
        $def->setNew($id, $slice, 'prices ' . $id);
        $props += [
            'source'     => self::SOURCE,
            'mapping'    => self::NO_PARAM_MAPPING,
            'key_fields' => '["relation........"]',
            'store_mode' => 'update/insert',
            'enabled'    => '1',
        ];
        foreach ($props as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame($id, $def->save());
        return $def;
    }

    /** @return string a run of DEF, as Service starts it */
    private static function importRun(): string
    {
        $def = Definition::fresh(self::DEF) ?? throw new \LogicException('No definition');
        return (string) DB_AA::transaction(static fn() => Run::start([
            'subject'     => self::DEF,
            'user'        => self::USER,
            'position'    => RunCursor::START,
            'mode'        => 'run',
            'snapshot'    => json_encode($def->snapshot(), JSON_THROW_ON_ERROR),
            'stream_keys' => '["s1"]',
            'trigger'     => 'manual',
        ]))->getId();
    }

    /** the stream state and the catalog of DEF */
    private static function ownedRecords(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['v' => '2026-10']);
        $state->store();
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha')]), self::NOW);
        $catalog->store();
    }

    /** @return list<string> the ids of the stored import runs */
    private static function runIds(): array
    {
        $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['property'] === 'aa_type') && ($r['value'] === Run::class));
        return array_values(array_map(static fn(array $r): string => (string) $r['object_id'], $rows));
    }

    /** whether the rows of definition $id were read FOR UPDATE before the first write */
    private static function lockedBeforeRun(string $id): bool
    {
        foreach (TableDb::$log as $sql) {
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, $id) && str_ends_with($sql, ' FOR UPDATE')) {
                return true;
            }
            if (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'DELETE')) {
                return false;
            }
        }
        return false;
    }

    /** the index in TableDb::$log of the first statement $match accepts */
    private static function firstIndex(\Closure $match): ?int
    {
        foreach (TableDb::$log as $i => $sql) {
            if ($match($sql)) {
                return $i;
            }
        }
        return null;
    }

    /** @return list<string> the params of the logged failures of the imports */
    private static function logged(): array
    {
        return array_values(array_map(static fn(array $r): string => (string) $r['params'], array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Definition::ERROR_EVENT)));
    }

    /** @return list<string> the params of the logged starts of a baseline */
    private static function baselinesLogged(): array
    {
        return array_values(array_map(static fn(array $r): string => (string) $r['params'], array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Definition::BASELINE_EVENT)));
    }

    /** @var array<string, array<string, string>> uid => slice => perms, as the perm storage keeps them */
    public static array $stored = [];

    /** The rights AA::$perm reads from the storage: those of $byUid, else those the session keeps
     *  for its user - the tests change them with perms().
     *  @param array<string, array<string, string>> $byUid */
    private static function storedPerms(array $byUid): void
    {
        self::$stored = $byUid;
        \AA::$perm   = new class ([]) extends \AA_Perm {
            public function getUserPerms($uid, $objectType, $flags = 0): array
            {
                if ($objectType !== 'slice') {
                    return [];
                }
                return ServiceTest::$stored[(string) $uid] ?? ((($GLOBALS['permission_uid'] ?? null) === $uid) ? ($GLOBALS['permission_to']['slice'] ?? []) : []);
            }
        };
    }

    /** @return array{string, string} */
    private static function codeOf(StartResult $r): array
    {
        return [$r->code, $r->reason];
    }
}
