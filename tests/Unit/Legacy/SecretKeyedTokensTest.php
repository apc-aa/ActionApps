<?php

declare(strict_types=1);

// store_fixtures loads the real locsess.php (the autoloader of include/stringexpand.php), which needs the config constants.

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

    use AA\Util\Crypt;
    use AA\Util\Secret;
    use AA_Password_Manager_Reader;
    use AA_Stringexpand_Encrypt;
    use PHPUnit\Framework\TestCase;

    /** The time token (mail.php configuration, slice passwords) and the reader password reset key
     *  are keyed by AA\Util\Secret - AA_ID is public, a key made of it lets anyone forge them. */
    final class SecretKeyedTokensTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        public function test_time_token_round_trip(): void
        {
            $data = ['to' => 'a@example.org', 'subject' => 'Hi'];
            $this->assertSame($data, AA_Stringexpand_Encrypt::decrypt_time_token(AA_Stringexpand_Encrypt::get_time_token($data, 'mail-form'), 'mail-form'));
        }

        public function test_time_token_of_one_purpose_does_not_open_another(): void
        {
            $slice_pwd = AA_Stringexpand_Encrypt::get_time_token(['spwd' => ['x']], 'slice-pwd');
            $this->assertSame('', AA_Stringexpand_Encrypt::decrypt_time_token($slice_pwd, 'mail-form'));
        }

        public function test_time_token_made_with_aa_id_is_refused(): void
        {
            $forged = Crypt::encryptAesGcm(serialize(['to' => 'victim@example.org']), 'aa-sul' . AA_ID . date('j.n.y H'));
            $this->assertSame('', AA_Stringexpand_Encrypt::decrypt_time_token($forged, 'mail-form'));
        }

        public function test_time_token_keeps_its_hour_windows(): void
        {
            $three_hours_old = Crypt::encryptAesGcm(serialize(['x' => 1]), Secret::key('mail-form') . date('j.n.y H', strtotime('-3 hour')));
            $this->assertSame(['x' => 1], AA_Stringexpand_Encrypt::decrypt_time_token($three_hours_old, 'mail-form', 24));
            $this->assertSame('', AA_Stringexpand_Encrypt::decrypt_time_token($three_hours_old, 'mail-form', 2));
        }

        /** the key as the reset e-mail carries it */
        private static function resetKey(string $user_id, string $email, int $minute): string
        {
            return hash_hmac('sha256', "$user_id\0$email\0$minute", Secret::key('reader-reset'));
        }

        public function test_reset_key_is_valid_for_its_timeout(): void
        {
            $now = 29_000_000;
            $this->assertTrue(AA_Password_Manager_Reader::keyMatches(self::resetKey('u1', 'a@example.org', $now), 'u1', 'a@example.org', $now));
            $this->assertTrue(AA_Password_Manager_Reader::keyMatches(self::resetKey('u1', 'a@example.org', $now - 149), 'u1', 'a@example.org', $now));
            $this->assertFalse(AA_Password_Manager_Reader::keyMatches(self::resetKey('u1', 'a@example.org', $now - 150), 'u1', 'a@example.org', $now), 'expired');
            $this->assertFalse(AA_Password_Manager_Reader::keyMatches(self::resetKey('u1', 'a@example.org', $now + 1), 'u1', 'a@example.org', $now), 'from the future');
        }

        public function test_reset_key_is_bound_to_the_user_and_the_email(): void
        {
            $now = 29_000_000;
            $key = self::resetKey('u1', 'a@example.org', $now);
            $this->assertFalse(AA_Password_Manager_Reader::keyMatches($key, 'u2', 'a@example.org', $now));
            $this->assertFalse(AA_Password_Manager_Reader::keyMatches($key, 'u1', 'b@example.org', $now));
            $this->assertFalse(AA_Password_Manager_Reader::keyMatches(self::resetKey('u1a', '@example.org', $now), 'u1', 'a@example.org', $now), 'the parts do not run into each other');
            $this->assertFalse(AA_Password_Manager_Reader::keyMatches(md5('u1a@example.org' . AA_ID . $now), 'u1', 'a@example.org', $now), 'the old AA_ID key is refused');
        }
    }
}
