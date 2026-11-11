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
            // the code as CodePoints reads it: a view, an e-mail and a field shown by f_y in an ordinary slice
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_starts_with($sql, 'SELECT DISTINCT group_id')                => array_map(static fn($g) => ['group_id' => $g], self::GROUPS),
                str_contains($sql, 'AS sid') && str_contains($sql, 'alias1_func') => [['sid' => self::SLICE, 'id' => 'text...........1']],
                str_starts_with($sql, 'SELECT `odd` AS txt FROM `view`')          => [['txt' => '{constant:in_view:x}']],
                str_starts_with($sql, 'SELECT `body` AS txt FROM `email`')        => [['txt' => '{options:in_email}']],
                str_contains($sql, 'AS iid FROM `item`')                         => [['iid' => 'bb11bb11bb11bb11bb11bb11bb11bb11']],
                str_starts_with($sql, 'SELECT `text` AS txt FROM `content`')      => str_contains($sql, "'text...........1'") ? [['txt' => '{constant:in_item:y}']] : [],
                default                                                           => [],
            };
        }

        private const SLICE = 'aa11aa11aa11aa11aa11aa11aa11aa11';

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

        public function test_getNotUsedGroups_readsTheCodeOnce(): void
        {
            // one read of all the code, not a LIKE scan per group (aadb: 100 slices with code fields, daily)
            self::optimize()->notUsed();

            self::assertSame(1, count(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'SELECT `text` AS txt FROM `content`'))));
            self::assertSame([], array_values(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, "LIKE '%"))));
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
