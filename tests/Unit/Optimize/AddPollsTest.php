<?php

declare(strict_types=1);

/**
 * AddPolls (part of sql_update) adds the Polls module tables and the template poll, keeping the polls
 * a site already has.
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
    use AA\Optimize\AddPolls;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class AddPollsTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            AbstractOptimize::justPrint(false);
            RecordingDb::reset();
        }

        /** the live database: these tables exist, the template module does or does not */
        private static function live(array $tables, bool $template): void
        {
            RecordingDb::$answer = static function (string $sql) use ($tables, $template): array {
                if (str_contains($sql, 'information_schema.tables')) {
                    foreach ($tables as $table) {
                        if (str_contains($sql, "'$table'")) {
                            return [['table_schema' => 'aa_test']];
                        }
                    }
                    return [];
                }
                return (str_contains($sql, 'module') && $template) ? [['id' => 'PollsTemplate...']] : [];
            };
        }

        private const TABLES = ['polls', 'polls_answer', 'polls_design', 'polls_ip_lock', 'polls_log'];

        public function test_repair_tablesWithPolls_keptNoDrop(): void
        {
            // the template module is missing, the site's polls and votes are not
            self::live(self::TABLES, false);

            self::assertTrue((new AddPolls())->repair());
            self::assertSame(0, RecordingDb::count('DROP'));
            self::assertSame(5, RecordingDb::count('CREATE TABLE IF NOT EXISTS'));
            self::assertSame(1, RecordingDb::count('INSERT IGNORE INTO `polls` '));
            self::assertSame(0, RecordingDb::count('REPLACE INTO `polls'));
            self::assertSame(1, RecordingDb::count('REPLACE'));
        }

        public function test_repair_oldTableRefusesTheTemplate_reportedNotThrown(): void
        {
            // a table UpdateDbStructure could not change must not stop the rest of sql_update
            self::live(self::TABLES, false);
            RecordingDb::$fail_on    = 'INSERT IGNORE INTO `polls` ';
            RecordingDb::$fail_error = "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'params' in 'field list'";
            $optimize = new AddPolls();

            self::assertFalse($optimize->repair());
            self::assertStringContainsString('The template poll not added - the database refused it: SQLSTATE[42S22]', $optimize->report());
            self::assertSame(0, RecordingDb::count('REPLACE'), 'no module without its template poll - test() would pass and the repair never run again');
        }

        public function test_test_allThere_ok(): void
        {
            self::live(self::TABLES, true);

            self::assertTrue((new AddPolls())->test());
        }

        public function test_test_tableMissing_fails(): void
        {
            self::live(['polls', 'polls_answer', 'polls_design', 'polls_ip_lock'], true);
            $optimize = new AddPolls();

            self::assertFalse($optimize->test());
            self::assertStringContainsString('polls_log is missing', $optimize->report());
        }

        public function test_test_noTemplateModule_fails(): void
        {
            self::live(self::TABLES, false);

            self::assertFalse((new AddPolls())->test());
        }
    }
}
