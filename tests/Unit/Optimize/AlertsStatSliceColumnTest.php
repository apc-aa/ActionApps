<?php

declare(strict_types=1);

/**
 * AlertsStatSliceColumn adds alerts_collection.stat_slice_id - the statistics slice of a mailing list - once.
 */

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

    use AA\Optimize\AbstractOptimize;
    use AA\Optimize\AlertsStatSliceColumn;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class AlertsStatSliceColumnTest extends TestCase
    {
        /** the live alerts_collection: has the column, has not, or there is no table */
        private string $live = 'missing';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->live          = 'missing';
            RecordingDb::$answer = function (string $sql): array {
                if (self::alters()) {   // the answers come only for SELECTs - the ALTER shows in the log
                    $this->live = 'present';
                }
                return match (true) {
                    str_contains($sql, 'information_schema.tables')  => ($this->live === 'no table') ? [] : [['table_schema' => 'aa_test']],
                    str_contains($sql, 'information_schema.columns') => [['cnt' => ($this->live === 'present') ? 1 : 0]],
                    default                                         => [],
                };
            };
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            AbstractOptimize::justPrint(false);
            RecordingDb::reset();
        }

        /** @return string[] */
        private static function alters(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $s): bool => str_starts_with($s, 'ALTER')));
        }

        public function test_test_missingColumn_fails(): void
        {
            self::assertFalse((new AlertsStatSliceColumn())->test());

            $this->live = 'present';
            self::assertTrue((new AlertsStatSliceColumn())->test());
        }

        /** nullable without a default: MySQL then defaults it to NULL - no list measures until set */
        public function test_repair_addsColumn_once(): void
        {
            // the optimizer keeps the handle of its ALTER, the lookups after it need another one
            $GLOBALS['spareDBs'][] = new RecordingDb();
            $optimizer = new AlertsStatSliceColumn();
            self::assertTrue($optimizer->repair());
            self::assertTrue($optimizer->repair());
            self::assertTrue($optimizer->test());

            self::assertSame(['ALTER TABLE `alerts_collection` ADD `stat_slice_id` varbinary(16)'], self::alters());
        }

        public function test_repair_noTable_altersNothing(): void
        {
            // a new install: UpdateDbStructure creates the table whole, with the column
            $this->live = 'no table';

            self::assertTrue((new AlertsStatSliceColumn())->test());
            self::assertTrue((new AlertsStatSliceColumn())->repair());
            self::assertSame([], self::alters());
        }

        public function test_repair_justPrint_altersNothing(): void
        {
            AbstractOptimize::justPrint(true);

            self::assertTrue((new AlertsStatSliceColumn())->repair());
            self::assertSame([], self::alters());
        }

        public function test_types_sqlUpdate(): void
        {
            self::assertSame(['sql_update'], AlertsStatSliceColumn::types());
        }
    }
}
