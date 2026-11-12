<?php

declare(strict_types=1);

/**
 * CodePoints searches template code wherever it lives: besides views, settings, spots and Code slices also the
 * item fields shown by f_y or edited by the code widget in any slice - and of such a slice only those fields,
 * so the text of an ordinary field is never taken for code - and the Alerts e-mails when the check asks.
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
                str_contains($sql, 'AS lid FROM `slice`')                             => [['lid' => self::CODE]],
                str_contains($sql, 'AS sid, `id` FROM `field` WHERE alias1_func')     => [['sid' => self::NEWS, 'id' => 'full_text.......'], ['sid' => self::PAGES, 'id' => 'full_text.......'], ['sid' => self::CODE, 'id' => 'text...........1']],
                str_contains($sql, 'AS sid, `id` FROM `field` WHERE input_show_func') => [['sid' => self::SITE, 'id' => 'code............']],
                str_contains($sql, 'AS iid FROM `item`')                              => self::items($sql),
                str_starts_with($sql, 'SELECT `body` AS txt FROM `email`')            => [['txt' => '{view.php?vid=5}']],
                default                                                               => [],
            };
        }

        /** 1200 items in the slice NEWS, one in each other */
        private static function items(string $sql): array
        {
            $count = str_contains($sql, self::NEWS) ? 1200 : 1;
            return array_map(static fn($i) => ['iid' => sprintf('%032x', $i + 1)], range(0, $count - 1));
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        /** @return string[] the reads of item content */
        private static function contentReads(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'SELECT `text` AS txt FROM `content`')));
        }

        public function test_texts_codeFieldsOfOrdinarySlices_readOnlyTheirFieldsInChunks(): void
        {
            iterator_to_array(CodePoints::texts(), false);

            $reads = self::contentReads();
            // CODE: 1 chunk, all fields; NEWS: 1200 items = 3 chunks; PAGES, SITE: 1 chunk each
            self::assertCount(6, $reads);
            self::assertStringNotContainsString('field_id', $reads[0], 'a Code slice is read whole');
            foreach (array_slice($reads, 1, 4) as $read) {
                self::assertStringContainsString("'full_text.......'", $read);
            }
            self::assertStringContainsString("'code............'", $reads[5]);
            self::assertStringNotContainsString("'full_text.......'", $reads[5]);
            self::assertSame(0, count(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'FROM `content`, `item`'))), 'no join over a whole scope');
        }

        public function test_texts_mails_onlyOnRequest(): void
        {
            self::assertNotContains('{view.php?vid=5}', iterator_to_array(CodePoints::texts(), false));
            self::assertContains('{view.php?vid=5}', iterator_to_array(CodePoints::texts(true), false));
        }

        public function test_contains_inAnEmail_onlyOnRequest(): void
        {
            // an e-mail is no page - a check about pages must not report it
            RecordingDb::$answer = static fn(string $sql): array => (str_contains($sql, '"email"') || str_contains($sql, '`email`')) && str_contains($sql, '`body`') ? [['x' => 1]] : [];

            self::assertFalse(CodePoints::contains('{view.php'));
            self::assertTrue(CodePoints::contains('{view.php', true));
        }
    }
}
