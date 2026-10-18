<?php

declare(strict_types=1);

/**
 * GetItemContent() and the reading password of a slice: the values of an item of a slice the request
 * cannot read come back as an error text, its ids and slice as they are - AA_Items::getItems() and
 * callers that filter by slice need them. The DB is a RecordingDb, the slices are registered in
 * AA_Module's cache - no database.
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

    use AA_Credentials;
    use AA_Slice;
    use Closure;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use zids;

    final class GetItemContentReadingPasswordTest extends TestCase
    {
        private const CLOSED    = '6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c';
        private const CLOSED_2  = '6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e';
        private const OPEN      = '6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d';
        private const IN_CLOSED = '0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a';
        private const IN_OPEN   = '0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b';
        private const IN_2      = '0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c';
        private const MASK      = 'Error: Missing Reading Password';

        /** @var array<string, string> item => its slice, what the item table holds */
        private static array $items = [];

        /** @var list<AA_Slice> */
        private static array $slices = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';    // the legacy boot, no DB
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql): array => self::answer($sql);
            self::$slices        = [self::slice(self::CLOSED, AA_Credentials::encrypt('secret')), self::slice(self::CLOSED_2, AA_Credentials::encrypt('other')), self::slice(self::OPEN, null)];
        }

        protected function tearDown(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [];
            foreach (self::$slices as $slice) {
                $slice::forget($slice->getId());
            }
            // the singleton outlives the test
            Closure::bind(function (): void {
                $this->_pwd = [];
            }, AA_Credentials::singleton(), AA_Credentials::class)();
        }

        /** @return iterable<string, array{list<string>}> the fields asked for */
        public static function shapes(): iterable
        {
            yield 'every field' => [[]];
            // slice_id not asked: GetItemContent() reads it anyway
            yield 'a content field' => [['headline........']];
            yield 'ids, slice and an item table field' => [['headline........', 'publish_date....', 'id..............', 'slice_id........']];
        }

        /** @param list<string> $fields */
        #[DataProvider('shapes')]
        public function test_GetItemContent_sliceClosed_valuesMasked_idsKept(array $fields): void
        {
            $item = self::read([self::IN_CLOSED => self::CLOSED], $fields)[self::IN_CLOSED];

            self::assertSame([self::MASK, self::CLOSED, self::IN_CLOSED], [$item['headline........'][0]['value'], $item['u_slice_id......'][0]['value'], $item['unpacked_id.....'][0]['value']]);
            if (isset($item['publish_date....'])) {
                self::assertSame([self::MASK, pack_id(self::IN_CLOSED), pack_id(self::CLOSED)], [$item['publish_date....'][0]['value'], $item['id..............'][0]['value'], $item['slice_id........'][0]['value']]);
            }
        }

        /** @param list<string> $fields */
        #[DataProvider('shapes')]
        public function test_GetItemContent_slicePasswordKnown_valuesPlain(array $fields): void
        {
            AA_Credentials::singleton()->loadFromSlice(self::CLOSED);

            $item = self::read([self::IN_CLOSED => self::CLOSED], $fields)[self::IN_CLOSED];

            self::assertSame('Brno', $item['headline........'][0]['value']);
            self::assertSame('1700000000', $item['publish_date....'][0]['value'] ?? '1700000000');
        }

        /** @return iterable<string, array{array<string, string>, list<string>}> items => their slices, in the order read; the fields asked for */
        public static function mixedReads(): iterable
        {
            foreach ([
                'closed first' => [self::IN_CLOSED => self::CLOSED, self::IN_OPEN => self::OPEN],
                'open first'   => [self::IN_OPEN => self::OPEN, self::IN_CLOSED => self::CLOSED],
                'two closed around an open one' => [self::IN_CLOSED => self::CLOSED, self::IN_OPEN => self::OPEN, self::IN_2 => self::CLOSED_2],
            ] as $order => $items) {
                foreach (self::shapes() as $shape => [$fields]) {
                    yield "$order, $shape" => [$items, $fields];
                }
            }
        }

        /**
         * A closed slice in a read masks its own items only: nothing of it gets through, nothing else is lost
         * @param array<string, string> $items
         * @param list<string>          $fields
         */
        #[DataProvider('mixedReads')]
        public function test_GetItemContent_mixedSlices_onlyTheItemsOfClosedOnesMasked(array $items, array $fields): void
        {
            $content = self::read($items, $fields);

            foreach ($items as $id => $slice) {
                $plain = ($slice === self::OPEN);
                self::assertSame($plain ? 'Brno' : self::MASK, $content[$id]['headline........'][0]['value'], $id);
                self::assertSame($plain ? '1700000000' : self::MASK, $content[$id]['publish_date....'][0]['value'] ?? ($plain ? '1700000000' : self::MASK), $id);
                self::assertSame($slice, $content[$id]['u_slice_id......'][0]['value']);
            }
        }

        /** @param array<string, string> $items item => its slice
         *  @param list<string>          $fields
         *  @return array<string, array<string, list<array{value: mixed}>>> */
        private static function read(array $items, array $fields): array
        {
            self::$items = $items;
            return GetItemContent(new zids(array_keys($items), 'l'), false, false, $fields);
        }

        /** the columns the item SELECT names (all for *), of the stored items; their content rows */
        private static function answer(string $sql): array
        {
            if (preg_match('/^SELECT (.+?) FROM item WHERE/', $sql, $m)) {
                $rows = [];
                foreach (self::$items as $id => $slice) {
                    $row    = ['id' => pack_id($id), 'slice_id' => pack_id($slice), 'publish_date' => '1700000000'] + array_fill_keys(\AA::Metabase()->getColumnNames('item'), '1');
                    $rows[] = ($m[1] === '*') ? $row : array_intersect_key($row, array_flip(explode(',', $m[1])));
                }
                return $rows;
            }
            if (str_starts_with($sql, 'SELECT * FROM content WHERE')) {
                return array_map(static fn(string $id): array => ['item_id' => pack_id($id), 'field_id' => 'headline........', 'number' => 0, 'text' => 'Brno', 'flag' => FLAG_TEXT_STORED], array_keys(self::$items));
            }
            return [];
        }

        /** a slice with the reading password $crypted (AA_Credentials::encrypt()), where AA_Slice::getModule() finds it */
        private static function slice(string $id, ?string $crypted): AA_Slice
        {
            return new class ($id, $crypted) extends AA_Slice {
                public function __construct(string $id, private ?string $crypted)
                {
                    // no parent::__construct() - it would load the slice from the database
                    $this->module_id          = $id;
                    \AA_Module::$_modules[$id] = $this;
                }

                public static function forget(string $id): void
                {
                    unset(\AA_Module::$_modules[$id]);
                }

                public function getId(): string
                {
                    return $this->module_id;
                }

                public function getProperty($prop)
                {
                    return ($prop === 'reading_password') ? $this->crypted : null;
                }
            };
        }
    }
}
