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

    use AA;
    use AA_Permsystem_Reader;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * AA_Permsystem_Reader::credentialStamp() over the real ItemContent and AA_Reader::name2Id(): the
     * stamp is the reader's password hash, and only of a reader the login would find - active and in
     * the reader slices the backend is restricted to. RecordingDb plays the tables; its item search
     * applies the slice, bin and username conditions QueryZids puts into the SQL.
     */
    final class ReaderCredentialStampTest extends TestCase
    {
        private const SLICE = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';
        private const OTHER = 'e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2';
        private const ANNA  = 'e3e3e3e3e3e3e3e3e3e3e3e3e3e3e3e3';

        /** @var array<string, array{slice: string, status: int, username: string, password: string}> reader items */
        private array $readers = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->readers = [self::ANNA => ['slice' => self::SLICE, 'status' => 1, 'username' => 'anna', 'password' => '$2y$10$annahash']];
            RecordingDb::$answer = fn(string $sql): array => $this->answer($sql);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private function answer(string $sql): array
        {
            $slices = [self::SLICE, self::OTHER];
            if (preg_match('/^SELECT \* FROM item WHERE\s+item\.id = 0x([0-9a-f]{32})/', $sql, $m)) {
                $reader = $this->readers[$m[1]] ?? null;
                return $reader ? [array_merge(array_fill_keys(AA::Metabase()->getColumnNames('item'), ''), [
                    'id' => pack_id($m[1]), 'slice_id' => pack_id($reader['slice']), 'status_code' => (string) $reader['status'], 'publish_date' => '0', 'expiry_date' => '2000000000',
                ])] : [];
            }
            if (preg_match('/^SELECT \* FROM content WHERE item_id = 0x([0-9a-f]{32})/', $sql, $m)) {
                $reader = $this->readers[$m[1]] ?? null;
                return $reader ? [
                    ['item_id' => pack_id($m[1]), 'field_id' => FIELDID_USERNAME, 'text' => $reader['username'], 'number' => null, 'flag' => '0'],
                    ['item_id' => pack_id($m[1]), 'field_id' => FIELDID_PASSWORD, 'text' => $reader['password'], 'number' => null, 'flag' => '0'],
                ] : [];
            }
            if (str_contains($sql, "slice.type = 'ReaderManagement'")) {
                return array_map(static fn(string $id): array => ['unpackid' => $id], $slices);
            }
            if (str_contains($sql, 'FROM `module`')) {
                return array_map(static fn(string $id): array => ['unpackid' => $id, 'type' => 'S'], $slices);
            }
            if (preg_match('/FROM `slice` WHERE id = 0x([0-9a-f]{32})/', $sql, $m)) {
                return [array_merge(array_fill_keys(AA::Metabase()->getColumnNames('slice'), ''), ['id' => pack_id($m[1]), 'type' => 'ReaderManagement', 'name' => 'Readers'])];
            }
            if (preg_match('/FROM `field` WHERE slice_id = 0x([0-9a-f]{32})/', $sql, $m)) {
                return [array_merge(array_fill_keys(AA::Metabase()->getColumnNames('field'), ''), ['id' => FIELDID_USERNAME, 'slice_id' => pack_id($m[1]), 'text_stored' => '1'])];
            }
            if (str_contains($sql, 'as itemid')) {
                preg_match_all('/0x([0-9a-f]{32})/', (string) strstr($sql, 'item.slice_id'), $in);
                preg_match('/"c0"\."text" = \'([^\']*)\'/', $sql, $name);
                $found = array_filter($this->readers, static fn(array $r): bool => in_array($r['slice'], $in[1], true)
                    && (($r['status'] === 1) || !str_contains($sql, 'item.status_code=1'))
                    && ($r['username'] === ($name[1] ?? null)));
                return array_map(static fn(string $id): array => ['itemid' => pack_id($id)], array_keys($found));
            }
            return [];
        }

        /** the legacy search code warns about globals and keys a web request would have */
        private static function stamp(AA_Permsystem_Reader $backend, string $uid): ?string
        {
            set_error_handler(static fn(int $no): bool => ($no === E_WARNING));
            try {
                return $backend->credentialStamp($uid);
            } finally {
                restore_error_handler();
            }
        }

        public function test_the_stamp_is_the_password_hash_of_an_active_reader(): void
        {
            $this->assertSame('$2y$10$annahash', self::stamp(new AA_Permsystem_Reader(), self::ANNA));
            $this->assertNotEmpty(preg_grep('/as itemid.*"c0"\."text" = \'anna\'/', RecordingDb::$log), 'found again by the username, as the login finds it');
        }

        public function test_a_new_password_is_a_new_stamp(): void
        {
            $before = self::stamp(new AA_Permsystem_Reader(), self::ANNA);
            $this->readers[self::ANNA]['password'] = '$2y$10$annaNEWhash';
            $this->assertNotSame($before, self::stamp(new AA_Permsystem_Reader(), self::ANNA));
        }

        public function test_no_stamp_for_a_reader_outside_the_active_bin(): void
        {
            $this->readers[self::ANNA]['status'] = 2;
            $this->assertNull(self::stamp(new AA_Permsystem_Reader(), self::ANNA));
        }

        public function test_no_stamp_for_a_reader_outside_the_restricted_slices(): void
        {
            $backend = new AA_Permsystem_Reader();
            $backend->restrictSlices([self::OTHER]);
            $this->assertNull(self::stamp($backend, self::ANNA));
        }

        public function test_no_stamp_when_the_username_finds_another_reader(): void
        {
            $this->readers[self::ANNA]['status'] = 2;
            $this->readers['e4e4e4e4e4e4e4e4e4e4e4e4e4e4e4e4'] = ['slice' => self::SLICE, 'status' => 1, 'username' => 'anna', 'password' => '$2y$10$otherhash'];
            $this->assertNull(self::stamp(new AA_Permsystem_Reader(), self::ANNA));
        }

        public function test_no_stamp_without_a_password(): void
        {
            $this->readers[self::ANNA]['password'] = '';
            $this->assertNull(self::stamp(new AA_Permsystem_Reader(), self::ANNA));
        }

        public function test_no_stamp_for_an_unknown_item(): void
        {
            $this->assertNull(self::stamp(new AA_Permsystem_Reader(), 'e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5'));
        }
    }
}
