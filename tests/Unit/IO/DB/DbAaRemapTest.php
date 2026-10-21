<?php

declare(strict_types=1);

// The store fixtures load include/locsess.php (getDB()/freeDB()), which needs the constants
// include/config.php normally provides.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 4) . '/',
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

namespace Tests\Unit\IO\DB {

    use AA\IO\DB\DB_AA;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** DB_AA::remap() rewrites a column by a map in a single statement */
    final class DbAaRemapTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        public function test_remap_oneStatementWithCase(): void
        {
            RecordingDb::$affected = static fn(string $sql) => 7;

            $ret = DB_AA::remap('item', 'posted_by', ['a' => '1', "o'k" => '2', 'b\\c' => "x'\\y"]);

            $c   = DB_AA::quoteIdentifier('posted_by');
            $cmp = "CONVERT($c USING utf8mb4) COLLATE utf8mb4_bin";
            self::assertSame([
                'UPDATE ' . DB_AA::quoteIdentifier('item') . " SET $c = CASE $cmp"
                . " WHEN 'a' THEN '1' WHEN 'o\\'k' THEN '2' WHEN 'b\\\\c' THEN 'x\\'\\\\y' ELSE $c END"
                . " WHERE $cmp IN ('a', 'o\\'k', 'b\\\\c')",
            ], RecordingDb::$log);
            self::assertSame(7, $ret, 'affected rows of the statement');
        }

        public function test_remap_integerLikeKeysAreStrings(): void
        {
            DB_AA::remap('item', 'posted_by', ['9999999999' => 'x', '12' => '3']);

            self::assertStringContainsString("WHEN '12' THEN '3'", RecordingDb::$log[0]);
            self::assertStringContainsString("IN ('9999999999', '12')", RecordingDb::$log[0]);
        }

        public function test_remap_chainIsOneStatement(): void
        {
            DB_AA::remap('item', 'posted_by', ['a' => 'b', 'b' => 'c']);

            $c   = DB_AA::quoteIdentifier('posted_by');
            $cmp = "CONVERT($c USING utf8mb4) COLLATE utf8mb4_bin";
            self::assertSame([
                'UPDATE ' . DB_AA::quoteIdentifier('item') . " SET $c = CASE $cmp WHEN 'a' THEN 'b' WHEN 'b' THEN 'c' ELSE $c END"
                . " WHERE $cmp IN ('a', 'b')",
            ], RecordingDb::$log, 'one statement, so a row once a ends as b, not c');
        }

        public function test_asText_exactOrCaseInsensitive(): void
        {
            $c = DB_AA::quoteIdentifier('posted_by');
            self::assertSame("CONVERT($c USING utf8mb4) COLLATE utf8mb4_bin", DB_AA::asText('posted_by'));
            self::assertSame("CONVERT($c USING utf8mb4) COLLATE utf8mb4_general_ci", DB_AA::asText('posted_by', false));
        }

        public function test_remap_emptyMapDoesNothing(): void
        {
            self::assertSame(0, DB_AA::remap('item', 'posted_by', []));
            self::assertSame([], RecordingDb::$log);
        }
    }
}
