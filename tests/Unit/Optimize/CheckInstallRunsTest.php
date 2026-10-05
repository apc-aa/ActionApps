<?php

declare(strict_types=1);

/**
 * CheckInstallRuns: State Check warns about a transfer that hangs - no step has come for longer than its
 * retries and an hour (a queue that does not run) - and lists the transfers stopped by an error;
 * previews, finished and ended runs are no finding.
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

    use AA\Central\InstallRun;
    use AA\Central\Package\IdMap;
    use AA\IO\DB\DB_AA;
    use AA\Later\BatchStep;
    use AA\Optimize\CheckInstallRuns;
    use AA\Optimize\Finding;
    use LogicException;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\FakeSuperadmins;
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    final class CheckInstallRunsTest extends TestCase
    {
        private const SLICE = '11111111111111111111111111111111';
        private const USER  = 'admin-uid';
        /** a run hangs this long after its last progress: the retries (1 + 5 + 15 min) and an hour */
        private const HANGS_AFTER = 3600 + 60 + 300 + 900;

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
            TableDb::$engines    = ['object_text' => 'InnoDB', 'toexecute' => 'InnoDB', 'item' => 'InnoDB', 'content' => 'InnoDB'];
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
            class_exists(\AA_Perm::class);              // its first load sets AA::$perm - not over the stub
            $this->perm          = \AA::$perm;
            $this->superadmins   = new FakeSuperadmins([self::USER]);
            \AA::$perm           = $this->superadmins;
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
            \AA::$perm = $this->perm;
            set_time_limit(0);   // a task later() runs at once sets a 30 s limit - it must not outlive the test
        }

        /** a run of $mode driven to $state; its id */
        private function runIn(string $state, string $mode = InstallRun::TRANSFER): string
        {
            $id = InstallRun::preview($mode, false, InstallRun::LOCAL, self::USER, '', time())->getId();
            if ($state === InstallRun::PREVIEW) {
                return $id;
            }
            $slices = ($mode === InstallRun::TRANSFER) ? [self::SLICE] : [];
            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(new IdMap(modules: [self::SLICE => str_repeat('b', 32)]), '<aa-app/>', $slices));
            match ($state) {
                // the user lost the superadmin rights: the step stops before it asks the source
                InstallRun::ERROR => $this->stepWithout(self::USER, $id),
                InstallRun::DONE  => self::assertSame(InstallRun::DONE, self::runQueued($id)),
                InstallRun::ENDED => self::locked($id, static fn(InstallRun $run) => $run->end(self::USER)),
                default           => null,
            };
            return $id;
        }

        private function stepWithout(string $user, string $id): void
        {
            $this->superadmins->superadmins = array_values(array_diff($this->superadmins->superadmins, [$user]));
            self::assertSame('error ' . InstallRun::DENIED, self::runQueued($id));
        }

        private static function locked(string $id, callable $change): mixed
        {
            return DB_AA::transaction(static fn() => $change(InstallRun::lockForUpdate($id)));
        }

        /** runs the queued step of run $id as the queue does - the row goes away after it */
        private static function runQueued(string $id): string
        {
            $row  = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['selector'] === "Install_$id"))[0] ?? throw new LogicException('Nothing queued');
            $step = unserialize((string) $row['object']);
            self::assertInstanceOf(BatchStep::class, $step);
            $ret = $step->toexecutelater();
            TableDb::$tables['toexecute'] = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['id'] !== $row['id']));
            return $ret;
        }

        public function test_no_run_is_ok(): void
        {
            $findings = CheckInstallRuns::findingsAt(time());

            $this->assertCount(1, $findings);
            $this->assertSame(Finding::OK, $findings[0]->severity);
        }

        /** the queue may not have come to it yet - only its retries and an hour make a run hang */
        public function test_a_transfer_waiting_for_the_queue_is_ok_till_it_hangs(): void
        {
            self::runIn(InstallRun::ITEMS);

            $this->assertSame(Finding::OK, CheckInstallRuns::findingsAt(time() + 600)[0]->severity);
            $this->assertSame(Finding::OK, CheckInstallRuns::findingsAt(time() + self::HANGS_AFTER - 60)[0]->severity);
            $this->assertSame(Finding::WARNING, CheckInstallRuns::findingsAt(time() + self::HANGS_AFTER + 60)[0]->severity);
        }

        public function test_a_transfer_that_hangs_is_a_warning(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            $findings = CheckInstallRuns::findingsAt(time() + 2 * 3600 + 60);

            $this->assertCount(1, $findings);
            $this->assertSame([Finding::WARNING, $id], [$findings[0]->severity, $findings[0]->key]);
            $this->assertStringContainsString('2', $findings[0]->title);
            $this->assertStringEndsWith("installapp.php?run=$id", array_values($findings[0]->links)[0]);
            $this->assertContains('1', $findings[0]->evidence[0], 'its slices');
        }

        /** a written install whose finish the queue never ran */
        public function test_an_install_waiting_for_its_finish_is_a_warning_of_its_own(): void
        {
            $id = self::runIn(InstallRun::ITEMS, InstallRun::INSTALL);

            $findings = CheckInstallRuns::findingsAt(time() + 2 * 3600 + 60);

            $this->assertSame([Finding::WARNING, $id], [$findings[0]->severity, $findings[0]->key]);
            $this->assertSame('An installed app has waited for its finish for 2 hours', $findings[0]->title);
            $this->assertStringStartsWith('The app is installed and works', $findings[0]->advice);
        }

        public function test_a_transfer_in_error_is_listed_with_its_error(): void
        {
            $id = self::runIn(InstallRun::ERROR);

            $findings = CheckInstallRuns::findingsAt(time());

            $this->assertCount(1, $findings);
            $this->assertSame([Finding::ERROR, $id], [$findings[0]->severity, $findings[0]->key]);
            $this->assertContains(safe(InstallRun::DENIED . ' user'), $findings[0]->evidence[0]);
            $this->assertContains(safe(self::USER), $findings[0]->evidence[0]);
        }

        public function test_a_written_install_is_no_finding_once_the_queue_finished_it(): void
        {
            $id = self::runIn(InstallRun::DONE, InstallRun::INSTALL);

            $this->assertSame(InstallRun::DONE, InstallRun::get($id)->state());
            $this->assertSame([], InstallRun::unfinished());
            $this->assertSame(Finding::OK, CheckInstallRuns::findingsAt(time() + 86400)[0]->severity);
        }

        public function test_previews_and_finished_or_ended_runs_are_no_finding(): void
        {
            self::runIn(InstallRun::PREVIEW);
            self::runIn(InstallRun::DONE, InstallRun::INSTALL);
            self::runIn(InstallRun::ENDED);

            $findings = CheckInstallRuns::findingsAt(time() + 86400);

            $this->assertCount(1, $findings);
            $this->assertSame(Finding::OK, $findings[0]->severity);
        }
    }
}
