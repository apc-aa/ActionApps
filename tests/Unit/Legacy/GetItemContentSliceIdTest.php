<?php

declare(strict_types=1);

/**
 * GetItemContent() reads the slice of every item it returns (u_slice_id......), also when it skips
 * the reading-password check and only some fields are asked for - the cron jobs and State Checks
 * read so. The DB is a RecordingDb answering only the columns the SELECT names.
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

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use zids;

    final class GetItemContentSliceIdTest extends TestCase
    {
        private const SLICE = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';
        private const ITEM  = '0123456789abcdef0123456789abcdef';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';    // the legacy boot, no DB
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql): array => self::answer($sql);
        }

        protected function tearDown(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [];
        }

        /** @return array<string, array{list<string>, list<string>}> fields asked for, keys of the item's content */
        public static function fieldLists(): array
        {
            return [
                'a content field'                => [['headline........'], ['headline........', 'u_slice_id......', 'unpacked_id.....']],
                'with a field of the item table' => [['headline........', 'publish_date....'], ['headline........', 'publish_date....', 'u_slice_id......', 'unpacked_id.....']],
            ];
        }

        /**
         * slice_id is read for u_slice_id......, never returned as a field nobody asked for
         * @param list<string> $fields
         * @param list<string> $keys
         */
        #[DataProvider('fieldLists')]
        public function test_GetItemContent_readingPasswordIgnored_sliceOfTheItemRead(array $fields, array $keys): void
        {
            $content = GetItemContent(new zids([self::ITEM], 'l'), false, true, $fields);

            $select = (string) current(array_filter(RecordingDb::$log, static fn(string $s): bool => str_contains($s, ' FROM item ')));
            self::assertMatchesRegularExpression('/^SELECT [^ ]*\bslice_id\b[^ ]* FROM item /', $select);
            self::assertSame(self::SLICE, $content[self::ITEM]['u_slice_id......'][0]['value'] ?? null);
            self::assertSame('Brno', $content[self::ITEM]['headline........'][0]['value'] ?? null);
            $returned = array_keys($content[self::ITEM]);
            sort($returned);
            self::assertSame($keys, $returned);
        }

        /** the columns the item SELECT names, of one stored item; its content rows */
        private static function answer(string $sql): array
        {
            if (preg_match('/^SELECT (.+?) FROM item WHERE/', $sql, $m)) {
                $row = ['id' => pack_id(self::ITEM), 'slice_id' => pack_id(self::SLICE), 'publish_date' => '1700000000', 'status_code' => '1'];
                return [array_intersect_key($row, array_flip(explode(',', $m[1])))];
            }
            if (str_starts_with($sql, 'SELECT * FROM content WHERE')) {
                return [['item_id' => pack_id(self::ITEM), 'field_id' => 'headline........', 'number' => 0, 'text' => 'Brno', 'flag' => FLAG_TEXT_STORED]];
            }
            return [];
        }
    }
}
