<?php

declare(strict_types=1);

/**
 * AA_Permsystem_Sql::authenticateUsername() (include/perm_sql.php) finds the login the way the
 * LDAP backend does: case and edge spaces do not count, for the login name as well as for the
 * e-mail. A folded name that fits two accounts logs nobody in, and an empty password never
 * reaches the database.
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
            self::assertStringContainsString("LOWER(TRIM(CONVERT(uid USING utf8mb4)))", self::selects());
            self::assertStringContainsString("= 'kalen01'", self::selects());
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
            $hash = password_hash('x', PASSWORD_DEFAULT);
            self::users(['id' => '12', 'password' => $hash], ['id' => '13', 'password' => $hash]);

            self::assertFalse((new AA_Permsystem_Sql())->authenticateUsername('kalen01', 'x'));
            self::assertSame(0, RecordingDb::count('UPDATE'));
        }

        public function test_authenticate_mailIsFolded(): void
        {
            self::users(['id' => '12', 'password' => password_hash('x', PASSWORD_DEFAULT)]);

            self::assertSame('12', (new AA_Permsystem_Sql())->authenticateUsername('Jan@Example.cz ', 'x'));
            self::assertStringContainsString('LOWER(TRIM(mail))', self::selects());
            self::assertStringContainsString("'jan@example.cz'", self::selects());
        }
    }
}
