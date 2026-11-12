<?php

declare(strict_types=1);

/**
 * CentralTokenColumn widens central_conf.AA_ADMIN_PWD to the template's length - a shorter column cuts the
 * encrypted login tokens of Central without an error - and State Check reports the short column as an error.
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
    use AA\Optimize\CentralTokenColumn;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class CentralTokenColumnTest extends TestCase
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

        /** the live central_conf with AA_ADMIN_PWD of this type; '' = without the column, null = no table */
        private function liveColumn(?string $type): void
        {
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, 'information_schema.tables')  => ($type === null) ? [] : [['table_schema' => 'aa_test']],
                str_contains($sql, 'information_schema.COLUMNS') => $type ? [['COLUMN_TYPE' => $type]] : [],
                default                                         => [],
            };
        }

        private static function alters(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'ALTER')));
        }

        public static function shortColumns(): array
        {
            return [
                'old databases'      => ['varbinary(30)', 'ALTER TABLE `central_conf` MODIFY `AA_ADMIN_PWD` varbinary(1024) NOT NULL'],
                'varchar, shorter'   => ['varchar(255)', 'ALTER TABLE `central_conf` MODIFY `AA_ADMIN_PWD` varbinary(1024) NOT NULL'],
                'tinyblob, 255'      => ['tinyblob', 'ALTER TABLE `central_conf` MODIFY `AA_ADMIN_PWD` varbinary(1024) NOT NULL'],
                'tinytext, 255'      => ['tinytext', 'ALTER TABLE `central_conf` MODIFY `AA_ADMIN_PWD` varbinary(1024) NOT NULL'],
                'no column'          => ['', 'ALTER TABLE `central_conf` ADD `AA_ADMIN_PWD` varbinary(1024) NOT NULL'],
            ];
        }

        #[DataProvider('shortColumns')]
        public function test_repair_shortColumn_widensItToTheTemplate(string $type, string $alter): void
        {
            $this->liveColumn($type);

            $findings = (new CentralTokenColumn())->findings();
            self::assertSame(Finding::ERROR, $findings[0]->severity);
            self::assertTrue($findings[0]->repairable);
            self::assertFalse((new CentralTokenColumn())->test());
            self::assertTrue((new CentralTokenColumn())->repair());
            self::assertSame([$alter], self::alters());
        }

        public static function longColumns(): array
        {
            return [
                'the template'    => ['varbinary(1024)'],
                'longer'          => ['varbinary(2048)'],
                'blob, no length' => ['blob'],
                'text, no length' => ['mediumtext'],
            ];
        }

        #[DataProvider('longColumns')]
        public function test_repair_longEnough_altersNothing(string $type): void
        {
            $this->liveColumn($type);

            self::assertSame(Finding::OK, (new CentralTokenColumn())->findings()[0]->severity);
            self::assertTrue((new CentralTokenColumn())->test());
            self::assertTrue((new CentralTokenColumn())->repair());
            self::assertSame([], self::alters());
        }

        public function test_findings_noTable_ok(): void
        {
            // a new install: UpdateDbStructure creates the table with the column of the template
            $this->liveColumn(null);

            self::assertSame(Finding::OK, (new CentralTokenColumn())->findings()[0]->severity);
            self::assertTrue((new CentralTokenColumn())->repair());
            self::assertSame([], self::alters());
        }

        public function test_repair_justPrint_altersNothing(): void
        {
            // sql_update.php without "Write to database" only lists the SQL
            $this->liveColumn('varbinary(30)');
            AbstractOptimize::justPrint(true);

            self::assertTrue((new CentralTokenColumn())->repair());
            self::assertSame([], self::alters());
        }

        public function test_types_stateCheckOnly(): void
        {
            // the update of a database widens the column with the others (UpdateDbStructure)
            self::assertSame([AbstractOptimize::CHECK_BASIC], CentralTokenColumn::types());
        }
    }
}
