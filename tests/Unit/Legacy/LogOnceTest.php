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

    use AA\Util\LogOnce;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * LogOnce - a log row for something that recurs on every page view (a legacy call AA refuses or
     * still allows): at most one row per event + selector a day, and within one request not even the
     * look-up is repeated, so a busy page neither floods the log nor queries it per item.
     */
    final class LogOnceTest extends TestCase
    {
        /** 2026-09-25 14:00 local time */
        private int $now;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->now = (int) mktime(14, 0, 0, 9, 25, 2026);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
        }

        /** @return string[] the INSERTs into the log */
        private static function logInserts(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'INSERT INTO `log`')));
        }

        public function test_first_occurrence_of_the_day_is_written(): void
        {
            $this->assertTrue(LogOnce::daily('TEST_EVT', 'a:b', 'x:y', $this->now));

            $midnight = (int) mktime(0, 0, 0, 9, 25, 2026);
            $this->assertSame("SELECT type FROM \"log\" WHERE type = 'TEST_EVT' AND time >= $midnight AND selector = 'a:b' LIMIT  1", RecordingDb::$log[0], 'the day is looked up by type and time - the type_time index');
            $inserts = self::logInserts();
            $this->assertCount(1, $inserts);
            $this->assertStringContainsString("'TEST_EVT'", $inserts[0]);
            $this->assertStringContainsString("'a:b'", $inserts[0]);
            $this->assertStringContainsString("'x:y'", $inserts[0]);
        }

        public function test_a_row_of_today_already_there_means_no_new_row(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM "log"') ? [['type' => 'TEST_EVT']] : [];

            $this->assertFalse(LogOnce::daily('TEST_EVT', 'a:b', 'x:y', $this->now));
            $this->assertSame([], self::logInserts());
        }

        public function test_the_same_key_twice_in_one_request_asks_the_database_once(): void
        {
            $this->assertTrue(LogOnce::daily('TEST_EVT', 'a:b', 'x', $this->now));
            $this->assertFalse(LogOnce::daily('TEST_EVT', 'a:b', 'x', $this->now));
            $this->assertFalse(LogOnce::daily('TEST_EVT', 'a:b', 'another params, same key', $this->now));

            $this->assertSame(1, RecordingDb::count('SELECT'));
            $this->assertCount(1, self::logInserts());
        }

        public function test_another_selector_or_event_is_another_key(): void
        {
            $this->assertTrue(LogOnce::daily('TEST_EVT', 'a:b', '', $this->now));
            $this->assertTrue(LogOnce::daily('TEST_EVT', 'a:c', '', $this->now));
            $this->assertTrue(LogOnce::daily('TEST_EV2', 'a:b', '', $this->now));

            $this->assertCount(3, self::logInserts());
        }

        public function test_values_are_cut_to_the_columns_before_the_lookup(): void
        {
            $selector = str_repeat('s', 300);
            $params   = str_repeat('p', 200);

            $this->assertTrue(LogOnce::daily('TEST_EVT', $selector, $params, $this->now));

            $this->assertStringContainsString("selector = '" . str_repeat('s', 255) . "' LIMIT", RecordingDb::$log[0], 'the look-up has to match the stored, cut value');
            $insert = self::logInserts()[0];
            $this->assertStringContainsString("'" . str_repeat('s', 255) . "'", $insert);
            $this->assertStringNotContainsString(str_repeat('s', 256), $insert);
            $this->assertStringContainsString("'" . str_repeat('p', 128) . "'", $insert);
            $this->assertStringNotContainsString(str_repeat('p', 129), $insert);
        }

        public function test_array_params_are_joined_as_the_log_joins_them(): void
        {
            $this->assertTrue(LogOnce::daily('TEST_EVT', 'k', ['a:b', 'c'], $this->now));

            $this->assertStringContainsString("'a#:b:c'", self::logInserts()[0]);
        }

        public function test_a_failing_database_does_not_break_the_page(): void
        {
            RecordingDb::$fail_on = 'SELECT';

            $this->assertFalse(LogOnce::daily('TEST_EVT', 'k', '', $this->now));
        }

        public function test_recent_groups_by_selector(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [['selector' => 'a', 'days' => '3', 'first' => '1', 'last' => '5', 'params' => 'p']];

            $rows = LogOnce::recent('TEST_EVT', 1000, 20);

            $this->assertSame([['selector' => 'a', 'days' => '3', 'first' => '1', 'last' => '5', 'params' => 'p']], $rows);
            $this->assertSame(
                "SELECT `selector`, COUNT(*) AS days, MIN(`time`) AS first, MAX(`time`) AS last, MAX(`params`) AS params FROM `log` WHERE type = 'TEST_EVT' AND time >= 1000 GROUP BY selector ORDER BY last DESC LIMIT 0,20",
                RecordingDb::$log[0],
            );
        }
    }
}
