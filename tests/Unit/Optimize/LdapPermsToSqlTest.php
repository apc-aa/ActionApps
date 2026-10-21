<?php

declare(strict_types=1);

// The Optimize reads through include/locsess.php (getDB()/freeDB()), which needs the
// constants include/config.php normally provides.

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

    use AA\Optimize\AbstractOptimize;
    use AA\Optimize\Convert2innodb;
    use AA\Optimize\LdapPermsToSql;
    use AA\Perm\LdapToSql\Plan;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** The conversion with LDAP and the heavy steps replaced by markers in RecordingDb::$log */
    final class ScriptedLdapPermsToSql extends LdapPermsToSql
    {
        /** @var list<array{blocking: bool, text: string}> */
        public array $parity = [];

        /** runs during the import - what a concurrent run could do meanwhile */
        public ?\Closure $onImport = null;

        public function __construct(public Plan $plan)
        {
            $this->db = new RecordingDb();   // the handle of query() and transaction()
        }

        public function messages(): string
        {
            return implode("\n", array_map('strval', array_column($this->reportRows(), 'txt')));
        }

        /** @return list<array{type: string, id: string}> */
        public static function dbObjects(): array
        {
            return self::objects();
        }

        protected function permLib(): string
        {
            return 'ldap';
        }

        protected function prepare(): array
        {
            RecordingDb::$log[] = 'prepare';
            return ['objects' => [], 'plan' => $this->plan, 'answers' => ['objects' => [], 'objectPerms' => [], 'people' => [], 'groups' => []]];
        }

        protected function import(Plan $plan): array
        {
            RecordingDb::$log[] = 'import';
            if ($this->onImport) {
                ($this->onImport)();
            }
            return ['deleted' => 0, 'users' => count($plan->users), 'groups' => 0, 'membership' => 0, 'perms' => 0];
        }

        protected function compare(array $answers, Plan $plan): array
        {
            RecordingDb::$log[] = 'compare';
            return $this->parity;
        }

        protected function rewrite(array $remap): array
        {
            RecordingDb::$log[] = 'rewrite';
            return ['item.posted_by' => 2];
        }

        protected function logConversion(array $imported): int
        {
            RecordingDb::$log[] = 'log';
            return 7;
        }

        protected function logRewritten(int $log_id, array $imported, array $rewritten): void
        {
            RecordingDb::$log[] = 'log rewritten';
        }
    }

    final class LdapPermsToSqlTest extends TestCase
    {
        private const CRYPT = '{CRYPT}$6$salt$hashhashhash';
        private const JAN   = 'uid=jan,ou=People,ou=AA';
        private const STEPS = ['prepare', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'BEGIN', 'import', 'compare', 'log', 'rewrite', 'log rewritten', 'COMMIT', 'ROLLBACK'];
        /** when the conversion ran - the time of its log row */
        private const CONVERTED = 1760000000;

        /** @var array<string, string> table => engine information_schema lists */
        private array $engines = ['users' => 'InnoDB', 'membership' => 'InnoDB', 'perms' => 'InnoDB'];
        private string $charset = 'utf8mb4';
        private bool $converted = false;
        /** @var list<array{v: string}> DN values left in item.posted_by */
        private array $leftovers = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/perm_sql.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            RecordingDb::$answer = fn(string $sql): array => match (true) {
                str_contains($sql, 'ENGINE')                      => array_map(static fn($t, $e) => ['TABLE_NAME' => $t, 'ENGINE' => $e], array_keys($this->engines), $this->engines),
                str_contains($sql, '@@character_set_connection') => [['c' => $this->charset]],
                str_contains($sql, "'LDAP2SQL'")                     => $this->converted ? [['type' => LdapPermsToSql::LOG_TYPE, 'time' => (string) self::CONVERTED, 'params' => '{"users":1}']] : [],
                str_starts_with($sql, 'SELECT DISTINCT')           => $this->leftovers,
                str_contains($sql, 'information_schema.COLUMNS')  => [['t' => 'item', 'c' => 'posted_by']],
                str_contains($sql, 'count(*)')                     => [['cnt' => '2']],
                default                                            => [],
            };
        }

        protected function tearDown(): void
        {
            AbstractOptimize::justPrint(false);
            $GLOBALS['spareDBs'] = [];
        }

        public function test_actions(): void
        {
            self::assertSame(['test', 'repair'], LdapPermsToSql::actions());
            self::assertSame(['private_fix'], LdapPermsToSql::types());
        }

        public function test_test_refusesWithoutLdap(): void
        {
            $optimize = new LdapPermsToSql();

            self::assertFalse($optimize->test());
            self::assertStringContainsString('PERM_LIB', $this->messages($optimize));
            self::assertSame([], $this->readsBesidesTheConversionRow(), 'only whether the conversion already ran is read');
        }

        public function test_repair_refusesWithoutLdap(): void
        {
            $optimize = new LdapPermsToSql();

            self::assertFalse($optimize->repair());
            self::assertStringContainsString('PERM_LIB', $this->messages($optimize));
            self::assertSame([], $this->readsBesidesTheConversionRow());
        }

        /** transaction() runs the block without a transaction in the dry run - the import would write for real */
        public function test_test_refusesTheDryRun(): void
        {
            AbstractOptimize::justPrint(true);
            $optimize = $this->optimize();

            self::assertFalse($optimize->test());
            self::assertStringContainsString('dry run', $optimize->messages());
            self::assertSame([], $this->readsBesidesTheConversionRow());
        }

        public function test_repair_refusesTheDryRun(): void
        {
            AbstractOptimize::justPrint(true);

            self::assertFalse($this->optimize()->repair());
            self::assertSame([], $this->readsBesidesTheConversionRow());
        }

        /** a MyISAM table ignores the rollback, so even Test would delete the users for good */
        public function test_test_refusesTablesThatCannotRollBack(): void
        {
            $this->engines = ['users' => 'MyISAM', 'membership' => 'InnoDB'];
            $optimize      = $this->optimize();

            self::assertFalse($optimize->test());
            self::assertStringContainsString('users (MyISAM), perms (-)', $optimize->messages());
            self::assertStringContainsString(Convert2innodb::name(), $optimize->messages());
            self::assertSame([], $this->steps());
        }

        public function test_repair_refusesTablesThatCannotRollBack(): void
        {
            $this->engines['perms'] = 'MyISAM';

            self::assertFalse($this->optimize()->repair());
            self::assertSame([], $this->steps());
        }

        public function test_test_refusesAConnectionWithoutUtf8mb4(): void
        {
            $this->charset = 'utf8mb3';
            $optimize      = $this->optimize();

            self::assertFalse($optimize->test());
            self::assertStringContainsString('utf8mb4', $optimize->messages());
            self::assertSame([], $this->steps());
        }

        /** a second run would give people other ids than the data already holds */
        public function test_repair_refusesWhenAlreadyConverted(): void
        {
            $this->converted = true;
            $optimize        = $this->optimize();

            self::assertFalse($optimize->repair());
            self::assertStringContainsString('already converted', $optimize->messages());
            self::assertSame([], $this->steps());
        }

        public function test_test_rollsTheTrialImportBack(): void
        {
            $optimize = $this->optimize();

            self::assertTrue($optimize->test());
            self::assertSame(['prepare', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'BEGIN', 'import', 'compare', 'ROLLBACK'], $this->steps());
            self::assertStringContainsString('item.posted_by: 2 rows', $optimize->messages(), 'the references Repair would rewrite');
            self::assertStringContainsString('cp1250', $optimize->messages());
        }

        /** the import would stop at the first person without a password */
        public function test_test_blockingPlanSkipsTheTrialImport(): void
        {
            $optimize = $this->optimize(password: null);

            self::assertFalse($optimize->test());
            self::assertSame(['prepare'], $this->steps());
            self::assertStringContainsString('skipped', $optimize->messages());
            self::assertStringContainsString('has no password', $optimize->messages());
        }

        public function test_test_blockingParityFails(): void
        {
            $optimize         = $this->optimize();
            $optimize->parity = [['blocking' => true, 'text' => 'lost right']];

            self::assertFalse($optimize->test());
            self::assertSame('ROLLBACK', $this->steps()[5]);
            self::assertStringContainsString('lost right', $optimize->messages());
        }

        /** the parity check before the rewrite: the rewrite's UPDATEs lock the big tables until the commit */
        public function test_repair_importParityRewriteAndLogInOneTransaction(): void
        {
            $optimize = $this->optimize();

            self::assertTrue($optimize->repair());
            // the log row before the rewrite, so the operator's DN in log.user is rewritten too
            self::assertSame(['prepare', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'BEGIN', 'import', 'compare', 'log', 'rewrite', 'log rewritten', 'COMMIT'], $this->steps());
            $commit = array_search('COMMIT', RecordingDb::$log, true);
            $cache  = array_keys(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'FROM pagecache')));
            self::assertNotEmpty($cache);
            self::assertGreaterThan($commit, $cache[0], 'the page cache is cleared after the commit');
            self::assertStringContainsString("336\t" . self::JAN, $optimize->messages());
            self::assertStringContainsString("PERM_LIB='sql'", $optimize->messages());
        }

        public function test_repair_blockingParityRollsEverythingBack(): void
        {
            $optimize         = $this->optimize();
            $optimize->parity = [['blocking' => true, 'text' => 'uid=<b>x</b>,ou=AA lost 2']];

            self::assertFalse($optimize->repair());
            self::assertSame(['prepare', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'BEGIN', 'import', 'compare', 'ROLLBACK'], $this->steps());
            self::assertStringContainsString('uid=&lt;b&gt;x&lt;/b&gt;,ou=AA lost 2', $optimize->messages(), 'LDAP data is escaped');
            self::assertStringNotContainsString('<b>x', $optimize->messages());
            self::assertStringContainsString('nothing was changed', $optimize->messages());
        }

        /** READ COMMITTED sees the commit of a run that started at the same time */
        public function test_repair_concurrentConversionRollsBack(): void
        {
            $optimize           = $this->optimize();
            $optimize->onImport = function (): void {
                $this->converted = true;
            };

            self::assertFalse($optimize->repair());
            self::assertSame(['prepare', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'BEGIN', 'import', 'ROLLBACK'], $this->steps());
            self::assertStringContainsString('finished conversion', $optimize->messages(), 'not "nothing was changed" - the database is converted');
            self::assertStringNotContainsString('nothing was changed', $optimize->messages());
        }

        /** a re-import would only test a run that cannot happen; what matters is what live webs wrote since */
        public function test_test_afterConversionReportsLeftoversInsteadOfATrialRun(): void
        {
            $this->converted = true;
            $this->leftovers = [['v' => 'uid=late,ou=People,ou=AA']];
            $optimize        = $this->optimize();

            self::assertTrue($optimize->test());
            self::assertSame([], $this->steps());
            self::assertStringContainsString(date('Y-m-d H:i', self::CONVERTED), $optimize->messages());
            self::assertStringContainsString('committed transaction', $optimize->messages());
            self::assertStringContainsString('item.posted_by (1): uid=late,ou=People,ou=AA', $optimize->messages());
        }

        public function test_test_afterConversionRunsUnderSql(): void
        {
            $this->converted = true;
            $optimize        = new LdapPermsToSql();

            self::assertTrue($optimize->test());
            self::assertStringNotContainsString('PERM_LIB is', $this->messages($optimize));
            self::assertStringContainsString('No DN is left', $this->messages($optimize));
        }

        public function test_repair_refusesABlockingPlan(): void
        {
            self::assertFalse($this->optimize(password: null)->repair());
            self::assertSame(['prepare'], $this->steps());
        }

        public function test_objects_aaModulesAndLinksCategories(): void
        {
            $module              = 'b5e9a2c4d6f8b0a1c3e5f7a9b1d3e5f7';
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, 'FROM module')           => [['id' => $module]],
                str_contains($sql, 'information_schema')    => [['TABLE_SCHEMA' => 'aa_test']],
                str_contains($sql, 'FROM links_categories') => [['id' => '1']],
                default                                     => [],
            };

            self::assertSame([
                ['type' => 'aa', 'id' => AA_ID],
                ['type' => 'slice', 'id' => $module],
                ['type' => 'slice', 'id' => \Links_Category2SliceID(1)],
            ], ScriptedLdapPermsToSql::dbObjects());
        }

        private function optimize(?string $password = self::CRYPT): ScriptedLdapPermsToSql
        {
            $people = [self::JAN => ['uid' => 'jan', 'cn' => 'Jan', 'sn' => null, 'givenname' => null, 'mail' => ['jan@example.org'], 'password' => $password]];
            return new ScriptedLdapPermsToSql(Plan::build($people, [], [], [self::JAN], 336));
        }

        private function messages(LdapPermsToSql $optimize): string
        {
            return implode("\n", array_map('strval', array_column($optimize->reportRows(), 'txt')));
        }

        /** @return list<string> */
        private function readsBesidesTheConversionRow(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => !str_contains($s, "'LDAP2SQL'")));
        }

        /** @return list<string> the steps of the conversion, in order */
        private function steps(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => in_array($s, self::STEPS, true)));
        }
    }
}
