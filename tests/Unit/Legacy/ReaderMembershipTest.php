<?php

declare(strict_types=1);

/**
 * AA_Permsystem_Reader::getMembership() (include/perm_core.php) - a reader is a member of his Reader
 * Management slice only while his item is active, as only then he can log in.
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

    use AA_Module;
    use AA_Permsystem_Reader;
    use Closure;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ReaderMembershipTest extends TestCase
    {
        private const READERS = '0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b';
        private const READER  = '1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b';

        /** @var array<string, string> the item table columns of the reader */
        private static array $item = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            new FakeSlice(self::READERS);
            RecordingDb::$answer = static function (string $sql): array {
                if (preg_match('/^SELECT (.+?) FROM item WHERE/', $sql, $m)) {
                    $row = self::$item + ['id' => pack_id(self::READER), 'slice_id' => pack_id(self::READERS)] + array_fill_keys(\AA::Metabase()->getColumnNames('item'), '');
                    return [($m[1] === '*') ? $row : array_intersect_key($row, array_flip(explode(',', $m[1])))];
                }
                if (str_starts_with($sql, 'SELECT * FROM content WHERE')) {
                    return [['item_id' => pack_id(self::READER), 'field_id' => 'headline........', 'number' => 0, 'text' => 'reader', 'flag' => FLAG_TEXT_STORED]];
                }
                return [];
            };
        }

        protected function tearDown(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [];
            $id = self::READERS;
            Closure::bind(static function () use ($id): void {
                unset(AA_Module::$_modules[$id]);
            }, null, AA_Module::class)();
        }

        public function test_getMembership_activeReader_memberOfHisSlice(): void
        {
            self::$item = ['status_code' => '1', 'publish_date' => '1700000000', 'expiry_date' => '4000000000'];
            self::assertSame([self::READERS], (new AA_Permsystem_Reader())->getMembership(self::READER));
        }

        /** @return array<string, array{array<string, string>}> */
        public static function inactive(): array
        {
            return [
                'trash'   => [['status_code' => '3', 'publish_date' => '1700000000', 'expiry_date' => '4000000000']],
                'holding' => [['status_code' => '2', 'publish_date' => '1700000000', 'expiry_date' => '4000000000']],
                'expired' => [['status_code' => '1', 'publish_date' => '1600000000', 'expiry_date' => '1700000000']],
                'pending' => [['status_code' => '1', 'publish_date' => '4000000000', 'expiry_date' => '4100000000']],
            ];
        }

        /** @param array<string, string> $item */
        #[DataProvider('inactive')]
        public function test_getMembership_inactiveReader_noGroup(array $item): void
        {
            self::$item = $item;
            self::assertSame([], (new AA_Permsystem_Reader())->getMembership(self::READER));
        }
    }
}
