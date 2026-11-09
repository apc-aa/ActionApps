<?php

declare(strict_types=1);

/**
 * RepeatedBulkMail against a RecordingDb (tests/Unit/Legacy/fixtures/store_fixtures.php): which
 * earlier send it finds from the EMAIL_SENT log rows and the email table, and what it asks. No database.
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

namespace Tests\Unit\Mail {

    use AA\Mail\RepeatedBulkMail;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class RepeatedBulkMailTest extends TestCase
    {
        private const SLICE   = '07cf8e35746d91136acea183385f7c40';
        private const USER    = 'uid=editor,ou=People,ou=AA';
        private const SUBJECT = 'Newsletter November';
        private const BODY    = '<p>Hello readers</p>';
        private const SINCE   = 1_794_000_000;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        /** @param list<array{time:int, params:string}> $sends  log rows, newest first (as the query orders them)
         *  @param array<int, array{subject:string, body:string}> $mails  email rows of the slice by id; the subject
         *         filters them as the WHERE would */
        private static function answer(array $sends, array $mails): void
        {
            RecordingDb::$answer = static function (string $sql) use ($sends, $mails): array {
                if (str_contains($sql, 'FROM log')) {
                    return $sends;
                }
                $rows = [];
                foreach ($mails as $id => $mail) {
                    if (str_contains($sql, "subject = '" . $mail['subject'] . "'")) {
                        $rows[] = ['id' => $id] + $mail;
                    }
                }
                return $rows;
            };
        }

        private static function lastSend(string $subject = self::SUBJECT, string $body = self::BODY, string $group = '0'): ?array
        {
            return RepeatedBulkMail::lastSend(self::SLICE, self::USER, $group, $subject, $body, self::SINCE);
        }

        public function test_lastSend_finds_the_newest_send_of_the_same_mail(): void
        {
            self::answer(
                [['time' => 1_794_127_283, 'params' => 'Em:1136:592:592'], ['time' => 1_794_127_140, 'params' => 'Em:1133:590:590']],
                [1133 => ['subject' => self::SUBJECT, 'body' => self::BODY], 1136 => ['subject' => self::SUBJECT, 'body' => self::BODY]],
            );

            $this->assertSame(['time' => 1_794_127_283, 'users' => 592], self::lastSend());
        }

        public function test_lastSend_asks_the_sends_of_the_user_to_the_group_and_the_mails_of_the_slice(): void
        {
            self::answer([['time' => 1_794_127_140, 'params' => 'Em:1133:592:592']], []);

            $this->assertNull(self::lastSend(group: '7'));
            [$log, $email] = RecordingDb::$log;
            $this->assertStringContainsString("'EMAIL_SENT'", $log);
            $this->assertStringContainsString('>= ' . self::SINCE, $log);
            $this->assertStringContainsString("selector = '7'", $log, 'only sends to the same group');
            $this->assertStringContainsString("user = '" . self::USER . "'", $log, 'a stored search is a position in the list of its user');
            $this->assertStringContainsString('1133', $email);
            $this->assertStringContainsString('0x' . self::SLICE, $email, 'only mails of this slice');
            $this->assertStringContainsString("subject = '" . self::SUBJECT . "'", $email);
        }

        public function test_lastSend_compares_a_named_group_across_users(): void
        {
            self::answer([], []);

            $this->assertNull(self::lastSend(group: 'AA_ALL'));
            $this->assertStringNotContainsString('user', RecordingDb::$log[0], 'AA_ALL means the same readers for every editor');
        }

        public function test_lastSend_ignores_a_mail_with_other_text(): void
        {
            self::answer(
                [['time' => 1_794_127_283, 'params' => 'Em:1136:592:592'], ['time' => 1_794_127_140, 'params' => 'Em:1133:592:592']],
                [1133 => ['subject' => self::SUBJECT, 'body' => self::BODY], 1136 => ['subject' => self::SUBJECT, 'body' => '<p>Corrected</p>']],
            );

            $this->assertSame(['time' => 1_794_127_140, 'users' => 592], self::lastSend());
            $this->assertNull(self::lastSend('Other subject'));
        }

        public function test_lastSend_ignores_a_send_that_reached_nobody(): void
        {
            self::answer(
                [['time' => 1_794_127_283, 'params' => 'Em:1136:0:0']],
                [1136 => ['subject' => self::SUBJECT, 'body' => self::BODY]],
            );

            $this->assertNull(self::lastSend());
            $this->assertCount(1, RecordingDb::$log, 'no mail to read');
        }

        public function test_lastSend_without_a_send_reads_no_mail(): void
        {
            self::answer([['time' => 1_794_127_140, 'params' => 'Ms:mail:1:ok::']], []);

            $this->assertNull(self::lastSend());
            $this->assertCount(1, RecordingDb::$log, 'a row of another entry type names no mail');
        }
    }
}
