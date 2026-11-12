<?php

declare(strict_types=1);

/**
 * AA_Temporary_Table (include/statestore.php) - the table the Optimize list (State Check) filters and sorts on.
 *
 * Pins: text columns hold whole texts - VARCHAR(255) cut descriptions, so a word further in was never
 * found, and a strict sql_mode failed the page with 1406; the table is dropped first - a persistent
 * connection keeps the temporary table of an earlier request, and CREATE ... IF NOT EXISTS reused it
 * with its old columns and rows.
 *
 * The DB is a RecordingDb answering SHOW FULL COLUMNS from the logged CREATE; no database is involved.
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

namespace Tests\Unit\Legacy {

    use AA\Optimize\AbstractOptimize;
    use AA_Content;
    use AA_Temporary_Table;
    use AA_Value;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class TemporaryTableTest extends TestCase
    {
        private const TABLE = 'tmp_components_test';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            RecordingDb::$answer = static function (string $sql): array {
                if (!str_starts_with($sql, 'SHOW FULL COLUMNS') || !preg_match('/\((.*)\)/', self::statement('CREATE'), $m)) {
                    return [];
                }
                return array_map(static function (string $column): array {
                    [$field, $type] = explode(' ', $column, 2);
                    return ['Field' => $field, 'Type' => strtolower($type), 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''];
                }, explode(',', $m[1]));
            };
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private static function statement(string $verb): string
        {
            return current(array_filter(RecordingDb::$log, static fn($sql) => str_starts_with($sql, $verb))) ?: '';
        }

        /** the Optimize list as admin/aa_optimize.php builds it, one component with a long description */
        private static function create(string $description): void
        {
            $content = (new AA_Content())->setAaValue('aa_id', new AA_Value('CheckLong'))
                ->setAaValue('description', new AA_Value($description))
                ->setAaValue('priority', new AA_Value(100));
            new AA_Temporary_Table(self::TABLE, AbstractOptimize::getClassProperties(), [$content]);
        }

        public function test_create_textColumnsHoldTextsLongerThan255Characters(): void
        {
            $description = str_repeat('x', 300) . ' convertfrom';
            self::create($description);

            $create = self::statement('CREATE');
            $this->assertStringContainsString('description MEDIUMTEXT', $create);
            $this->assertStringContainsString('priority INT', $create);
            $this->assertStringNotContainsString('VARCHAR', $create);
            $this->assertStringEndsWith(' ENGINE=InnoDB', $create);   // MEMORY, a possible default, refuses TEXT
            $this->assertStringContainsString("'$description'", self::statement('INSERT'));
        }

        public function test_create_dropsTheTableAPersistentConnectionKeptFromAnEarlierRequest(): void
        {
            self::create('short');

            $verbs = array_map(static fn($sql) => strtok($sql, ' '), RecordingDb::$log);
            $this->assertSame('DROP TEMPORARY TABLE IF EXISTS ' . self::TABLE, self::statement('DROP'));
            $this->assertLessThan(array_search('CREATE', $verbs, true), array_search('DROP', $verbs, true));
            $this->assertStringStartsWith('CREATE TEMPORARY TABLE ' . self::TABLE . ' (', self::statement('CREATE'));
        }
    }
}
