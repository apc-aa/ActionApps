<?php

declare(strict_types=1);

// HitcounterGroup::plan() reads through DB_AA, whose getDB() lives in include/locsess.php (loaded by
// the RecordingDb fixture) and needs the constants include/config.php normally provides.

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

namespace Tests\Unit\Later {

    use AA\Later\HitcounterGroup;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The HitcounterUpdate task plans the grouping of hit_archive from its oldest row. An empty
     * hit_archive (a new install, after a purge) has no oldest row - nothing to plan, and the task
     * must not fail every run.
     */
    final class HitcounterGroupPlanTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'hit_archive') ? [['oldest' => null]] : [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        public function test_plan_emptyArchive_plansNothing(): void
        {
            (new HitcounterGroup())->plan();

            $this->assertSame([], array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_contains($sql, 'toexecute'))));
        }
    }
}
