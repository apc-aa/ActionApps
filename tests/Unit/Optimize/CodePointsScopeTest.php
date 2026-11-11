<?php

declare(strict_types=1);

/**
 * CodePoints searches template code wherever it lives: besides views, settings, spots and Code slices also the
 * Alerts e-mails and the item fields shown by f_y or edited by the code widget in any slice - and of such a
 * slice only those fields, so the text of an ordinary field is never taken for code.
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

    use AA\Optimize\CodePoints;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class CodePointsScopeTest extends TestCase
    {
        private const CODE  = 'c0dec0dec0dec0dec0dec0dec0dec0de';
        private const NEWS  = 'aaaa0000aaaa0000aaaa0000aaaa0000';
        private const PAGES = 'bbbb0000bbbb0000bbbb0000bbbb0000';
        private const SITE  = 'cccc0000cccc0000cccc0000cccc0000';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, 'AS lid FROM `slice`')                                => [['lid' => self::CODE]],
                str_contains($sql, 'AS sid, `id` FROM `field` WHERE alias1_func')        => [['sid' => self::NEWS, 'id' => 'full_text.......'], ['sid' => self::PAGES, 'id' => 'full_text.......'], ['sid' => self::CODE, 'id' => 'text...........1']],
                str_contains($sql, 'AS sid, `id` FROM `field` WHERE input_show_func')    => [['sid' => self::SITE, 'id' => 'code............']],
                str_starts_with($sql, 'SELECT `body` AS txt FROM `email`')               => [['txt' => '{view.php?vid=5}']],
                default                                                                  => [],
            };
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        /** @return string[] the item reads, one per scope */
        private static function itemReads(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'FROM `content`, `item`')));
        }

        public function test_texts_codeFieldsOfOrdinarySlices_readWithTheirSlicesOnly(): void
        {
            $texts = iterator_to_array(CodePoints::texts(), false);

            self::assertContains('{view.php?vid=5}', $texts);
            $reads = self::itemReads();
            self::assertCount(3, $reads, 'Code slices, the slices sharing full_text, the slice with the code widget');
            self::assertStringContainsString(self::CODE, $reads[0]);
            self::assertStringNotContainsString('field_id', $reads[0]);
            self::assertStringContainsString("'full_text.......'", $reads[1]);
            self::assertStringContainsString(self::NEWS, $reads[1]);
            self::assertStringContainsString(self::PAGES, $reads[1]);
            self::assertStringNotContainsString(self::CODE, $reads[1], 'a Code slice is read whole already');
            self::assertStringContainsString("'code............'", $reads[2]);
            self::assertStringNotContainsString("'full_text.......'", $reads[2]);
        }

        public function test_contains_inAnEmail_found(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => (str_contains($sql, '"email"') || str_contains($sql, '`email`')) && str_contains($sql, '`body`') ? [['x' => 1]] : [];

            self::assertTrue(CodePoints::contains('{view.php'));
        }
    }
}
