<?php

declare(strict_types=1);

/**
 * CheckOptimizeRuns: State Check warns about an Optimize run of Central that hangs - no step has come for
 * longer than its retries and an hour (a queue that does not run) - and lists the runs stopped by an
 * error; finished and ended runs are no finding.
 */

// Loading AA_Object (include/statestore.php) needs the config constants already (as InstallRunTest).

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Optimize {

    use AA\Central\OptimizeRun;
    use AA\Later\BatchStep;
    use AA\Optimize\CheckOptimizeRuns;
    use AA\Optimize\Finding;
    use AA_Response;
    use LogicException;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\FakeSuperadmins;
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    final class CheckOptimizeRunsTest extends TestCase
    {
        private const USER = 'admin-uid';
        /** a run hangs this long after its last progress: the retries (1 + 2 + 5 min) and an hour */
        private const HANGS_AFTER = 3600 + 60 + 120 + 300;

        private FakeSuperadmins $superadmins;
        private mixed $perm = null;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../Central/Package/fixtures/TableDb.php';
            require_once __DIR__ . '/../Central/Package/fixtures/FakeSuperadmins.php';
        }

        protected function setUp(): void
        {
            TableDb::reset();
            TableDb::$next       = ['object_text' => 1];
            TableDb::$engines    = ['object_text' => 'InnoDB', 'toexecute' => 'InnoDB'];
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
            class_exists(\AA_Perm::class);              // its first load sets AA::$perm - not over the stub
            $this->perm          = \AA::$perm;
            $this->superadmins   = new FakeSuperadmins([self::USER]);
            \AA::$perm           = $this->superadmins;
            OptimizeRun::setTestRemote(static fn(): AA_Response => new AA_Response('OK <table></table>'));
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
            \AA::$perm = $this->perm;
            OptimizeRun::setTestRemote(null);
            set_time_limit(0);   // a task later() runs at once sets a 30 s limit - it must not outlive the test
        }

        private static function begin(): string
        {
            return OptimizeRun::begin(OptimizeRun::SQL_UPDATE, 'test', [1, 2], self::USER)->getId();
        }

        /** runs the queued step of run $id as the queue does - the row goes away after it */
        private static function runQueued(string $id): string
        {
            $row  = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['selector'] === "Optimize_$id"))[0] ?? throw new LogicException('Nothing queued');
            $step = unserialize((string) $row['object']);
            self::assertInstanceOf(BatchStep::class, $step);
            $ret = $step->toexecutelater();
            TableDb::$tables['toexecute'] = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['id'] !== $row['id']));
            return $ret;
        }

        public function test_no_run_is_ok(): void
        {
            $findings = CheckOptimizeRuns::findingsAt(time());

            $this->assertCount(1, $findings);
            $this->assertSame(Finding::OK, $findings[0]->severity);
        }

        public function test_a_run_waiting_for_the_queue_is_ok_till_it_hangs(): void
        {
            $id = self::begin();

            $this->assertSame(Finding::OK, CheckOptimizeRuns::findingsAt(time() + 600)[0]->severity);
            $this->assertSame(Finding::OK, CheckOptimizeRuns::findingsAt(time() + self::HANGS_AFTER - 60)[0]->severity);
            $findings = CheckOptimizeRuns::findingsAt(time() + self::HANGS_AFTER + 60);
            $this->assertSame([Finding::WARNING, $id], [$findings[0]->severity, $findings[0]->key]);
            $this->assertStringEndsWith("optimize.php?run=$id", array_values($findings[0]->links)[0]);
            $this->assertContains('0 / 2', $findings[0]->evidence[0], 'no AA asked yet');
        }

        public function test_a_run_in_error_is_listed_with_its_error(): void
        {
            $id = self::begin();
            $this->superadmins->superadmins = [];
            self::assertSame('error ' . OptimizeRun::DENIED, self::runQueued($id));

            $findings = CheckOptimizeRuns::findingsAt(time());

            $this->assertSame([Finding::ERROR, $id], [$findings[0]->severity, $findings[0]->key]);
            $this->assertContains(OptimizeRun::DENIED, $findings[0]->evidence[0]);
        }

        public function test_finished_and_ended_runs_are_no_finding(): void
        {
            self::assertSame(OptimizeRun::DONE, self::runQueued(self::begin()));
            OptimizeRun::endRun(self::begin(), self::USER);

            $this->assertSame(Finding::OK, CheckOptimizeRuns::findingsAt(time() + 86400)[0]->severity);
        }
    }
}
