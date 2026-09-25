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

    if (!function_exists('usr_test_check_fu')) {
        /** an installation's usr_* function - defined in this (AA base) file, which does not matter for usr_* */
        function usr_test_check_fu(array $content, string $col, string $param): string
        {
            return $param;
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA\Optimize\CheckUserAliasFunctionLog;
    use AA\Optimize\CheckUserAliasFunctions;
    use AA\Optimize\Finding;
    use AA\Util\UserAliasFunction;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * State Check for f_u (AA_Item::f_u()): the field definitions sorted by what AA does with the
     * function they name (CheckUserAliasFunctions, extended), and the calls pages made that were not
     * plain usr_* calls, from the deduplicated log (CheckUserAliasFunctionLog, basic).
     */
    final class CheckUserAliasFunctionsTest extends TestCase
    {
        private const SLICE  = '0123456789abcdef0123456789abcdef';
        private const SLICE2 = 'fedcba9876543210fedcba9876543210';

        private static string $dir = '';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            // an installation function without the usr_ prefix, in a file outside the AA base (like NSZM's nszm_prava_conds)
            self::$dir = sys_get_temp_dir() . '/aa_fu_check_' . getmypid() . '_' . bin2hex(random_bytes(4));
            mkdir(self::$dir, 0700);
            file_put_contents(self::$dir . '/legacy.php', "<?php\nfunction aa_test_check_legacy_fu(array \$c, string \$col, string \$param): string\n{\n    return \$param;\n}\n");
            require_once self::$dir . '/legacy.php';
            UserAliasFunction::addInstallationFunctions(['aa_test_check_legacy_fu']);   // as if include/usr_aliasfnc.php included it
            file_put_contents(self::$dir . '/hostapp.php', "<?php\nfunction aa_test_check_hostapp_fu(array \$c, string \$col, string \$param): string\n{\n    return \$param;\n}\n");
            require_once self::$dir . '/hostapp.php';
        }

        public static function tearDownAfterClass(): void
        {
            @unlink(self::$dir . '/legacy.php');
            @unlink(self::$dir . '/hostapp.php');
            @rmdir(self::$dir);
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

        /** @return array<string, ?string> a FIELDS_SQL row */
        private static function row(string $slice, string $field, ?string $a1, ?string $a2 = null, ?string $a3 = null, string $name = 'Web'): array
        {
            return ['slice_id' => $slice, 'id' => $field, 'alias1_func' => $a1, 'alias2_func' => $a2, 'alias3_func' => $a3, 'name' => $name];
        }

        /** @param Finding[] $findings
         *  @return array<string, array{string, int}> key => [severity, count] */
        private static function byKey(array $findings): array
        {
            $out = [];
            foreach ($findings as $finding) {
                $out[$finding->key] = [$finding->severity, $finding->count];
            }
            return $out;
        }

        public function test_field_definitions_are_classified(): void
        {
            $findings = CheckUserAliasFunctions::findingsFor([
                self::row(self::SLICE, 'text...........1', 'f_u:usr_test_check_fu:x', 'f_u:aa_test_check_legacy_fu:{x}', 'f_h:-'),
                self::row(self::SLICE, 'text...........2', 'F_U:huhl', 'f_u:', 'f_u:g_s:x'),
                self::row(self::SLICE2, 'text...........1', 'f_u:aa_test_check_legacy_fu', 'f_u:phpinfo', 'f_u:_#ALIAS___', 'Other'),
                self::row(self::SLICE2, 'text...........2', 'f_u:aa_test_check_hostapp_fu', null, null, 'Other'),
            ]);

            $this->assertSame([
                'def:' . UserAliasFunction::REJECTED . ':core:huhl'                   => [Finding::ERROR, 1],
                'def:' . UserAliasFunction::REJECTED . ':internal:phpinfo'            => [Finding::ERROR, 1],
                'def:' . UserAliasFunction::REJECTED . ':name:_#ALIAS___'             => [Finding::ERROR, 1],
                'def:' . UserAliasFunction::REJECTED . ':foreign:aa_test_check_hostapp_fu' => [Finding::ERROR, 1],
                'def:' . UserAliasFunction::INSTALLATION . '::aa_test_check_legacy_fu' => [Finding::WARNING, 2],
                'def:' . UserAliasFunction::REJECTED . ':undefined:g_s'               => [Finding::WARNING, 1],
                'def:' . UserAliasFunction::REJECTED . ':empty:'                      => [Finding::INFO, 1],
            ], self::byKey($findings), 'usr_* and other alias functions give no finding; one finding per function, the fields counted; '
                . 'undefined only warns - the admin page does not load what only a site loads');
        }

        public function test_undefined_in_a_definition_points_to_the_runtime_log(): void
        {
            [$finding] = CheckUserAliasFunctions::findingsFor([self::row(self::SLICE, 'text...........1', 'f_u:nszm_prava_conds:x')]);

            $this->assertSame(Finding::WARNING, $finding->severity);
            $this->assertStringContainsString('f_u calls on pages', $finding->advice);
        }

        public function test_evidence_names_the_field_and_slice_escaped(): void
        {
            [$finding] = CheckUserAliasFunctions::findingsFor([self::row(self::SLICE, 'text...........1', 'f_u:phpinfo:<b>', name: 'A <i>slice</i>')]);

            $this->assertSame('f_u names phpinfo, a PHP function - not called', $finding->title);
            $row = $finding->evidence[0];
            $this->assertSame('A &lt;i&gt;slice&lt;/i&gt;', $row['Slice']);
            $this->assertSame('f_u:phpinfo:&lt;b&gt;', $row['Definition']);
            $this->assertStringContainsString('se_inputform.php', $row['Field']);
            $this->assertStringContainsString('fid=text...........1', $row['Field']);
        }

        public function test_installation_function_warning_says_where_to_rename(): void
        {
            [$finding] = CheckUserAliasFunctions::findingsFor([self::row(self::SLICE, 'text...........1', 'f_u:aa_test_check_legacy_fu:x')]);

            $this->assertSame(Finding::WARNING, $finding->severity);
            $this->assertStringContainsString('usr_aa_test_check_legacy_fu', $finding->advice);
            $this->assertStringContainsString(self::$dir . '/legacy.php', $finding->advice, 'outside the AA base the full path');
        }

        public function test_all_fine_is_one_ok_finding(): void
        {
            $this->assertSame([[Finding::OK, 'All 1 f_u field definitions call usr_* functions']], array_map(static fn(Finding $f) => [$f->severity, $f->title], CheckUserAliasFunctions::findingsFor([self::row(self::SLICE, 'text...........1', 'f_u:usr_test_check_fu')])));
            $this->assertSame([[Finding::OK, 'No field definition uses f_u']], array_map(static fn(Finding $f) => [$f->severity, $f->title], CheckUserAliasFunctions::findingsFor([])));
        }

        public function test_findings_read_the_field_table_with_constant_sql(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM `field`') ? [self::row(self::SLICE, 'text...........1', 'f_u:huhl')] : [];

            $findings = (new CheckUserAliasFunctions())->findings();

            $this->assertSame([CheckUserAliasFunctions::FIELDS_SQL], RecordingDb::$log);
            $this->assertSame(Finding::ERROR, $findings[0]->severity);
        }

        // ---- runtime log ------------------------------------------------------------------

        /** @return array<string, string> a LogOnce::recent() row */
        private static function logRow(string $level, string $slice, string $field, string $name, string $reason = '', string $file = '', int $days = 1): array
        {
            return ['selector' => ParamImplode([$level, $slice, $field, $name]), 'days' => (string) $days, 'first' => '1758000000', 'last' => '1758700000', 'params' => ParamImplode([$reason, $file])];
        }

        public function test_log_rows_become_findings(): void
        {
            $findings = CheckUserAliasFunctionLog::findingsFor([
                self::logRow(UserAliasFunction::INSTALLATION, self::SLICE, 'text...........1', 'nszm_prava_conds', '', 'include/usr_aliasfnc.php', 12),
                self::logRow(UserAliasFunction::REJECTED, self::SLICE, 'text...........2', 'huhl', 'core', 'include/util.php', 3),
                self::logRow(UserAliasFunction::REJECTED, self::SLICE2, 'headline........', '', 'empty'),
            ], [self::SLICE => 'NSZM']);

            $this->assertSame([Finding::ERROR, Finding::WARNING, Finding::INFO], array_map(static fn(Finding $f) => $f->severity, $findings));
            $this->assertStringNotContainsString('f_u calls on pages', $findings[0]->advice);
            [$core, $legacy] = $findings;
            $this->assertSame('f_u calls nszm_prava_conds, an installation function without the usr_ prefix - field text...........1 of slice NSZM', $legacy->title);
            $this->assertSame(12, $legacy->count, 'days seen');
            $this->assertSame(1758000000, $legacy->since);
            $this->assertSame(1758700000, $legacy->last);
            $this->assertSame(ParamImplode([UserAliasFunction::INSTALLATION, self::SLICE, 'text...........1', 'nszm_prava_conds']), $legacy->key, 'the problem keeps its identity across runs');
            $this->assertSame('include/usr_aliasfnc.php', $legacy->evidence[0]['File']);
            $this->assertArrayHasKey('Field settings', $legacy->links);
            $this->assertArrayHasKey('Log rows', $legacy->links);
            $this->assertSame('f_u names huhl, a function of AA itself - not called - field text...........2 of slice NSZM', $core->title);
            $this->assertSame(self::SLICE2, $findings[2]->evidence[0]['Slice'], 'no name known - the id');
        }

        /** on a page "undefined" is a fact - the function was not there when the page ran */
        public function test_runtime_reasons_are_errors(): void
        {
            $findings = CheckUserAliasFunctionLog::findingsFor([
                self::logRow(UserAliasFunction::REJECTED, self::SLICE, 'text...........1', 'nszm_x', 'undefined'),
                self::logRow(UserAliasFunction::REJECTED, self::SLICE, 'text...........2', 'usr_x', 'call', ''),
                self::logRow(UserAliasFunction::REJECTED, self::SLICE, 'text...........3', 'host_x', 'foreign', '/srv/app/x.php'),
            ], []);

            $this->assertSame([Finding::ERROR, Finding::ERROR, Finding::ERROR], array_map(static fn(Finding $f) => $f->severity, $findings));
            $titles = implode("\n", array_map(static fn(Finding $f) => $f->title, $findings));
            $this->assertStringContainsString('f_u call of usr_x failed', $titles);
            $this->assertStringContainsString('f_u names host_x, defined outside AA in /srv/app/x.php', $titles);
        }

        public function test_logged_values_are_escaped_in_evidence(): void
        {
            [$finding] = CheckUserAliasFunctionLog::findingsFor([self::logRow(UserAliasFunction::REJECTED, 'x<b>', 'f<i>', '<script>', 'name', '<f>')], []);

            $this->assertSame('x&lt;b&gt;', $finding->evidence[0]['Slice']);
            $this->assertSame('f&lt;i&gt;', $finding->evidence[0]['Field']);
            $this->assertSame('&lt;f&gt;', $finding->evidence[0]['File']);
            $this->assertArrayNotHasKey('Field settings', $finding->links, 'no link from a slice id that is not one');
        }

        public function test_no_log_rows_is_ok(): void
        {
            $this->assertSame([Finding::OK], array_map(static fn(Finding $f) => $f->severity, CheckUserAliasFunctionLog::findingsFor([], [])));
        }

        public function test_log_findings_query_the_log_and_the_slice_names(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM `log`')
                ? [self::logRow(UserAliasFunction::REJECTED, self::SLICE, 'text...........1', 'phpinfo', 'internal')]
                : [['unpackid' => self::SLICE, 'name' => 'Web']];

            $findings = (new CheckUserAliasFunctionLog())->findings();

            $this->assertCount(2, RecordingDb::$log);
            $this->assertStringContainsString("FROM `log` WHERE type = '" . UserAliasFunction::LOG_EVENT . "' AND time >= ", RecordingDb::$log[0]);
            $this->assertSame('SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module` WHERE id = 0x' . self::SLICE, RecordingDb::$log[1]);
            $this->assertStringEndsWith('of slice Web', $findings[0]->title);
        }
    }
}
