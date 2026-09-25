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

    use AA\Optimize\CheckSessionidUse;
    use AA\Optimize\Finding;
    use AA\Util\LogOnce;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * Get_Sessionid of central/responder.php: every use is logged once a day
     * per remote address and kind of login, and State Check lists who still calls it.
     */
    final class CheckSessionidUseTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
        }

        public function test_a_use_is_logged_once_a_day_by_address_and_kind(): void
        {
            $server = ['REMOTE_ADDR' => '192.0.2.7'];
            $this->assertTrue(CheckSessionidUse::record($server, ['username' => 'central', 'password' => 'pw'], '7', 'password'));
            $this->assertFalse(CheckSessionidUse::record($server, ['username' => 'central', 'password' => 'pw'], '7', 'password'));
            $this->assertTrue(CheckSessionidUse::record($server, ['free' => 'reader1', 'freepwd' => 'secret-pw'], '0123456789abcdef0123456789abcdef', ''));
            $this->assertTrue(CheckSessionidUse::record($server, [], '0123456789abcdef0123456789abcdef', ''));

            $rows = RecordingDb::inserts('log');
            $this->assertSame(array_fill(0, 3, CheckSessionidUse::EVENT), array_column($rows, 'type'));
            $this->assertSame([ParamImplode(['192.0.2.7', 'password']), ParamImplode(['192.0.2.7', 'reader']), ParamImplode(['192.0.2.7', 'session'])], array_column($rows, 'selector'));
            $this->assertLessThanOrEqual(10, strlen(CheckSessionidUse::EVENT));
            foreach (['central', 'pw', 'reader1', 'secret-pw'] as $secret) {
                $this->assertStringNotContainsString("'$secret'", implode("\n", RecordingDb::$log), 'no login name or password in the log');
            }
        }

        public function test_an_anonymous_call_is_not_logged(): void
        {
            $this->assertFalse(CheckSessionidUse::record(['REMOTE_ADDR' => '192.0.2.7'], ['free' => 'reader1', 'freepwd' => 'wrong'], 'nobody', ''));
            $this->assertFalse(CheckSessionidUse::record(['REMOTE_ADDR' => '192.0.2.7'], [], '', ''));
            $this->assertSame([], RecordingDb::$log);
        }

        public function test_log_rows_become_warnings(): void
        {
            $findings = CheckSessionidUse::findingsFor([
                ['selector' => ParamImplode(['192.0.2.7', 'reader']), 'days' => '4', 'first' => '1758000000', 'last' => '1758700000', 'params' => ''],
                ['selector' => ParamImplode(['<b>', 'password']), 'days' => '1', 'first' => '1758000000', 'last' => '1758000000', 'params' => ''],
                ['selector' => ParamImplode(['192.0.2.8', 'session']), 'days' => '1', 'first' => '1758000000', 'last' => '1758000000', 'params' => ''],
            ]);

            $this->assertSame([Finding::INFO, Finding::INFO, Finding::INFO], array_map(static fn(Finding $f) => $f->severity, $findings));
            [$reader, $user, $session] = $findings;
            $this->assertStringContainsString('192.0.2.7', $reader->title);
            $this->assertSame(4, $reader->count);
            $this->assertSame('reader', $reader->evidence[0]['Login']);
            $this->assertStringContainsString('AA_ClientAuth', $reader->advice);
            $this->assertSame('&lt;b&gt;', $user->evidence[0]['Address']);
            $this->assertStringContainsString('Central', $user->advice, 'a password login is an old Central or a script');
            $this->assertStringNotContainsString('Central', $session->advice, 'a session resume is an external site, not a Central');
            $this->assertStringContainsString('AA_Sess', $session->advice);
        }

        public function test_no_rows_is_ok_and_says_it_can_go(): void
        {
            [$finding] = CheckSessionidUse::findingsFor([]);
            $this->assertSame(Finding::OK, $finding->severity);
            $this->assertStringContainsString('30 days', $finding->title);
        }
    }
}
