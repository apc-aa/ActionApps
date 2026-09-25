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

    use AA\Optimize\CheckAjaxUnsigned;
    use AA\Optimize\Finding;
    use AA\Widget\AjaxSignature;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * State Check for the {ajax} widget settings Get_Widget ignored as unsigned (AA\Widget\AjaxSignature),
     * from the deduplicated WIDGET_KEY log rows.
     */
    final class CheckAjaxUnsignedTest extends TestCase
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
        private static function logRow(string $slice, string $field, string $reason = 'unsigned', int $days = 1): array
        {
            return ['selector' => ParamImplode([$slice, $field]), 'days' => (string) $days, 'first' => '1758000000', 'last' => '1758700000', 'params' => $reason];
        }

        public function test_log_rows_become_warnings(): void
        {
            $findings = CheckAjaxUnsigned::findingsFor(
                [self::logRow(self::SLICE, 'relation........', days: 3), self::logRow('ffffffffffffffffffffffffffffffff', 'headline........', 'bad signature')],
                [self::SLICE => 'News'],
            );

            $this->assertSame([Finding::WARNING, Finding::WARNING], array_map(static fn(Finding $f) => $f->severity, $findings));
            [$first, $second] = $findings;
            $this->assertSame('{ajax} edit of field relation........ in slice News came without a valid signature', $first->title);
            $this->assertSame(3, $first->count, 'days seen');
            $this->assertSame(1758000000, $first->since);
            $this->assertSame(1758700000, $first->last);
            $this->assertSame(ParamImplode([self::SLICE, 'relation........']), $first->key);
            $this->assertSame('unsigned', $first->evidence[0]['Reason']);
            $this->assertArrayHasKey('Log rows', $first->links);
            $this->assertStringContainsString('ffffffffffffffffffffffffffffffff', $second->title, 'no name known - the id');
            $this->assertSame('bad signature', $second->evidence[0]['Reason']);
        }

        public function test_logged_values_are_escaped_in_evidence(): void
        {
            [$finding] = CheckAjaxUnsigned::findingsFor([self::logRow('<b>', '<i>', '<s>')], []);

            foreach (['Slice' => '&lt;b&gt;', 'Field' => '&lt;i&gt;', 'Reason' => '&lt;s&gt;'] as $col => $expected) {
                $this->assertSame($expected, $finding->evidence[0][$col], $col);
            }
        }

        public function test_no_rows_is_ok(): void
        {
            $this->assertSame([Finding::OK], array_map(static fn(Finding $f) => $f->severity, CheckAjaxUnsigned::findingsFor([], [])));
        }

        public function test_findings_query_the_log_and_the_slice_names(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM `log`')
                ? [self::logRow(self::SLICE, 'relation........')]
                : [['unpackid' => self::SLICE, 'name' => 'News']];

            $findings = (new CheckAjaxUnsigned())->findings();

            $this->assertCount(2, RecordingDb::$log);
            $this->assertStringContainsString("FROM `log` WHERE type = '" . AjaxSignature::EVENT . "' AND time >= ", RecordingDb::$log[0]);
            $this->assertSame('SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module` WHERE id = 0x' . self::SLICE, RecordingDb::$log[1]);
            $this->assertStringContainsString('in slice News', $findings[0]->title);
        }
    }
}
