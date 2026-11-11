<?php

declare(strict_types=1);

/**
 * DbInconsistencyConstants: Repair deletes the groups it finds unused, so a group named anywhere AA can
 * use it must count as used - the core lists, the Alerts e-mails, item fields expanded as code in any slice
 * and the custom PHP of the install, besides fields, views and the template code points.
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

    use AA\Optimize\DbInconsistencyConstants;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class DbInconsistencyConstantsTest extends TestCase
    {
        private const GROUPS = ['in_view', 'lt_languages', 'AA_Core_Bins....', 'in_email', 'in_item', 'in_custom', ' lt_languages', 'unused'];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            // LIKE patterns escape the underscore
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_starts_with($sql, 'SELECT DISTINCT group_id')                      => array_map(static fn($g) => ['group_id' => $g], self::GROUPS),
                str_contains($sql, 'AS sid')                                           => str_contains($sql, 'input_show_func') ? [['sid' => 'aa11', 'id' => 'text...........1']] : [],
                self::from('view', $sql) && self::has('in_view', $sql)                 => [['x' => 1]],
                self::from('email', $sql) && self::has('in_email', $sql)               => [['x' => 1]],
                self::from('content', $sql) && self::has('in_item', $sql)              => [['x' => 1]],
                default                                                                 => [],
            };
        }

        /** DB_AA quotes table names either way */
        private static function from(string $table, string $sql): bool
        {
            return (bool) preg_match('/FROM [`"]' . $table . '[`"]/', $sql);
        }

        private static function has(string $group, string $sql): bool
        {
            return str_contains(str_replace('\\_', '_', $sql), $group);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private static function optimize(): DbInconsistencyConstants
        {
            return new class extends DbInconsistencyConstants {
                public function notUsed(): array
                {
                    return array_values($this->getNotUsedGroups());
                }

                protected function customCode(): string
                {
                    return "<?php\n\$langs = GetConstants('in_custom');";
                }
            };
        }

        public function test_getNotUsedGroups_namedAnywhere_kept(): void
        {
            self::assertSame(['unused'], self::optimize()->notUsed());
        }

        public function test_getNotUsedGroups_codeFieldItems_searchedInTheirSlices(): void
        {
            self::optimize()->notUsed();

            $probe = array_values(array_filter(RecordingDb::$log, static fn($s) => self::from('content', $s) && self::has('in_item', $s)));
            self::assertCount(1, $probe);
            self::assertStringContainsString('text...........1', $probe[0]);
        }

        public function test_repair_untrimmedName_neitherDeletedNorTrimmedToACoreGroup(): void
        {
            // " lt_languages" (se_constant.php does not trim a new group id) trimmed would delete the MLX languages
            self::optimize()->repair();

            $deletes = implode("\n", array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'DELETE')));
            self::assertStringNotContainsString('lt_languages', $deletes);
            self::assertStringContainsString("Spaces around the group name", (function () {
                $this->test();
                return $this->report();
            })->call(self::optimize()));
        }

        public function test_repair_deletesOnlyTheUnused(): void
        {
            self::assertTrue(self::optimize()->repair());

            $deletes = implode("\n", array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'DELETE')));
            self::assertStringContainsString("'unused'", $deletes);
            foreach (array_diff(self::GROUPS, ['unused']) as $kept) {
                self::assertStringNotContainsString("'$kept'", $deletes);
            }
        }
    }
}
