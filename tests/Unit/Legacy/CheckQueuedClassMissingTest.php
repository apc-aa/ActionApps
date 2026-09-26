<?php

declare(strict_types=1);

// RecordingDb (store_fixtures) loads the real locsess.php, which needs the constants include/config.php normally provides.

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

namespace Tests\Unit\Legacy {

    use AA\Later\Toexecute;
    use AA\Optimize\CheckQueuedClassMissing;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** State Check for the queued tasks whose class is missing (TASK_MISS rows Toexecute writes once a day per class) */
    final class CheckQueuedClassMissingTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        /** @return array<string, string> a LogOnce::recent() row */
        private static function logRow(string $class, int $days = 1): array
        {
            return ['selector' => $class, 'days' => (string) $days, 'first' => '1758000000', 'last' => '1758700000', 'params' => 'AA_Task_X'];
        }

        public function test_each_missing_class_is_a_warning_with_advice(): void
        {
            [$finding] = CheckQueuedClassMissing::findingsFor([self::logRow('AA_Task_Gone', 3)]);

            $this->assertSame(Finding::WARNING, $finding->severity);
            $this->assertStringContainsString('AA_Task_Gone', $finding->title);
            $this->assertStringContainsString('Task Manager', (string) $finding->advice);
            $this->assertSame(3, $finding->count, 'days seen');
            $this->assertSame(1758000000, $finding->since);
            $this->assertSame(1758700000, $finding->last);
            $this->assertSame('AA_Task_Gone', $finding->key);
            $this->assertArrayHasKey('Task Manager', $finding->links);
            $this->assertArrayHasKey('Log rows', $finding->links);
        }

        public function test_logged_class_is_escaped_in_evidence(): void
        {
            [$finding] = CheckQueuedClassMissing::findingsFor([self::logRow('<b>')]);

            $this->assertSame('&lt;b&gt;', $finding->evidence[0]['Class']);
        }

        public function test_no_rows_is_ok(): void
        {
            $this->assertSame([Finding::OK], array_map(static fn(Finding $f) => $f->severity, CheckQueuedClassMissing::findingsFor([])));
        }

        public function test_findings_read_the_rows_toexecute_writes(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [self::logRow('AA_Task_Gone')];

            $findings = (new CheckQueuedClassMissing())->findings();

            $this->assertStringContainsString("FROM `log` WHERE type = '" . Toexecute::LOG_CLASS_MISSING . "' AND time >= ", RecordingDb::$log[0]);
            $this->assertSame('AA_Task_Gone', $findings[0]->key);
        }
    }
}
