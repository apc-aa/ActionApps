<?php

declare(strict_types=1);

/**
 * AA_Slice::protectedFieldIdsOf(): the protected fields of many slices from one query on `field`, without
 * building their fields - QueryZids() asks it for every condition across all the listed slices.
 *
 * Pins: password, secret, pwd/2fa insert, password copy and unreadable fields are found from the rows alone;
 * a slice with a fieldslice builds its fields; built slices send no query; the order of the slices is kept;
 * QueryZids() refuses a condition on a field protected in an unbuilt slice.
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

    use AA\Util\LogOnce;
    use AA_Field;
    use AA_Module;
    use AA_Slice;
    use Closure;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class SliceProtectedFieldIdsOfTest extends TestCase
    {
        // ids no other test uses - AA_Fields::factoryFor() and AA_Object::load() keep what they read for the process
        private const A = '5f155f155f155f155f155f155f155f15';
        private const B = '5f255f255f255f255f255f255f255f25';
        /** its fields are defined by another slice */
        private const F = '5f355f355f355f355f355f355f355f35';
        /** built in the test - FakeFields */
        private const BUILT = '5f455f455f455f455f455f455f455f45';
        private const FIELDSLICE = '5f555f555f555f555f555f555f555f55';

        /** `field` rows of A and B: slice, id, input_insert_func, content_edit */
        private const ROWS = [
            [self::A, 'headline........', 'qte', '0'],
            [self::A, 'password........', 'pwd:text...........2:phrase', '0'],
            [self::A, 'text...........1', '2FA', '0'],
            [self::A, 'text...........2', 'qte', '0'],
            [self::A, 'text...........3', 'qte', '1'],
            [self::B, 'secret..........', 'qte', '0'],
            [self::B, 'text...........1', 'qte', '0'],
        ];

        /** @var string[] */
        private array $modules = [];
        private array $server = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $this->server = $_SERVER;
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['slice_info'] = $GLOBALS['vid'] = $GLOBALS['view_info'] = null;   // read by the SQL comment
            foreach ([self::A, self::B, self::F] as $id) {
                $this->register(new AA_Slice($id));
            }
            $built = new FakeSlice(self::BUILT);
            $built->setFields(new FakeFields(self::BUILT, [new AA_Field(self::row('headline........')), new AA_Field(self::row('secret..........'))]));
            $this->modules[] = self::BUILT;
            RecordingDb::$answer = self::answer(...);
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->server;
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['slice_info'], $GLOBALS['vid'], $GLOBALS['view_info']);
            RecordingDb::reset();
            LogOnce::reset();
            $ids = $this->modules;
            Closure::bind(static function () use ($ids): void {
                foreach ($ids as $id) {
                    unset(AA_Module::$_modules[$id]);
                }
            }, null, AA_Module::class)();
        }

        /** the rows the queries of the test read */
        private static function answer(string $q): array
        {
            if (str_starts_with($q, 'SELECT object_id, value FROM `object_text`')) {   // fieldslice of the slices
                $f = AA_Slice::getModuleObjectId(self::F);
                return str_contains($q, $f) ? [['object_id' => $f, 'value' => ''], ['object_id' => $f, 'value' => self::FIELDSLICE], ['object_id' => $f, 'value' => '']] : [];
            }
            if (str_contains($q, 'FROM `field`') && str_contains($q, '0x' . self::F)) {   // load() of F
                return [self::row('headline........'), self::row('text...........9', 'pwd:')];
            }
            if (str_starts_with($q, 'SELECT LOWER(HEX(`slice_id`)) AS unpackid')) {   // the rows of the unbuilt slices
                return array_map(static fn(array $r): array => ['unpackid' => $r[0], 'id' => $r[1], 'input_insert_func' => $r[2], 'content_edit' => $r[3]], self::ROWS);
            }
            if (str_contains($q, 'FROM `field`') && str_contains($q, '0x' . self::B) && preg_match("/id = '([^']+)'/", $q, $m)) {   // a field of B, the first slice of QueryZids()
                $rows = array_filter(self::ROWS, static fn(array $r): bool => ($r[0] === self::B) && ($r[1] === $m[1]));
                return array_values(array_map(static fn(array $r): array => self::row($r[1], $r[2]), $rows));
            }
            return [];
        }

        private function register(AA_Slice $slice): void
        {
            $id = $slice->getId();
            Closure::bind(static function () use ($id, $slice): void {
                AA_Module::$_modules[$id] = $slice;
            }, null, AA_Module::class)();
            $this->modules[] = $id;
        }

        /** a full `field` row */
        private static function row(string $id, string $insert = ''): array
        {
            return ['id' => $id, 'input_insert_func' => $insert, 'text_stored' => 1] + array_fill_keys(AA_Field::USED_FIELDS, '');
        }

        /** the statements on a table */
        private static function queries(string $table): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $s): bool => str_contains($s, "FROM `$table`")));
        }

        public function test_unbuiltSlices_oneQueryOnField(): void
        {
            $ids = AA_Slice::protectedFieldIdsOf([self::A, self::B]);

            self::assertSame([
                self::A => ['password........' => true, 'text...........2' => true, 'text...........1' => true, 'text...........3' => true],
                self::B => ['secret..........' => true],
            ], $ids);
            self::assertCount(1, self::queries('field'));
            self::assertCount(1, self::queries('object_text'));
            self::assertStringNotContainsString('input_help', self::queries('field')[0]);   // not the fields built
        }

        public function test_secondCall_noQuery(): void
        {
            AA_Slice::protectedFieldIdsOf([self::A, self::B]);
            RecordingDb::$log = [];

            self::assertSame(['secret..........' => true], AA_Slice::protectedFieldIdsOf([self::B])[self::B]);
            self::assertSame(['secret..........' => true], AA_Slice::getOwnModule(self::B)->protectedFieldIds());
            self::assertSame([], RecordingDb::$log);
        }

        public function test_builtSlice_answersItself_orderKept(): void
        {
            $ids = AA_Slice::protectedFieldIdsOf([self::BUILT, self::A]);

            self::assertSame([self::BUILT, self::A], array_keys($ids));
            self::assertSame(['secret..........' => true], $ids[self::BUILT]);
            self::assertStringNotContainsString(self::BUILT, self::queries('field')[0]);
        }

        public function test_allBuilt_noQuery(): void
        {
            self::assertSame([self::BUILT => ['secret..........' => true]], AA_Slice::protectedFieldIdsOf([self::BUILT]));
            self::assertSame([], RecordingDb::$log);
        }

        /** the rows in `field` are not all its fields - it builds them (any of its fieldslice rows counts), and
         *  is left out of the query for the others */
        public function test_fieldslice_buildsItsFields(): void
        {
            $ids = AA_Slice::protectedFieldIdsOf([self::F, self::A]);

            self::assertSame(['text...........9' => true], $ids[self::F]);
            $field_queries = self::queries('field');
            self::assertStringContainsString('input_help', $field_queries[0]);   // load() of F
            self::assertStringNotContainsString(self::F, end($field_queries));
        }

        /** the first slice describes the field, the rows of the other, unbuilt slice tell it is a 2FA secret there */
        public function test_queryZids_fieldProtectedInUnbuiltSlice_matchesNothing(): void
        {
            QueryZids([self::B, self::A], [['text...........1' => 1, 'operator' => 'BEGIN', 'value' => 'x']], [], 'ACTIVE');

            $selects = array_values(array_filter(RecordingDb::$log, static fn(string $s): bool => str_contains($s, 'as itemid')));
            self::assertCount(1, $selects);
            self::assertStringContainsString(' AND (1=0) ', $selects[0]);
            self::assertFalse(AA_Slice::getOwnModule(self::A)->getFields()->isLoaded());
        }
    }
}
