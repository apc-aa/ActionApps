<?php

declare(strict_types=1);

// RecordingDb (store_fixtures) loads the real locsess.php, which needs the constants include/config.php normally provides.

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

    use AA_Permsystem_Reader;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * AA_Permsystem_Reader::findGroups() - the group search of se_users.php: Reader slices by name,
     * with all their Reader Sets, and Reader Sets by their own name. RecordingDb plays module and object_text.
     */
    final class ReaderFindGroupsTest extends TestCase
    {
        private const SUBJEKTY = 'd4199fc3c6c924d7c8b83eaf39d8744c';
        private const CLENOVE  = 'd5d5d5d5d5d5d5d5d5d5d5d5d5d5d5d5';
        private const KANCELAR = '227ae73f3412f2c1e946ecd1544062b6';
        private const PLNICI   = '4273bbd5199b80db9a413d78a62bd6f7';
        private const VYBOR    = '5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c';
        private const RADA     = '6a6a6a6a6a6a6a6a6a6a6a6a6a6a6a6a';

        /** reader slices id => name */
        private const SLICES = [self::SUBJEKTY => 'Subjekty', self::CLENOVE => 'Clenove'];
        /** sets id => [owner, name] */
        private const SETS = [
            self::KANCELAR => [self::SUBJEKTY, 'nszmkancelar'],
            self::PLNICI   => [self::SUBJEKTY, 'nszmplnici'],
            self::VYBOR    => [self::CLENOVE, 'Vybor NSZM'],
            self::RADA     => [self::SUBJEKTY, 'clenove rada'],
        ];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            RecordingDb::$answer = fn(string $sql): array => $this->answer($sql);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private function answer(string $sql): array
        {
            if (preg_match("/^SELECT module\\.id,module\\.name FROM slice,module .*module\\.name LIKE '%(.*)%'/s", $sql, $m)) {
                $rows = [];
                foreach (self::SLICES as $id => $name) {
                    if (($m[1] === '') || (stripos($name, $m[1]) !== false)) {
                        $rows[] = ['id' => pack_id($id), 'name' => $name];
                    }
                }
                return $rows;
            }
            if (str_starts_with($sql, 'SELECT LOWER(HEX(module.id)) AS unpackid FROM slice, module')) {
                return array_map(static fn($id) => ['unpackid' => $id], array_keys(self::SLICES));
            }
            if (str_starts_with($sql, 'SELECT o1.object_id, o3.value FROM object_text')) {
                $like = preg_match("/o3\\.value LIKE '%(.*)%'/", $sql, $m) ? $m[1] : '';
                $rows = [];
                foreach (self::SETS as $id => [$owner, $name]) {
                    if (str_contains($sql, "'$owner'") && (($like === '') || (stripos($name, $like) !== false))) {
                        $rows[] = ['object_id' => $id, 'value' => $name];
                    }
                }
                return $rows;
            }
            $this->fail("unexpected SQL: $sql");
        }

        public static function searches(): array
        {
            $slice = 'Reader Slice: ';
            $set   = 'Reader Set: ';
            return [
                'slice name lists the slice and all its sets' => ['subjekty', [self::SUBJEKTY => $slice . 'Subjekty', self::KANCELAR => $set . 'nszmkancelar', self::PLNICI => $set . 'nszmplnici', self::RADA => $set . 'clenove rada']],
                'set name finds the set'                      => ['kancelar', [self::KANCELAR => $set . 'nszmkancelar']],
                'set name, any case, other slice'             => ['vybor', [self::VYBOR => $set . 'Vybor NSZM']],
                'set names of two slices'                     => ['nszm', [self::KANCELAR => $set . 'nszmkancelar', self::PLNICI => $set . 'nszmplnici', self::VYBOR => $set . 'Vybor NSZM']],
                'nothing'                                     => ['xyz', []],
                'empty lists every slice and set'             => ['', [self::SUBJEKTY => $slice . 'Subjekty', self::CLENOVE => $slice . 'Clenove', self::KANCELAR => $set . 'nszmkancelar', self::PLNICI => $set . 'nszmplnici', self::VYBOR => $set . 'Vybor NSZM', self::RADA => $set . 'clenove rada']],
            ];
        }

        /** a set found by its own name is listed before the sets of a slice that matched too */
        public function test_findGroups_ownNameFirst(): void
        {
            $names = array_column((new AA_Permsystem_Reader())->findGroups('clen'), 'name');
            $this->assertSame(['Reader Slice: Clenove', 'Reader Set: clenove rada', 'Reader Set: Vybor NSZM'], $names);
        }

        #[DataProvider('searches')]
        public function test_findGroups_search(string $pattern, array $expected): void
        {
            $groups = (new AA_Permsystem_Reader())->findGroups($pattern);
            ksort($groups);
            ksort($expected);
            $this->assertSame($expected, array_map(static fn($g) => $g['name'], $groups));
        }
    }
}
