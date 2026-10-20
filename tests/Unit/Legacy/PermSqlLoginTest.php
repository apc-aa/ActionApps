<?php

declare(strict_types=1);

/**
 * AA_Permsystem_Sql::authenticateUsername() (include/perm_sql.php) finds the login the way the
 * LDAP backend does: case and edge spaces do not count, for the login name as well as for the
 * e-mail, while accents do. Accounts may share an e-mail: the password picks one, and when it
 * fits two, nobody logs in. An empty password never reaches the database.
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

    use AA_Permsystem_Sql;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class PermSqlLoginTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        /** every SELECT on `users` gets these rows */
        private static function users(array ...$rows): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_starts_with($sql, 'SELECT id, password FROM `users`') ? $rows : [];
        }

        private static function selects(): string
        {
            return implode("\n", array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'SELECT')));
        }

        public function test_authenticate_foldsCaseAndSpaces(): void
        {
            self::users(['id' => '12', 'password' => password_hash('x', PASSWORD_DEFAULT)]);

            self::assertSame('12', (new AA_Permsystem_Sql())->authenticateUsername(' Kalen01 ', 'x'));
            self::assertStringContainsString("LOWER(TRIM(CONVERT(uid USING utf8mb4))) COLLATE utf8mb4_bin = 'kalen01'", self::selects(), 'accents count - only case and spaces are folded');
            self::assertStringContainsString("AND type IN ('User'", self::selects(), 'a group never logs in');
            self::assertStringEndsWith(' LIMIT 0,10', self::selects());
        }

        public function test_authenticate_atAtTheStartSearchesTheLoginName(): void
        {
            self::users();

            self::assertFalse((new AA_Permsystem_Sql())->authenticateUsername('@kalen01', 'x'));
            self::assertStringContainsString("CONVERT(uid USING utf8mb4)", self::selects());
            self::assertStringNotContainsString('mail', self::selects());
        }

        public function test_authenticate_emptyPasswordNeverAsks(): void
        {
            self::users(['id' => '12', 'password' => password_hash('', PASSWORD_DEFAULT)]);

            self::assertFalse((new AA_Permsystem_Sql())->authenticateUsername('kalen01', ''));
            self::assertFalse((new AA_Permsystem_Sql())->authenticateUsername('kalen01', null));
            self::assertFalse((new AA_Permsystem_Sql())->authenticateUsername('  ', 'x'), 'nothing left of the name');
            self::assertSame([], RecordingDb::$log);
        }

        public function test_authenticate_twoMatchesRefuse(): void
        {
            self::users(['id' => '12', 'password' => password_hash('x', PASSWORD_DEFAULT)], ['id' => '13', 'password' => '{SHA}' . base64_encode(sha1('x', true))]);

            self::assertFalse((new AA_Permsystem_Sql())->authenticateUsername('kalen01', 'x'));
            self::assertSame(0, RecordingDb::count('UPDATE'));
        }

        public function test_authenticate_sharedMailThePasswordPicksTheAccount(): void
        {
            self::users(['id' => '12', 'password' => password_hash('other', PASSWORD_DEFAULT)], ['id' => '13', 'password' => '{SHA}' . base64_encode(sha1('x', true))]);

            self::assertSame('13', (new AA_Permsystem_Sql())->authenticateUsername('jan@example.cz', 'x'));
            $updates = array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'UPDATE')));
            self::assertCount(1, $updates);
            self::assertStringEndsWith("WHERE id='13'", $updates[0], 'only the account that logged in is rehashed');
        }

        public function test_authenticate_mailIsFolded(): void
        {
            self::users(['id' => '12', 'password' => password_hash('x', PASSWORD_DEFAULT)]);

            self::assertSame('12', (new AA_Permsystem_Sql())->authenticateUsername('Jan@Example.cz ', 'x'));
            self::assertStringContainsString("LOWER(TRIM(CONVERT(mail USING utf8mb4))) COLLATE utf8mb4_bin = 'jan@example.cz'", self::selects());
        }

        public function test_isLoginFree_foldsAsTheLogin(): void
        {
            self::assertTrue((new AA_Permsystem_Sql())->isLoginFree(' Kalen01 '));
            self::assertStringContainsString("LOWER(TRIM(CONVERT(uid USING utf8mb4))) COLLATE utf8mb4_bin = 'kalen01'", self::selects());
            self::assertStringContainsString("LOWER(TRIM(CONVERT(mail USING utf8mb4))) COLLATE utf8mb4_bin = 'kalen01'", self::selects());
        }
    }
}
