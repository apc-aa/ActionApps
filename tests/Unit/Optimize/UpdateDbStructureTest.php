<?php

declare(strict_types=1);

/**
 * UpdateDbStructure changes a differing table by ALTER TABLE in place. Copying it into a new table lost
 * the writes made during the copy (on a live e-shop the content table takes minutes), and it never
 * drops a column the template does not know.
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
    use AA\Optimize\UpdateDbStructure;
    use AA_MbT;
    use AA_Metabase;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class UpdateDbStructureTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            // one handle for the Optimize class, one for the DB_AA look-ups
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            AbstractOptimize::justPrint(false);
            RecordingDb::reset();
        }

        public const INDEXES = [
            'text'    => ['t' => 'content', 'n' => 'text', 's' => 'I', 'c' => [1 => ['text', '12'], 2 => 'field_id']],
            'item_id' => ['t' => 'content', 'n' => 'item_id', 's' => 'I', 'c' => [1 => 'item_id', 2 => 'field_id']],
        ];
        public const COLUMNS = [
            'item_id'  => ['item_id', 'varbinary(16)', false, 'MUL', '                ', '', ''],
            'field_id' => ['field_id', 'varbinary(16)', false, '', '                ', '', ''],
            'text'     => ['text', 'longtext', true, 'MUL', null, '', ''],
        ];

        /** the content table of the template with the changes */
        private static function content(array $columns = [], array $indexes = []): AA_MbT
        {
            return AA_MbT::fromArray([
                't' => 'content',
                'k' => [],
                'i' => array_filter(array_merge(self::INDEXES, $indexes)),
                'c' => array_filter(array_merge(self::COLUMNS, $columns)),
                'f' => null,
            ]);
        }

        public static function liveTables(): array
        {
            $item_nullable = ['item_id' => ['item_id', 'varbinary(16)', true, 'MUL', null, '', '']];
            return [
                'the same'                       => [self::content(), []],
                'NULL-able ids (aadb_utf, sasov)' => [
                    self::content($item_nullable + ['field_id' => ['field_id', 'varbinary(16)', true, '', null, '', '']]),
                    ["MODIFY COLUMN `item_id` varbinary(16) NOT NULL default '                '", "MODIFY COLUMN `field_id` varbinary(16) NOT NULL default '                '"],
                ],
                'columns in another order'      => [AA_MbT::fromArray(['t' => 'content', 'k' => [], 'i' => self::INDEXES, 'c' => array_reverse(self::COLUMNS, true), 'f' => null]), []],
                'char for varchar, no default' => [self::content(['field_id' => ['field_id', 'binary(16)', false, '', null, '', '']]), []],
                'missing column'                => [self::content(['text' => null]), ['ADD COLUMN `text` longtext']],
                'other index'                   => [
                    self::content([], ['text' => ['t' => 'content', 'n' => 'text', 's' => 'I', 'c' => [1 => ['text', '12']]]]),
                    ['DROP INDEX `text`', 'ADD KEY text (`text`(12),`field_id`)'],
                ],
                'index the template lacks'      => [self::content([], ['old' => ['t' => 'content', 'n' => 'old', 's' => 'I', 'c' => [1 => 'field_id']]]), []],
                'missing index'                 => [self::content([], ['item_id' => null]), ['ADD KEY item_id (`item_id`,`field_id`)']],
            ];
        }

        #[DataProvider('liveTables')]
        public function test_changes_liveTable_alterClausesToTheTemplate(AA_MbT $live, array $alter): void
        {
            self::assertSame($alter, UpdateDbStructure::changes(self::content(), $live)['alter']);
        }

        public function test_changes_primaryKey_droppedAndAdded(): void
        {
            $template = AA_MbT::fromArray(['t' => 'cron', 'k' => [], 'i' => ['PRIMARY' => ['t' => 'cron', 'n' => 'PRIMARY', 's' => 'P', 'c' => [1 => 'id']]], 'c' => ['id' => ['id', 'bigint(20)', false, 'PRI', null, 'auto_increment', '']], 'f' => null]);
            $live     = AA_MbT::fromArray(['t' => 'cron', 'k' => [], 'i' => ['PRIMARY' => ['t' => 'cron', 'n' => 'PRIMARY', 's' => 'P', 'c' => [1 => 'id', 2 => 'x']]], 'c' => ['id' => ['id', 'bigint(20)', false, 'PRI', null, 'auto_increment', '']], 'f' => null]);

            self::assertSame(['DROP PRIMARY KEY', 'ADD PRIMARY KEY (`id`)'], UpdateDbStructure::changes($template, $live)['alter']);
        }

        public static function widerColumns(): array
        {
            return [
                'longtext for mediumtext' => ['mediumtext', 'longtext'],
                'varbinary(32) for 16'    => ['varbinary(16)', 'varbinary(32)'],
                'mediumtext for varchar'  => ['varbinary(255)', 'mediumtext'],
            ];
        }

        #[DataProvider('widerColumns')]
        public function test_changes_liveColumnWider_keptAndListed(string $template_type, string $live_type): void
        {
            // a narrower column would cut the values (strict mode refuses it); a password hash or a long condition must stay
            $template = self::content(['text' => ['text', $template_type, true, 'MUL', null, '', '']]);
            $changes  = UpdateDbStructure::changes($template, self::content(['text' => ['text', $live_type, true, 'MUL', null, '', '']]));

            self::assertSame([], $changes['alter']);
            self::assertSame(["text $live_type"], $changes['wider']);
        }

        public function test_changes_liveColumnNarrower_widened(): void
        {
            $changes = UpdateDbStructure::changes(self::content(), self::content(['text' => ['text', 'text', true, 'MUL', null, '', '']]));

            self::assertSame(['MODIFY COLUMN `text` longtext'], $changes['alter']);
        }

        public function test_changes_columnAndIndexTheTemplateLacks_reportedNotDropped(): void
        {
            // a column may hold data, an index may be there for a slow query of the site (sasov change_record.change_id)
            $live    = self::content(['note' => ['note', 'text', true, '', null, '', '']], ['note' => ['t' => 'content', 'n' => 'note', 's' => 'I', 'c' => [1 => 'field_id']]]);
            $changes = UpdateDbStructure::changes(self::content(), $live);

            self::assertSame([], $changes['alter']);
            self::assertSame(['note', 'index note'], $changes['extra']);
        }

        /** UpdateDbStructure over these live tables (name => table) */
        private static function optimize(array $live): UpdateDbStructure
        {
            return new class ($live) extends UpdateDbStructure {
                public function __construct(private array $live) {}

                protected function templateMetabase(): AA_Metabase
                {
                    $template = new AA_Metabase();
                    $template->tables = [
                        'content' => AA_MbT::fromArray(['t' => 'content', 'k' => [], 'i' => UpdateDbStructureTest::INDEXES, 'c' => UpdateDbStructureTest::COLUMNS, 'f' => null]),
                        'cron'    => AA_MbT::fromArray(['t' => 'cron', 'k' => [], 'i' => [], 'c' => ['id' => ['id', 'bigint(20)', false, '', null, '', '']], 'f' => null]),
                    ];
                    return $template;
                }

                protected function liveMetabase(): AA_Metabase
                {
                    $metabase = new AA_Metabase();
                    $metabase->tables = $this->live;
                    return $metabase;
                }
            };
        }

        public function test_repair_nullableIdsOfABigTable_oneAlterWithoutLockInStrictModeThenModeBack(): void
        {
            // InnoDB makes the column NOT NULL in place and lets the e-shop write meanwhile
            self::rows(8500000);
            $live = self::content(['item_id' => ['item_id', 'varbinary(16)', true, 'MUL', null, '', '']]);

            self::assertFalse(self::optimize(['content' => $live, 'cron' => self::cron()])->test());
            self::assertTrue(self::optimize(['content' => $live, 'cron' => self::cron()])->repair());
            self::assertSame([
                "SET @aa_sql_mode = @@SESSION.sql_mode, SESSION sql_mode = 'STRICT_ALL_TABLES'",
                "ALTER TABLE `content` MODIFY COLUMN `item_id` varbinary(16) NOT NULL default '                ', LOCK=NONE",
                'SET SESSION sql_mode = @aa_sql_mode',
            ], array_values(array_filter(RecordingDb::$log, static fn($s) => !str_contains($s, 'information_schema'))));
        }

        public function test_repair_neverCopiesTheTable(): void
        {
            $live = self::content(['field_id' => ['field_id', 'varbinary(32)', true, '', null, '', ''], 'note' => ['note', 'text', true, '', null, '', '']], ['text' => null]);

            self::optimize(['content' => $live, 'cron' => self::cron()])->repair();

            self::assertSame(0, RecordingDb::count('INSERT') + RecordingDb::count('DROP TABLE') + RecordingDb::count('CREATE'));
            self::assertStringNotContainsString('RENAME', implode("\n", RecordingDb::$log));
            self::assertStringNotContainsString('`note`', implode("\n", RecordingDb::$log));
        }

        /** the content table as SHOW reports its rows */
        private static function rows(int $rows): void
        {
            RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'information_schema.TABLES') ? [['TABLE_ROWS' => $rows]] : [];
        }

        private static function alterLog(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'ALTER')));
        }

        public function test_repair_bigTableNeedingALock_listedNotRun(): void
        {
            // another column type cannot be changed while writes go on - on 8 million rows that stops the e-shop for minutes
            RecordingDb::$fail_on = 'ALTER TABLE `content`';
            self::rows(8500000);
            $optimize = self::optimize(['content' => self::content(['text' => ['text', 'mediumtext', true, 'MUL', null, '', '']]), 'cron' => self::cron()]);

            self::assertFalse($optimize->repair());
            self::assertSame(1, RecordingDb::count('SET SESSION sql_mode = @aa_sql_mode'));
            self::assertSame(1, RecordingDb::count('SET @aa_sql_mode'));
            self::assertStringContainsString('Run it when the traffic is low: ALTER TABLE `content` MODIFY COLUMN `text` longtext;', $optimize->report());
        }

        public function test_repair_smallTable_runWithoutAskingForNoLock(): void
        {
            // its lock lasts seconds; a refused LOCK=NONE would only add a database error to the log
            self::rows(1145);

            self::assertTrue(self::optimize(['content' => self::content(['text' => ['text', 'mediumtext', true, 'MUL', null, '', '']]), 'cron' => self::cron()])->repair());
            self::assertSame(['ALTER TABLE `content` MODIFY COLUMN `text` longtext'], self::alterLog());
        }

        public function test_repair_failingAlterOnSmallTable_reportsAndSetsTheModeBack(): void
        {
            // a NULL in a column that becomes NOT NULL stops the ALTER in strict mode; the table stays as it was
            RecordingDb::$fail_on = 'ALTER TABLE `content`';
            self::rows(10);
            $optimize = self::optimize(['content' => self::content(['item_id' => ['item_id', 'varbinary(16)', true, 'MUL', null, '', '']]), 'cron' => self::cron()]);

            self::assertFalse($optimize->repair());
            self::assertSame('SET SESSION sql_mode = @aa_sql_mode', end(RecordingDb::$log));
            self::assertStringContainsString('content not changed - the database refused the change', $optimize->report());
        }

        public function test_repair_missingTable_created(): void
        {
            self::assertTrue(self::optimize(['content' => self::content()])->repair());
            self::assertSame(1, RecordingDb::count('CREATE TABLE IF NOT EXISTS `cron`'));
            self::assertSame(0, RecordingDb::count('ALTER'));
        }

        public function test_test_sameStructure_ok(): void
        {
            self::assertTrue(self::optimize(['content' => self::content(), 'cron' => self::cron()])->test());
            self::assertSame([], RecordingDb::$log);
        }

        public function test_test_tableTheTemplateLacks_notOk(): void
        {
            self::assertFalse(self::optimize(['content' => self::content(), 'cron' => self::cron(), 'mine' => self::cron()])->test());
        }

        public function test_repair_justPrint_changesNothing(): void
        {
            AbstractOptimize::justPrint(true);

            self::optimize(['content' => self::content(['item_id' => ['item_id', 'varbinary(16)', true, 'MUL', null, '', '']]), 'cron' => self::cron()])->repair();

            self::assertSame(0, RecordingDb::count('ALTER') + RecordingDb::count('SET'));
        }

        private static function cron(): AA_MbT
        {
            return AA_MbT::fromArray(['t' => 'cron', 'k' => [], 'i' => [], 'c' => ['id' => ['id', 'bigint(20)', false, '', null, '', '']], 'f' => null]);
        }
    }
}
