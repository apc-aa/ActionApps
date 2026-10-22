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

    use AA\Optimize\CheckAlertitemRefusals;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * State Check for the Alertitem sends AA refused (error 9 of AA_Responder_Alertitem - the collection
     * is not related to the item's slice), from the deduplicated ALERT_DENY log rows.
     */
    final class CheckAlertitemRefusalsTest extends TestCase
    {
        private const SLICE = '0123456789abcdef0123456789abcdef';
        private const ITEM  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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
        private static function logRow(string $collection, string $slice, string $user, string $item = self::ITEM, int $days = 1): array
        {
            return ['selector' => ParamImplode([$collection, $slice, $user]), 'days' => (string) $days, 'first' => '1758000000', 'last' => '1758700000', 'params' => $item];
        }

        public function test_log_rows_become_warnings(): void
        {
            $findings = CheckAlertitemRefusals::findingsFor(
                [self::logRow('sQjwa', self::SLICE, 'editor1', days: 4), self::logRow('abcde', self::SLICE, 'editor2')],
                ['sQjwa' => 'Newsletter'],
                [self::SLICE => 'News'],
                [],
            );

            $this->assertSame([Finding::WARNING, Finding::WARNING], array_map(static fn(Finding $f) => $f->severity, $findings));
            [$first, $second] = $findings;
            $this->assertSame('Alert send of an item of slice News through collection Newsletter was refused', $first->title);
            $this->assertSame(4, $first->count, 'days seen');
            $this->assertSame(1758000000, $first->since);
            $this->assertSame(1758700000, $first->last);
            $this->assertSame(ParamImplode(['sQjwa', self::SLICE, 'editor1']), $first->key);
            $this->assertSame('editor1', $first->evidence[0]['User']);
            $this->assertSame(self::ITEM, $first->evidence[0]['Item']);
            $this->assertArrayHasKey('Log rows', $first->links);
            $this->assertSame('Alert send of an item of slice News through collection abcde was refused', $second->title, 'no name known - the id');
        }

        public function test_logged_values_are_escaped_in_evidence(): void
        {
            [$finding] = CheckAlertitemRefusals::findingsFor([self::logRow('<b>', '<i>', '<u>', '<s>')], [], [], []);

            foreach (['Collection' => '&lt;b&gt;', 'Slice' => '&lt;i&gt;', 'User' => '&lt;u&gt;', 'Item' => '&lt;s&gt;'] as $col => $expected) {
                $this->assertSame($expected, $finding->evidence[0][$col], $col);
            }
        }

        public function test_no_rows_is_ok(): void
        {
            $this->assertSame([Finding::OK], array_map(static fn(Finding $f) => $f->severity, CheckAlertitemRefusals::findingsFor([], [], [], [])));
        }

        public function test_findings_query_the_log_and_the_names(): void
        {
            RecordingDb::$answer = static fn(string $sql) => match (true) {
                str_contains($sql, 'FROM `log`')               => [self::logRow('sQjwa', self::SLICE, 'editor1')],
                str_contains($sql, 'FROM `alerts_collection`') => [['id' => 'sQjwa', 'name' => 'Newsletter', 'module_id' => str_repeat('c', 32)]],
                default                                        => [['unpackid' => self::SLICE, 'name' => 'News']],
            };

            $findings = (new CheckAlertitemRefusals())->findings();

            $this->assertCount(3, RecordingDb::$log);
            $this->assertStringContainsString("FROM `log` WHERE type = '" . CheckAlertitemRefusals::EVENT . "' AND time >= ", RecordingDb::$log[0]);
            $this->assertSame("SELECT c.id, m.name, LOWER(HEX(c.module_id)) AS module_id FROM `alerts_collection` AS c JOIN `module` AS m ON m.id = c.module_id WHERE c.id = 'sQjwa'", RecordingDb::$log[1]);
            $this->assertSame('SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module` WHERE id = 0x' . self::SLICE, RecordingDb::$log[2]);
            $this->assertSame('Alert send of an item of slice News through collection Newsletter was refused', $findings[0]->title);
            $this->assertSame(AA_INSTAL_PATH . 'modules/alerts/acf.php?module_id=' . str_repeat('c', 32), $findings[0]->links[_m('Selections of the collection')]);
        }
    }
}
