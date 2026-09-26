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

    use AA\Optimize\CheckHtmlFlagRefusals;
    use AA\Optimize\Finding;
    use AA_Log;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * State Check for the HTML flags the aa[...] form input did not take from a visitor because the
     * field has no HTML/plain switch (AA\IO\Grabber\Form), from the deduplicated HTML_DENY log rows.
     */
    final class CheckHtmlFlagRefusalsTest extends TestCase
    {
        private const SLICE = '0123456789abcdef0123456789abcdef';

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
            RecordingDb::reset();
        }

        /** @return array<string, string> a LogOnce::recent() row */
        private static function logRow(string $slice, string $field, string $uid = '', string $mode = 'new', int $days = 1): array
        {
            return ['selector' => ParamImplode([$slice, $field]), 'days' => (string) $days, 'first' => '1758000000', 'last' => '1758700000', 'params' => ParamImplode([$uid, $mode])];
        }

        public function test_the_event_fits_the_log_column(): void
        {
            $this->assertLessThanOrEqual(AA_Log::TYPE_MAX, strlen(CheckHtmlFlagRefusals::EVENT));
        }

        public function test_it_only_tests(): void
        {
            $this->assertSame(['test'], CheckHtmlFlagRefusals::actions());
        }

        public function test_log_rows_become_warnings(): void
        {
            $findings = CheckHtmlFlagRefusals::findingsFor(
                [self::logRow(self::SLICE, 'headline........', days: 3), self::logRow('ffffffffffffffffffffffffffffffff', 'abstract........', 'editor1', 'update')],
                [self::SLICE => 'News'],
            );

            $this->assertSame([Finding::WARNING, Finding::WARNING], array_map(static fn(Finding $f) => $f->severity, $findings));
            [$first, $second] = $findings;
            $this->assertStringContainsString('headline........', $first->title);
            $this->assertStringContainsString('News', $first->title);
            $this->assertStringContainsString(date('Y-m-d', 1758700000), $first->title, 'last seen');
            $this->assertStringContainsString('Show HTML/plain switch', $first->advice);
            $this->assertStringContainsString('rich-text', $first->advice);
            $this->assertStringContainsString('forged', $first->advice);
            $this->assertSame(3, $first->count, 'days seen');
            $this->assertSame(1758000000, $first->since);
            $this->assertSame(1758700000, $first->last);
            $this->assertSame(ParamImplode([self::SLICE, 'headline........']), $first->key);
            $this->assertSame(date('Y-m-d', 1758700000), $first->evidence[0]['Last']);
            $this->assertArrayHasKey('Log rows', $first->links);
            $this->assertStringContainsString('ffffffffffffffffffffffffffffffff', $second->title, 'no name known - the id');
            $this->assertSame('editor1', $second->evidence[0]['User (one of)']);
            $this->assertSame('update', $second->evidence[0]['Item mode']);
        }

        public function test_logged_values_are_escaped_in_evidence(): void
        {
            [$finding] = CheckHtmlFlagRefusals::findingsFor([self::logRow('<b>', '<i>', '<s>', '<u>')], []);

            foreach (['Slice' => '&lt;b&gt;', 'Field' => '&lt;i&gt;', 'User (one of)' => '&lt;s&gt;', 'Item mode' => '&lt;u&gt;'] as $col => $expected) {
                $this->assertSame($expected, $finding->evidence[0][$col], $col);
            }
        }

        public function test_no_rows_is_ok(): void
        {
            $this->assertSame([Finding::OK], array_map(static fn(Finding $f) => $f->severity, CheckHtmlFlagRefusals::findingsFor([], [])));
        }

        public function test_findings_query_the_log_and_the_slice_names(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM `log`')
                ? [self::logRow(self::SLICE, 'headline........')]
                : [['unpackid' => self::SLICE, 'name' => 'News']];

            $findings = (new CheckHtmlFlagRefusals())->findings();

            $this->assertCount(2, RecordingDb::$log);
            $this->assertStringContainsString("FROM `log` WHERE type = '" . CheckHtmlFlagRefusals::EVENT . "' AND time >= ", RecordingDb::$log[0]);
            $this->assertSame('SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module` WHERE id = 0x' . self::SLICE, RecordingDb::$log[1]);
            $this->assertStringContainsString('News', $findings[0]->title);
        }
    }
}
