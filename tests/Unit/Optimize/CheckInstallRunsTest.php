<?php

declare(strict_types=1);

/**
 * CheckInstallRuns: State Check warns about a transfer that has not moved on for an hour and lists the
 * transfers stopped by an error; finished, ended and preview runs are no finding.
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
    use AA\Optimize\CheckInstallRuns;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    final class CheckInstallRunsTest extends TestCase
    {
        private const SLICE = '11111111111111111111111111111111';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../Central/Package/fixtures/TableDb.php';
        }

        protected function setUp(): void
        {
            TableDb::reset();
            TableDb::$next       = ['object_text' => 1];
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
        }

        /** a transfer run driven to $state; its id */
        private static function runIn(string $state): string
        {
            $id = InstallRun::preview(InstallRun::TRANSFER, false, InstallRun::LOCAL, 'admin-uid', '', time())->getId();
            $at = static fn(callable $change) => DB_AA::transaction(static fn() => $change(InstallRun::lockForUpdate($id)));
            if ($state !== InstallRun::PREVIEW) {
                $at(static fn(InstallRun $run) => $run->definitionsWritten(new IdMap(modules: [self::SLICE => str_repeat('b', 32)]), '<aa-app/>', [self::SLICE]));
            }
            $at(static fn(InstallRun $run) => match ($state) {
                InstallRun::ERROR => $run->fail('ITEM_TOO_BIG', 'slice-module[' . self::SLICE . ']/item[x]'),
                InstallRun::DONE  => $run->finish(),
                InstallRun::ENDED => $run->end(),
                default           => null,
            });
            return $id;
        }

        public function test_no_run_is_ok(): void
        {
            $findings = CheckInstallRuns::findingsAt(time());

            $this->assertCount(1, $findings);
            $this->assertSame(Finding::OK, $findings[0]->severity);
        }

        public function test_a_transfer_that_moved_within_the_hour_is_ok(): void
        {
            self::runIn(InstallRun::ITEMS);

            $this->assertSame(Finding::OK, CheckInstallRuns::findingsAt(time() + 600)[0]->severity);
        }

        public function test_a_transfer_without_progress_for_an_hour_is_a_warning(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            $findings = CheckInstallRuns::findingsAt(time() + 2 * 3600 + 60);

            $this->assertCount(1, $findings);
            $this->assertSame([Finding::WARNING, $id], [$findings[0]->severity, $findings[0]->key]);
            $this->assertStringContainsString('2', $findings[0]->title);
            $this->assertStringEndsWith("installapp.php?run=$id", array_values($findings[0]->links)[0]);
        }

        public function test_a_transfer_in_error_is_listed_with_its_error(): void
        {
            $id = self::runIn(InstallRun::ERROR);

            $findings = CheckInstallRuns::findingsAt(time());

            $this->assertSame([Finding::ERROR, $id], [$findings[0]->severity, $findings[0]->key]);
            $this->assertContains(safe('ITEM_TOO_BIG slice-module[' . self::SLICE . ']/item[x]'), $findings[0]->evidence[0]);
        }

        public function test_a_written_install_run_is_done_at_once_and_no_finding(): void
        {
            $id = InstallRun::preview(InstallRun::INSTALL, false, InstallRun::LOCAL, 'admin-uid', '', time())->getId();
            DB_AA::transaction(static fn() => InstallRun::lockForUpdate($id)->definitionsWritten(new IdMap(modules: [self::SLICE => str_repeat('b', 32)]), '<aa-app/>', []));

            $this->assertSame(InstallRun::DONE, InstallRun::get($id)->state());
            $this->assertSame([], InstallRun::unfinished());
            $this->assertSame(Finding::OK, CheckInstallRuns::findingsAt(time() + 86400)[0]->severity);
        }

        public function test_previews_and_finished_or_ended_runs_are_no_finding(): void
        {
            foreach ([InstallRun::PREVIEW, InstallRun::DONE, InstallRun::ENDED] as $state) {
                self::runIn($state);
            }

            $findings = CheckInstallRuns::findingsAt(time() + 86400);

            $this->assertCount(1, $findings);
            $this->assertSame(Finding::OK, $findings[0]->severity);
        }
    }
}
