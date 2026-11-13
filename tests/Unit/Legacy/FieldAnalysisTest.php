<?php

declare(strict_types=1);

/**
 * AA_Field::getAnalysis() - the "Analyze" row of the Fields page lists the first characters of up to four
 * distinct values: as text, and never of a password or secret field.
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

    use AA_Field;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class FieldAnalysisTest extends TestCase
    {
        private const SLICE = '0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, 'SUBSTRING(text,1,12)')  => [['text' => '<b>bold</b>', 'txt' => '<b>bold</b>']],
                str_contains($sql, 'count(DISTINCT text)')  => [['cnt' => 1, 'cntval' => 1, 'cntnum' => 1, 'cntitm' => 1]],
                str_starts_with($sql, 'SELECT count(*)')    => [['cnt' => 1]],
                default                                     => [],
            };
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private static function analysis(string $id): string
        {
            return (new AA_Field(['id' => $id, 'slice_id' => pack_id(self::SLICE), 'text_stored' => 1] + array_fill_keys(AA_Field::USED_FIELDS, '')))->getAnalysis();
        }

        public function test_getAnalysis_valuesAreText(): void
        {
            $out = self::analysis('text...........1');
            self::assertStringContainsString('("&lt;b&gt;bold&lt;/b&gt;")', $out);
            self::assertStringNotContainsString('<b>bold', $out);
        }

        public function test_getAnalysis_secretField_showsNoValue(): void
        {
            $out = self::analysis('secret.........1');
            self::assertStringNotContainsString('bold', $out);
            self::assertSame(0, \count(array_filter(RecordingDb::$log, static fn(string $s): bool => str_contains($s, 'SUBSTRING('))));
        }
    }
}
