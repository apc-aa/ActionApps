<?php

declare(strict_types=1);

/**
 * AddContentFieldIndex brings the content text index to the template's (text(12), field_id) and drops
 * the field-first index (field_id, text) - whatever the live table has, in one ALTER.
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
    use AA\Optimize\AddContentFieldIndex;
    use AA\Optimize\UpdateDbStructure;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class AddContentFieldIndexTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            AbstractOptimize::justPrint(false);
            RecordingDb::reset();
        }

        private const PLAIN_TEXT  = ['text' => [['text', 12]]];
        private const TEXT_FIELD  = ['text' => [['text', 12], ['field_id', null]]];
        private const FIELD_FIRST = ['field_text' => [['field_id', null], ['text', 12]]];
        private const OTHERS      = ['item_id' => [['item_id', null], ['field_id', null], ['text', 16]], 'number' => [['item_id', null], ['number', null]]];

        /** the live content table with these indexes (name => [[column, sub_part], ...]); null = no table */
        private function liveIndexes(?array $indexes): void
        {
            $rows = [];
            foreach ($indexes ?? [] as $name => $columns) {
                foreach ($columns as $seq => [$column, $sub_part]) {
                    $rows[] = ['Table' => 'content', 'Non_unique' => 1, 'Key_name' => $name, 'Seq_in_index' => $seq + 1, 'Column_name' => $column, 'Sub_part' => $sub_part];
                }
            }
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, 'information_schema.tables') => ($indexes === null) ? [] : [['table_schema' => 'aa_test']],
                str_starts_with($sql, 'SHOW INDEX')            => $rows,
                default                                        => [],
            };
        }

        private static function alters(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'ALTER')));
        }

        public static function liveStates(): array
        {
            $add = 'ADD KEY text (`text`(12),`field_id`)';
            return [
                'plain text(12)'           => [self::PLAIN_TEXT, "DROP INDEX `text`, $add"],
                'text(12) and field_text'  => [self::PLAIN_TEXT + self::FIELD_FIRST, "DROP INDEX `field_text`, DROP INDEX `text`, $add"],
                'done, field_text left'    => [self::TEXT_FIELD + self::FIELD_FIRST, 'DROP INDEX `field_text`'],
                'no text index'            => [[], $add],
                'only field_text'          => [self::FIELD_FIRST, "DROP INDEX `field_text`, $add"],
                'text index, other prefix' => [['text' => [['text', 10], ['field_id', null]]], "DROP INDEX `text`, $add"],
            ];
        }

        #[DataProvider('liveStates')]
        public function test_repair_liveIndexes_oneAlterToTheTemplate(array $live, string $changes): void
        {
            $this->liveIndexes($live + self::OTHERS);

            self::assertFalse((new AddContentFieldIndex())->test());
            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame(["ALTER TABLE `content` $changes"], self::alters());
        }

        public function test_repair_shortLockWaitAroundTheAlter_thenSetBack(): void
        {
            // the server waits a day for a lock by default - the queries queued behind the ALTER would stop the site
            $this->liveIndexes(self::PLAIN_TEXT + self::OTHERS);

            self::assertTrue((new AddContentFieldIndex())->repair());
            $log = array_values(array_filter(RecordingDb::$log, static fn($s) => !preg_match('/^(SELECT|SHOW)\s/', $s)));
            self::assertStringContainsString('SESSION lock_wait_timeout = 5', $log[0]);
            self::assertStringStartsWith('ALTER TABLE `content`', $log[1]);
            self::assertSame('SET SESSION sql_mode = @aa_sql_mode, SESSION lock_wait_timeout = @aa_lock_wait', $log[2]);
        }

        public function test_repair_tableInUse_failsTryLater(): void
        {
            // a long query holds the table: the ALTER gives up after seconds, the test after it fails again
            $this->liveIndexes(self::PLAIN_TEXT + self::OTHERS);
            RecordingDb::$fail_on    = 'ALTER TABLE `content`';
            RecordingDb::$fail_error = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction';
            $optimize = new AddContentFieldIndex();

            self::assertFalse($optimize->repair());
            self::assertStringContainsString('other queries are using it. Try it again later.', $optimize->report());
            self::assertSame(1, RecordingDb::count('SET SESSION sql_mode = @aa_sql_mode'));
        }

        public function test_repair_refused_failsWithTheError(): void
        {
            $this->liveIndexes(self::PLAIN_TEXT + self::OTHERS);
            RecordingDb::$fail_on    = 'ALTER TABLE `content`';
            RecordingDb::$fail_error = 'SQLSTATE[HY000]: General error: 1799 Creating index text required more than innodb_online_alter_log_max_size';
            $optimize = new AddContentFieldIndex();

            self::assertFalse($optimize->repair());
            self::assertStringContainsString('the database refused the change: SQLSTATE[HY000]: General error: 1799', $optimize->report());
        }

        public function test_repair_done_altersNothing(): void
        {
            // the ALTER takes minutes on a big database - a second run must not repeat it
            $this->liveIndexes(self::TEXT_FIELD + self::OTHERS);

            self::assertTrue((new AddContentFieldIndex())->test());
            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame([], self::alters());
        }

        public function test_repair_noContentTable_leavesItToTheStructureUpdate(): void
        {
            // a new install: UpdateDbStructure creates the table with the index afterwards
            $this->liveIndexes(null);

            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame([], self::alters());
            self::assertSame(0, RecordingDb::count('SHOW'));
        }

        public function test_repair_justPrint_altersNothing(): void
        {
            // sql_update.php without "Write to database" only lists the SQL
            $this->liveIndexes(self::PLAIN_TEXT + self::FIELD_FIRST + self::OTHERS);
            AbstractOptimize::justPrint(true);

            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame([], self::alters());
        }

        public function test_priority_runsBeforeUpdateDbStructure(): void
        {
            // the structure update would otherwise copy the whole content table to add the index
            self::assertLessThan(UpdateDbStructure::priority(), AddContentFieldIndex::priority());
        }
    }
}
