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

        public function test_items_of_an_empty_slice_ask_only_for_the_ids_of_the_active_bin(): void
        {
            $this->assertSame([], iterator_to_array((new DbSource())->items(self::SLICE, false)));
            $this->assertSame(['SELECT LOWER(HEX("id")) AS unpackid FROM "item" WHERE slice_id = 0x' . self::SLICE . ' AND status_code = 1'], RecordingDb::$log);
        }

        public function test_items_of_all_bins(): void
        {
            iterator_to_array((new DbSource())->items(self::SLICE, true));
            $this->assertSame(['SELECT LOWER(HEX("id")) AS unpackid FROM "item" WHERE slice_id = 0x' . self::SLICE], RecordingDb::$log);
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
