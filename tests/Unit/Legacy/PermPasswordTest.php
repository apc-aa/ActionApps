<?php

declare(strict_types=1);

/**
 * Characterization test for AA_Perm::comparePwds() (include/perm_core.php) and the
 * opportunistic rehash in AA_Permsystem_Sql::authenticateUsername() (include/perm_sql.php).
 *
 * The SQL perm backend may hold hashes carried over from LDAP by AA\Optimize\LdapPermsToSql
 * ({SSHA}, {SHA}, {SMD5}, {MD5}, {CRYPT}). Those must verify, and the first successful login
 * must replace them with bcrypt. Every hash form AA installs hold today (bcrypt, md5-crypt
 * cut to 30 chars, DES, md5-crypt) must keep verifying exactly as before - and never be
 * rewritten.
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

    use AA_Perm;
    use AA_Permsystem_Sql;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class PermPasswordTest extends TestCase
    {
        private const PWD   = 'Tajne.Heslo1';
        private const WRONG = 'jine-heslo';

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

        /** name => [hash, is LDAP form] */
        public static function hashes(): array
        {
            $pwd = self::PWD;
            return [
                'bcrypt'                 => [password_hash($pwd, PASSWORD_DEFAULT), false],
                'md5-crypt'              => [crypt($pwd, '$1$kdfjslk$'), false],
                'md5-crypt cut to 30'    => [substr(crypt($pwd, '$1$Dabcdefg$'), 0, 30), false],
                'DES'                    => [crypt($pwd, 'Xz'), false],
                '{SSHA}'                 => ['{SSHA}' . base64_encode(sha1($pwd . 'saltsalt', true) . 'saltsalt'), true],
                '{SSHA} without salt'    => ['{SSHA}' . base64_encode(sha1($pwd, true)), true],
                '{SHA}'                  => ['{SHA}' . base64_encode(sha1($pwd, true)), true],
                '{SMD5} 30 chars long'   => ['{SMD5}' . base64_encode(md5($pwd . 'ab', true) . 'ab'), true],   // same length as the legacy 30-char branch
                '{MD5}'                  => ['{MD5}' . base64_encode(md5($pwd, true)), true],
                '{CRYPT} sha512'         => ['{CRYPT}' . crypt($pwd, '$6$rounds=5000$ldapsalt$'), true],
                '{crypt} lowercase'      => ['{crypt}' . crypt($pwd, '$6$rounds=5000$ldapsalt$'), true],
            ];
        }

        #[DataProvider('hashes')]
        public function test_correct_password_verifies_and_wrong_one_does_not(string $hash): void
        {
            $this->assertTrue(AA_Perm::comparePwds(self::PWD, $hash));
            $this->assertFalse(AA_Perm::comparePwds(self::WRONG, $hash));
        }

        public static function rejected(): array
        {
            return [
                'migration placeholder'     => ['{CRYPT}$6$disabled$x'],
                'AddUser placeholder'       => ['crypt will never return this'],
                'unknown LDAP scheme'       => ['{PBKDF2}abc'],
                'broken base64'             => ['{SSHA}***'],
                'SSHA too short'            => ['{SSHA}' . base64_encode('short')],
                'empty'                     => [''],
                'null'                      => [null],
            ];
        }

        #[DataProvider('rejected')]
        public function test_unusable_hash_rejects_every_password(?string $hash): void
        {
            $this->assertFalse(AA_Perm::comparePwds(self::PWD, $hash));
            $this->assertFalse(AA_Perm::comparePwds('', $hash));
            $this->assertFalse(AA_Perm::comparePwds(null, $hash));
        }

        public function test_null_password_is_treated_as_empty_string(): void
        {
            $this->assertFalse(AA_Perm::comparePwds(null, password_hash(self::PWD, PASSWORD_DEFAULT)));
            $this->assertFalse(AA_Perm::comparePwds(null, '{SSHA}' . base64_encode(sha1(self::PWD . 'salt', true) . 'salt')));
            // '' is what the old code compared after PHP's null-to-string coercion - kept, only without the deprecation
            $this->assertTrue(AA_Perm::comparePwds(null, '{SHA}' . base64_encode(sha1('', true))));
        }

        public function test_try_old_still_accepts_reader_slice_sha1_and_md5_salt(): void
        {
            $this->assertTrue(AA_Perm::comparePwds(self::PWD, sha1(self::PWD), true));
            $this->assertFalse(AA_Perm::comparePwds(self::PWD, sha1(self::PWD)));
            $this->assertTrue(AA_Perm::comparePwds(self::PWD, '$1$g(WWPuY$' . md5(self::PWD . 'g(WWPuY'), true));
        }

        // ---------------------------------------------------------------- authenticateUsername()

        private function login(string $stored_hash, string $password): false|string
        {
            RecordingDb::$answer = static fn(string $sql) => str_starts_with($sql, 'SELECT id, password FROM `users`') ? [['id' => 7, 'password' => $stored_hash]] : [];
            return (new AA_Permsystem_Sql())->authenticateUsername('honza', $password);
        }

        private function updates(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'UPDATE')));
        }

        #[DataProvider('hashes')]
        public function test_login_rehashes_ldap_hashes_to_bcrypt_and_leaves_the_others_alone(string $hash, bool $ldap): void
        {
            $this->assertSame('7', $this->login($hash, self::PWD));   // false|string return type

            $updates = $this->updates();
            if (!$ldap) {
                $this->assertSame([], $updates, "a native hash must not be rewritten:\n" . implode("\n", RecordingDb::$log));
                return;
            }
            $this->assertCount(1, $updates);
            $this->assertMatchesRegularExpression("/^UPDATE `users` SET `password`='\\\$2y\\\$\\d\\d\\\$[.\\/A-Za-z0-9]{53}' WHERE id='7'$/", $updates[0]);
        }

        #[DataProvider('hashes')]
        public function test_wrong_password_neither_logs_in_nor_rewrites(string $hash): void
        {
            $this->assertFalse($this->login($hash, self::WRONG));
            $this->assertSame([], $this->updates());
        }

        public function test_migration_placeholder_cannot_log_in_and_is_kept(): void
        {
            $this->assertFalse($this->login('{CRYPT}$6$disabled$x', self::PWD));
            $this->assertSame([], $this->updates());
        }

        public function test_unknown_user_fails(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [];
            $this->assertFalse((new AA_Permsystem_Sql())->authenticateUsername('nikdo', self::PWD));
            $this->assertSame([], $this->updates());
        }
    }
}
