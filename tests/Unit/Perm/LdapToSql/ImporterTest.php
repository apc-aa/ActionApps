<?php

declare(strict_types=1);

// The SQL backend writes through include/locsess.php (getDB()/freeDB()), which needs the
// constants include/config.php normally provides.

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

namespace AA\Tests\Unit\Perm\LdapToSql {

    use AA\IO\DB\DB_AA;
    use AA\Perm\LdapToSql\Importer;
    use AA\Perm\LdapToSql\Plan;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ImporterTest extends TestCase
    {
        private const READER = '0123456789abcdef0123456789abcdef';
        private const SLICE  = 'b5e9a2c4d6f8b0a1c3e5f7a9b1d3e5f7';
        private const OTHER  = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        /** '+', '/' and '=' must reach the column untouched */
        private const SSHA = '{SSHA}+/AbCdEfGhIjKlMnOpQrStUvWxYz0123456=';
        private const COUNTS = 'SELECT (SELECT count(*) FROM users) AS users, (SELECT count(*) FROM membership) AS membership, (SELECT count(*) FROM perms) AS perms LIMIT  1';
        private const TYPES  = 'SELECT type, count(*) AS cnt FROM users GROUP BY type';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../../include/perm_sql.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->existingRows();
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        /** @var array<string, int> rows the fake tables keep whatever was deleted - a wipe that missed them */
        private array $leftover = [];
        /** @var array<string, int> rows the fake tables lack after the import - a write lost without an error */
        private array $lost = [];

        public function test_run_wipesThenImports(): void
        {
            $this->existingRows(
                users: [['id' => '1', 'type' => 'User'], ['id' => '2', 'type' => 'Group']],
                membership: [['groupid' => '5', 'memberid' => self::READER]],
                perms: [['object_type' => 'slice', 'objectid' => self::OTHER, 'userid' => '9']],
            );

            $counts = $this->import();

            $slice  = self::SLICE;
            $other  = self::OTHER;
            $reader = self::READER;
            self::assertSame([
                'BEGIN',
                'SELECT id, type FROM users ORDER BY id',
                "DELETE FROM `membership`  WHERE memberid = '1'",
                "DELETE FROM `perms`  WHERE userid = '1'",
                "DELETE FROM `users`  WHERE id = '1'",
                "DELETE FROM `membership`  WHERE groupid = '2'",
                "DELETE FROM `membership`  WHERE memberid = '2'",
                "DELETE FROM `perms`  WHERE userid = '2'",
                "DELETE FROM `users`  WHERE id = '2'",
                // rows whose user or group is already gone
                'SELECT groupid, memberid FROM membership',
                "DELETE FROM `membership`  WHERE groupid = '5' AND memberid = '$reader'",
                'SELECT object_type, objectid, userid FROM perms',
                "DELETE FROM `perms`  WHERE object_type = 'slice' AND objectid = '$other' AND userid = '9'",
                self::COUNTS,
                "INSERT INTO `users` (`id`,`type`,`password`,`uid`,`mail`,`name`,`description`,`givenname`,`sn`) VALUES (336,'User','" . self::SSHA . "','kalen01','karel@example.org','Karel Novak','','Karel','Novak')",
                "INSERT INTO `users` (`id`,`type`,`password`,`uid`,`mail`,`name`,`description`,`givenname`,`sn`) VALUES (337,'Group','crypt will never return this','','','Editors','Desk','','')",
                "DELETE FROM `membership`  WHERE groupid = '337' AND memberid = '336'",
                "INSERT INTO `membership` (`groupid`,`memberid`) VALUES (337,'336')",
                "DELETE FROM `perms`  WHERE object_type = 'slice' AND objectid = '$slice' AND userid = '337'",
                "INSERT INTO `perms` (`object_type`,`objectid`,`userid`,`perm`) VALUES ('slice','$slice','337','3')",
                self::COUNTS,
                self::TYPES,
                'COMMIT',
            ], RecordingDb::$log);
            self::assertSame(['deleted' => 4, 'users' => 1, 'groups' => 1, 'membership' => 1, 'perms' => 1], $counts);
        }

        public function test_run_passwordHashIsStoredByteForByte(): void
        {
            $this->import();

            self::assertSame(self::SSHA, RecordingDb::inserts('users')[0]['password']);
        }

        public function test_run_readerIdsPassThrough(): void
        {
            $counts = $this->import($this->plan(members: [self::READER], principals: [self::READER => '2']));

            self::assertSame([['groupid' => 337, 'memberid' => self::READER]], RecordingDb::inserts('membership'));
            self::assertSame(
                [['object_type' => 'slice', 'objectid' => self::SLICE, 'userid' => self::READER, 'perm' => '2']],
                RecordingDb::inserts('perms'),
            );
            self::assertSame(['deleted' => 0, 'users' => 1, 'groups' => 1, 'membership' => 1, 'perms' => 1], $counts);
        }

        public function test_run_refusesOutsideATransaction(): void
        {
            try {
                Importer::run(new \AA_Permsystem_Sql(), $this->plan());
                self::fail('without a transaction a failure would leave half of the import stored');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('transaction', $e->getMessage());
            }
            self::assertSame([], RecordingDb::$log);
        }

        public function test_run_failedInsertStopsTheRun(): void
        {
            RecordingDb::$fail_on = 'INSERT INTO `users`';

            $e = $this->failedRun();

            self::assertStringContainsString('kalen01', $e->getMessage());
            self::assertSame(0, RecordingDb::count('INSERT INTO `membership`'));
            self::assertSame(0, RecordingDb::count('INSERT INTO `perms`'));
        }

        /** a lock wait timeout on a handle that only reports errors - the old account must not survive */
        public function test_run_refusedDeleteStopsTheRun(): void
        {
            $this->existingRows(users: [['id' => '1', 'type' => 'User']]);
            RecordingDb::$refuse_on = 'DELETE FROM `users`';

            $e = $this->failedRun();

            self::assertStringContainsString('users row 1', $e->getMessage());
            self::assertSame(0, RecordingDb::count('INSERT'));
        }

        public function test_run_backendRefusalStopsTheRun(): void
        {
            $sql = new class extends \AA_Permsystem_Sql {
                public function addPerm($id, $objectID, string $objectType, $perm): bool
                {
                    return false;
                }
            };

            $this->failedRun($sql);
        }

        public function test_run_rowsLeftAfterTheWipeStopTheRun(): void
        {
            $this->leftover = ['perms' => 1];

            $e = $this->failedRun();

            self::assertStringContainsString('removing the old rows', $e->getMessage());
            self::assertSame(0, RecordingDb::count('INSERT'));
        }

        public function test_run_finalStateDifferentFromThePlanStopsTheRun(): void
        {
            $this->lost = ['membership' => 1];

            $e = $this->failedRun();

            self::assertStringContainsString('after the import', $e->getMessage());
        }

        public function test_run_usesOneHandle(): void
        {
            $this->existingRows(users: [['id' => '1', 'type' => 'User']]);

            // a second handle asked for inside the transaction would throw
            $this->import();

            self::assertCount(1, array_unique(RecordingDb::$handles));
        }

        public function test_importUser_refusesAnEmptyPassword(): void
        {
            $row = ['id' => 336, 'uid' => 'kalen01', 'mail' => '', 'name' => 'Karel', 'givenname' => '', 'sn' => '', 'password' => ''];

            self::assertFalse((new \AA_Permsystem_Sql())->importUser($row));
            self::assertSame([], RecordingDb::$log);
        }

        /** @return array{deleted: int, users: int, groups: int, membership: int, perms: int} */
        private function import(?Plan $plan = null, ?\AA_Permsystem_Sql $sql = null): array
        {
            return DB_AA::transaction(fn() => Importer::run($sql ?? new \AA_Permsystem_Sql(), $plan ?? $this->plan()));
        }

        /** the run must throw and its transaction roll back */
        private function failedRun(?\AA_Permsystem_Sql $sql = null): \RuntimeException
        {
            try {
                $this->import(null, $sql);
            } catch (\RuntimeException $e) {
                self::assertSame('ROLLBACK', end(RecordingDb::$log));
                return $e;
            }
            self::fail('a failed write must stop the import');
        }

        /**
         * Rows the tables hold before the run; the row counts are what the run inserted.
         *
         * @param list<array<string, string>> $users
         * @param list<array<string, string>> $membership
         * @param list<array<string, string>> $perms
         */
        private function existingRows(array $users = [], array $membership = [], array $perms = []): void
        {
            RecordingDb::$answer = fn(string $sql): array => match (true) {
                $sql === self::COUNTS                 => [$this->counts()],
                $sql === self::TYPES                  => $this->types(),
                str_contains($sql, 'FROM users')      => $users,
                str_contains($sql, 'FROM membership') => $membership,
                str_contains($sql, 'FROM perms')      => $perms,
                default                               => [],
            };
        }

        /** @return array<string, string> */
        private function counts(): array
        {
            $inserted = RecordingDb::count('INSERT') > 0;
            $counts   = [];
            foreach (['users', 'membership', 'perms'] as $table) {
                $rows = count(RecordingDb::inserts($table)) + ($this->leftover[$table] ?? 0) - ($inserted ? ($this->lost[$table] ?? 0) : 0);
                $counts[$table] = (string) $rows;
            }
            return $counts;
        }

        /** @return list<array{type: string, cnt: string}> */
        private function types(): array
        {
            $types = array_count_values(array_column(RecordingDb::inserts('users'), 'type'));
            return array_map(static fn($type, $cnt) => ['type' => (string) $type, 'cnt' => (string) $cnt], array_keys($types), $types);
        }

        /**
         * One person in one group which holds one right, unless members/principals say otherwise.
         *
         * @param list<string> $members
         * @param array<string, string> $principals
         */
        private function plan(array $members = ['uid=kalen01,ou=People,ou=AA'], array $principals = ['cn=Editors,ou=AA' => '3']): Plan
        {
            return Plan::build(
                [
                    'uid=kalen01,ou=People,ou=AA' => [
                        'uid' => 'kalen01', 'cn' => 'Karel Novak', 'sn' => 'Novak', 'givenname' => 'Karel',
                        'mail' => ['karel@example.org'], 'password' => self::SSHA,
                    ],
                ],
                ['cn=Editors,ou=AA' => ['cn' => 'Editors', 'description' => 'Desk', 'members' => $members]],
                [['type' => 'slice', 'id' => self::SLICE, 'perms' => $principals]],
                [],
                336,
            );
        }
    }
}
