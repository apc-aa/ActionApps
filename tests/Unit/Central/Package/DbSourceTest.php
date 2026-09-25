<?php

declare(strict_types=1);

/**
 * DbSource against a RecordingDb (tests/Unit/Legacy/fixtures/store_fixtures.php): the statements it
 * sends - values only through DB_AA::makeWhere() - and the unpacking of the rows. No database.
 */

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

namespace Tests\Unit\Central\Package {

    use AA\Central\Package\DbSource;
    use InvalidArgumentException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class DbSourceTest extends TestCase
    {
        private const SLICE = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
        private const OTHER = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';
        private const ITEM  = '0123456789abcdef0123456789abcdef';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../../include/util.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        public function test_select_sends_the_conditions_and_unpacks_the_ids(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => [
                ['id' => hex2bin(self::SLICE), 'name' => 'A', 'owner' => hex2bin(self::OTHER), 'mlxctrl' => null],
            ];

            $rows = (new DbSource())->select('slice', [['id', [self::SLICE, self::OTHER], 'l'], ['name', "it's", 's'], ['flag', '1 OR 1', 'i']]);

            $this->assertSame([['id' => self::SLICE, 'name' => 'A', 'owner' => self::OTHER, 'mlxctrl' => null]], $rows);   // NULL stays NULL
            $this->assertSame(['SELECT * FROM "slice" WHERE id IN (0x' . self::SLICE . ',0x' . self::OTHER . ") AND name = 'it\\'s' AND flag = 1"], RecordingDb::$log);
        }

        public function test_itemIds_of_the_active_bin_from_the_start(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => [['unpackid' => self::ITEM]];

            $this->assertSame([self::ITEM], (new DbSource())->itemIds(self::SLICE, false, '', 501));
            $this->assertSame(['SELECT LOWER(HEX("id")) AS unpackid FROM "item" WHERE slice_id = 0x' . self::SLICE . ' AND status_code = 1 ORDER BY "id" LIMIT 501'], RecordingDb::$log);
        }

        public function test_itemIds_of_all_bins_after_the_cursor(): void
        {
            (new DbSource())->itemIds(self::SLICE, true, self::ITEM, 3);
            $this->assertSame(['SELECT LOWER(HEX("id")) AS unpackid FROM "item" WHERE slice_id = 0x' . self::SLICE . ' AND "id" > 0x' . self::ITEM . ' ORDER BY "id" LIMIT 3'], RecordingDb::$log);
        }

        public function test_itemIds_refuse_a_cursor_of_no_id(): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new DbSource())->itemIds(self::SLICE, true, "0x0 OR 1=1", 3);
        }

        public function test_items_add_the_2fa_secrets_GetItemContent_leaves_out(): void
        {
            $item = array_fill_keys(\AA_Metabase::singleton()->getColumnNames('item'), '');
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, "LIKE 'secret%'") => [['item_id' => self::ITEM, 'field_id' => 'secret..........', 'text' => 'JBSWY3DP', 'flag' => 0]],
                str_contains($sql, 'FROM item')      => [['id' => hex2bin(self::ITEM), 'slice_id' => hex2bin(self::SLICE)] + $item],
                str_contains($sql, 'FROM content')   => [['item_id' => hex2bin(self::ITEM), 'field_id' => 'headline........', 'number' => null, 'text' => 'Jan', 'flag' => 64]],
                default                              => [],
            };

            $items = iterator_to_array((new DbSource())->items([self::ITEM], true), false);

            $this->assertSame(self::ITEM, $items[0]['id']);
            $this->assertSame([['value' => 'Jan', 'flag' => 64]], $items[0]['content']['headline........']);
            $this->assertSame([['value' => 'JBSWY3DP', 'flag' => 0]], $items[0]['content']['secret..........']);
            $this->assertSame('SELECT LOWER(HEX("item_id")) AS item_id, field_id, text, flag FROM "content" WHERE item_id = 0x' . self::ITEM . " AND field_id LIKE 'secret%' ORDER BY item_id,number", end(RecordingDb::$log));
        }

        public function test_items_read_no_secret_unless_asked(): void
        {
            $item                = array_fill_keys(\AA_Metabase::singleton()->getColumnNames('item'), '');
            RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'FROM item') ? [['id' => hex2bin(self::ITEM), 'slice_id' => hex2bin(self::SLICE)] + $item] : [];

            $items = iterator_to_array((new DbSource())->items([self::ITEM], false), false);

            $this->assertSame(self::ITEM, $items[0]['id']);
            $this->assertSame([], array_filter(RecordingDb::$log, static fn(string $sql): bool => str_contains($sql, 'secret')));
        }

        public function test_the_2fa_field_is_one_of_the_secrets(): void
        {
            class_exists(\AA_Module_Site::class);     // include/constants.php
            $this->assertStringStartsWith('secret', FIELDID_2FA_SECRET);    // the prefix GetItemContent() leaves out
        }

        public function test_logins_are_the_login_names_of_the_active_readers(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => [['text' => 'jan'], ['text' => 'eva']];

            $this->assertSame(['jan', 'eva'], (new DbSource())->logins(self::SLICE, false));
            $this->assertSame(['SELECT "content"."text" FROM "content" INNER JOIN "item" ON "item"."id" = "content"."item_id" WHERE item.slice_id = 0x' . self::SLICE
                . " AND content.field_id = 'headline........' AND item.status_code = 1"], RecordingDb::$log);
        }

        public function test_the_owner_of_a_module_is_readable(): void
        {
            (new DbSource())->select('slice_owner', [['id', self::OTHER, 'l']]);
            $this->assertSame(['SELECT * FROM "slice_owner" WHERE id = 0x' . self::OTHER], RecordingDb::$log);
        }

        public function test_objectIds_are_the_objects_the_module_owns(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => [['object_id' => 'aaaa']];

            $this->assertSame(['aaaa'], (new DbSource())->objectIds(self::SLICE));
            $this->assertSame(['SELECT object_id FROM "object_text" WHERE property = \'aa_owner\' AND value = \'' . self::SLICE . '\''], RecordingDb::$log);
        }

        public function test_objectRows_reads_the_three_object_tables(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, 'object_text')    => [['object_id' => 'aaaa', 'property' => 'aa_type', 'priority' => 0, 'value' => 'AA_Set']],
                str_contains($sql, 'object_integer') => [['object_id' => 'aaaa', 'property' => 'x', 'priority' => 1, 'value' => 5]],
                default                              => [],
            };

            $rows = (new DbSource())->objectRows(['aaaa', 'bbbb'], ['aa_type', 'x']);

            $this->assertSame([
                ['table' => 'object_text', 'object_id' => 'aaaa', 'property' => 'aa_type', 'priority' => 0, 'value' => 'AA_Set'],
                ['table' => 'object_integer', 'object_id' => 'aaaa', 'property' => 'x', 'priority' => 1, 'value' => 5],
            ], $rows);
            $where = " WHERE object_id IN ('aaaa','bbbb') AND property IN ('aa_type','x')";
            $this->assertSame([
                'SELECT object_id, property, priority, value FROM "object_text"' . $where,
                'SELECT object_id, property, priority, value FROM "object_integer"' . $where,
                'SELECT object_id, property, priority, value FROM "object_float"' . $where,
            ], RecordingDb::$log);
        }

        public function test_the_helper_properties_are_read_from_object_text_only(): void
        {
            (new DbSource())->objectRows(['aaaa'], ['aa_type', 'aa_subobjects']);

            $this->assertSame(['SELECT object_id, property, priority, value FROM "object_text" WHERE object_id = \'aaaa\' AND property IN (\'aa_type\',\'aa_subobjects\')'], RecordingDb::$log);
        }

        public function test_objectRows_chunks_the_ids_and_reads_nothing_for_none(): void
        {
            $this->assertSame([], (new DbSource())->objectRows([]));
            $this->assertSame([], RecordingDb::$log);

            (new DbSource())->objectRows(array_map(static fn(int $i): string => sprintf('%032x', $i), range(1, DbSource::OBJECT_CHUNK + 1)));
            $this->assertCount(6, RecordingDb::$log);        // two chunks x three tables
        }

        /** @return array<string, array{string, list<array{string, mixed, string}>}> */
        public static function refused(): array
        {
            return [
                'a table no package carries' => ['perms', [['object_id', 'x', 's']]],
                'the item table'             => ['item', [['slice_id', self::SLICE, 'l']]],
                'no condition'               => ['module', []],
            ];
        }

        #[DataProvider('refused')]
        public function test_select_refuses(string $table, array $where): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new DbSource())->select($table, $where);
        }
    }
}
