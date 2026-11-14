<?php

declare(strict_types=1);

/**
 * CodePoints searches template code wherever it lives: besides views, settings, spots and Code slices also the
 * item fields shown by f_y or edited by the code widget in any slice - and of such a slice only those fields,
 * so the text of an ordinary field is never taken for code - and the Alerts e-mails when the check asks.
 * Of object_text only the properties holding code, never the data other objects keep there.
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

        /** object_text rows as AA_Object::save() writes them: [object id, class (its aa_type), property, value] */
        private const OBJECTS = [
            ['a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 'AA_Aliasfunc', 'code', '<b>{debuglog:x}</b>'],
            ['a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 'AA_Aliasfunc', 'ussage', '{_:Box} - remove {debuglog} before going live'],
            ['b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', 'AA\Seo\Crawl\Run', 'pages', '[{"url":"/expressions/debuglog","text":"{debuglog:...}"}]'],
            ['c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3', 'AA\Util\Note', 'text', 'try {debuglog} here'],
            ['d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4', 'AA_Modulesettings_Site', 'ga_id', 'UA-12254263-1'],
            ['d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4', 'AA_Modulesettings_Site', 'alternate_url', 'https://example.org/'],
        ];

        /** A read of object_text answered as the server would, roughly: a read not scoped by class gets every row
         *  holding the needle, a scoped one those of the class and property it names. Other tables are empty. */
        private static function answerObjects(): void
        {
            RecordingDb::$answer = static function (string $sql): array {
                if (!str_contains($sql, 'object_text')) {
                    return [];
                }
                $scoped = str_contains($sql, "'aa_type'");
                $needle = preg_match("/LIKE '%(.*?)%'/", $sql, $m) ? stripslashes($m[1]) : '';
                $rows   = array_filter(self::OBJECTS, static fn(array $o): bool => str_contains($o[3], $needle)
                    && (!$scoped || (str_contains($sql, "'" . addslashes($o[1]) . "'") && str_contains($sql, "'$o[2]'"))));
                return array_map(static fn(array $o): array => ['iid' => $o[0], 'txt' => $o[3], 'x' => 1], array_values($rows));
            };
        }

        public function test_containing_dataObjects_notTakenForCode(): void
        {
            self::answerObjects();

            $rows = iterator_to_array(CodePoints::containing('{debuglog'), false);

            // a crawled page, a note or an alias's usage example only mention the expression - never run it
            self::assertSame([['table' => 'object_text', 'column' => 'value', 'iid' => 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 'txt' => '<b>{debuglog:x}</b>']], $rows);
        }

        public function test_texts_dataObjects_notTakenForCode(): void
        {
            self::answerObjects();

            self::assertSame(['<b>{debuglog:x}</b>', 'UA-12254263-1'], iterator_to_array(CodePoints::texts(), false));
        }

        public function test_containing_analyticsIdOfSiteSettings_found(): void
        {
            // {generate:HEAD} prints the snippet with ga_id - CheckUniversalAnalytics finds an old UA id there
            self::answerObjects();

            $rows = iterator_to_array(CodePoints::containing('UA-'), false);

            self::assertSame(['d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4'], array_column($rows, 'iid'));
        }

        public function test_contains_onlyInADataObject_false(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => (str_contains($sql, 'object_text') && !str_contains($sql, "'aa_type'")) ? [['x' => 1]] : [];

            self::assertFalse(CodePoints::contains('{debuglog'));
        }

        /** a renamed property would silently drop its code from every check */
        public function test_objectCode_namesPropertiesTheClassesHave(): void
        {
            foreach (CodePoints::OBJECT_CODE as $class => $props) {
                self::assertTrue(class_exists($class), $class);
                foreach ($props as $prop) {
                    self::assertArrayHasKey($prop, $class::getClassProperties(), "$class::$prop");
                }
            }
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
