<?php

declare(strict_types=1);

/**
 * CheckMailbulkStats: what changed on the statistics slice of a list since it was chosen - a slice gone or
 * without its fields counts nothing, history and no reading password are to be fixed, and without AA_SECRET
 * a new database password breaks the tracked links of the mails sent.
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

namespace Tests\Unit\Optimize {

    use AA\Optimize\CheckMailbulkStats;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\TestCase;

    /** the check over the lists and slices the test says */
    final class TestMailbulkStatsCheck extends CheckMailbulkStats
    {
        /** @var list<array{name: string, module: string, slice: string}> */
        public static array $lists = [];
        /** @var array<string, array{live: bool, missing: list<string>, history: bool, readable: bool}> slice => state */
        public static array $slices = [];
        public static string $secret = 'a-long-random-string-of-at-least-32-bytes';

        protected function lists(): array
        {
            return self::$lists;
        }

        protected function inspect(string $sliceId): array
        {
            return self::$slices[$sliceId];
        }

        protected function secret(): string
        {
            return self::$secret;
        }
    }

    final class CheckMailbulkStatsTest extends TestCase
    {
        private const FINE = ['live' => true, 'missing' => [], 'history' => false, 'readable' => false];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/go_url.php';
        }

        protected function setUp(): void
        {
            TestMailbulkStatsCheck::$lists  = [];
            TestMailbulkStatsCheck::$slices = [];
            TestMailbulkStatsCheck::$secret = 'a-long-random-string-of-at-least-32-bytes';
        }

        private static function list(string $name, string $slice, array $state): void
        {
            TestMailbulkStatsCheck::$lists[]        = ['name' => $name, 'module' => str_repeat('7a', 16), 'slice' => $slice];
            TestMailbulkStatsCheck::$slices[$slice] = $state;
        }

        /** @return array<string, Finding> key => finding */
        private static function findings(): array
        {
            $findings = (new TestMailbulkStatsCheck())->findings();
            return array_combine(array_map(static fn(Finding $f): string => $f->key, $findings), $findings);
        }

        public function test_noListMeasures_isOk_evenWithoutSecret(): void
        {
            TestMailbulkStatsCheck::$secret = '';

            self::assertSame([''], array_keys(self::findings()));
            self::assertSame(Finding::OK, self::findings()['']->severity);
        }

        public function test_sliceFine_isOk(): void
        {
            self::list('News', str_repeat('5a', 16), self::FINE);

            self::assertSame(Finding::OK, self::findings()['']->severity);
        }

        public function test_eachProblem_isItsFinding(): void
        {
            self::list('Gone', str_repeat('1a', 16), ['live' => false] + self::FINE);
            self::list('Fields', str_repeat('2a', 16), ['missing' => ['relation.......1']] + self::FINE);
            self::list('History', str_repeat('3a', 16), ['history' => true, 'readable' => true] + self::FINE);
            TestMailbulkStatsCheck::$secret = 'short';

            $findings = self::findings();

            self::assertSame(['stat-gone', 'stat-fields', 'stat-history', 'stat-readable', 'stat-secret'], array_keys($findings));
            self::assertSame([Finding::ERROR, Finding::ERROR, Finding::WARNING, Finding::WARNING, Finding::WARNING], array_values(array_map(static fn(Finding $f): string => $f->severity, $findings)));
            self::assertSame('relation.......1', $findings['stat-fields']->evidence[0]['Missing fields']);
            self::assertStringContainsString('History', $findings['stat-history']->evidence[0]['Alerts']);
            self::assertStringContainsString('Store item history for N days', $findings['stat-history']->advice);
            self::assertStringContainsString('AA_SECRET', $findings['stat-secret']->advice);
        }

        /** a list name is the admin's text */
        public function test_evidence_isEscaped(): void
        {
            self::list('<b>x</b>', str_repeat('1a', 16), ['live' => false] + self::FINE);

            self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', self::findings()['stat-gone']->evidence[0]['Alerts']);
        }
    }
}
