<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\DatovkaConf;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsConf;
use AA\Optimize\Finding;
use AA\Util\Txt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Optimize\Fixtures\ScriptedIsdsConfCheck;

require_once __DIR__ . '/../Isds/fixtures/Sending.php';

/**
 * CheckIsdsConf: the file of the data-box settings itself - each finding with its file in a temporary
 * directory of AA and its settings passed in, the log rows given. Names of keys and files only, never a
 * value of the file.
 */
final class CheckIsdsConfTest extends TestCase
{
    /** a value no finding may show */
    private const SECRET = 'Secret-never-shown';

    private const DAY = 86400;

    /** the directory of AA of the test, ending with a slash */
    private string $base = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/fixtures/ScriptedIsdsConfCheck.php';
    }

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/aa-isds-conf-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->base . 'misc/datovka', 0o700, true);
        mkdir($this->base . 'include', 0o700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->base);
    }

    public function test_types_basicOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_BASIC], CheckIsdsConf::types());
    }

    public function test_findings_noFileIsOk(): void
    {
        $findings = $this->check()->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('There is no misc/datovka/datovka_conf.php - AA runs without the data-box settings of this installation', $findings[0]->title);
    }

    public function test_findings_fileReadIsOk(): void
    {
        $this->write(DatovkaConf::FILE, "<?php\nconst DATOVKA_CONF = ['accounts' => ['NSZM' => ['password' => '" . self::SECRET . "']]];\n", 0o640);
        $this->write('include/config.php', '<?php', 0o640);

        $findings = $this->check(['accounts' => ['NSZM' => ['password' => self::SECRET]]])->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('AA reads all of misc/datovka/datovka_conf.php', $findings[0]->title);
    }

    /** a link to a file that is gone: PHP of this request cannot read it - no OK of the file, nor of its accounts */
    public function test_findings_unreadableIsAnError(): void
    {
        $check       = $this->check();
        $check->conf = Sending::fileConf(false);

        $findings = $check->findings();

        self::assertSame(['unreadable'], self::keys($findings));
        self::assertSame([Finding::ERROR, 'PHP cannot read misc/datovka/datovka_conf.php'], [$findings[0]->severity, $findings[0]->title]);
        self::assertStringStartsWith('misc/datovka/datovka_conf.php links to a file PHP cannot reach', $findings[0]->evidence[0]['Problem']);
        self::assertSame('Give the file the owner, group and mode of include/config.php, as each site may run PHP as a user of its own, and check that open_basedir of each site lets PHP reach the file (and the target of a link). Until then AA runs as if there were no file.', $findings[0]->advice);
        $this->assertNoSecret($findings);
    }

    /** the line of the error only, never the code around it */
    public function test_findings_brokenShowsTheLineOnly(): void
    {
        $file        = $this->write(DatovkaConf::FILE, "<?php\nconst DATOVKA_CONF = [\n    'accounts' => ['NSZM' => ['password' => '" . self::SECRET . "'] 'x'],\n];\n");
        $check       = $this->check();
        $check->conf = DatovkaConf::fromFile($file);

        $findings = $check->findings();

        self::assertSame(['broken'], self::keys($findings));
        self::assertSame([Finding::ERROR, 'AA cannot use misc/datovka/datovka_conf.php'], [$findings[0]->severity, $findings[0]->title]);
        self::assertSame(['Problem' => 'misc/datovka/datovka_conf.php is not valid PHP (line 3) - AA runs as if the file were not there'], $findings[0]->evidence[0]);
        self::assertSame('Correct the file following datovka_conf.php.default - the problem tells what is wrong. Until then AA runs as if there were no file.', $findings[0]->advice);
        $this->assertNoSecret($findings);
    }

    // a list of accounts that is no array keeps AA from the accounts only; the rest it reads, with its own problems
    public function test_findings_accountsNotAnArrayAndOtherProblems(): void
    {
        $findings = $this->check(['accounts' => self::SECRET, 'instances' => [0 => ['url' => self::SECRET]]])->findings();

        self::assertSame(['broken-accounts', 'problems'], self::keys($findings));
        self::assertSame([Finding::ERROR, 'AA cannot use the accounts of misc/datovka/datovka_conf.php'], [$findings[0]->severity, $findings[0]->title]);
        self::assertSame(['Problem' => Txt::safe("DATOVKA_CONF['accounts'] is not an array - AA has no ISDS account")], $findings[0]->evidence[0]);
        self::assertSame("Correct DATOVKA_CONF['accounts'] following datovka_conf.php.default. Until then AA has no ISDS account; AA reads the rest of the file.", $findings[0]->advice);
        self::assertSame([Finding::WARNING, 'AA leaves out settings of misc/datovka/datovka_conf.php: 1'], [$findings[1]->severity, $findings[1]->title]);
        self::assertStringStartsWith(Txt::safe("DATOVKA_CONF['instances'][0] is left out"), $findings[1]->evidence[0]['Problem']);
        $this->assertNoSecret($findings);
    }

    /** what AA leaves out, keys it does not read and blocks out of their place, addresses of an older file: by their keys */
    public function test_findings_keysNamedNeverValues(): void
    {
        $findings = $this->check([
            'instances' => [1000 => ['url' => 'http://' . self::SECRET . '/'], 1001 => ['url' => 'https://example.org/aa/', 'NSZM' => ['password' => self::SECRET]]],
            'test'      => ['url_login' => 'https://' . self::SECRET . '/', 'ECN' => ['password' => self::SECRET], 'atsId' => self::SECRET],
            'NSZM'      => ['environment' => 'production', 'password' => self::SECRET],
        ])->findings();

        self::assertSame(['problems', 'strays', 'addresses'], self::keys($findings));
        self::assertSame([Finding::WARNING, Finding::WARNING, Finding::INFO], self::severities($findings));
        self::assertSame(
            array_map(static fn(string $setting): array => ['Setting' => Txt::safe($setting)], ["DATOVKA_CONF['test']['ECN']", "DATOVKA_CONF['instances'][1001]['NSZM']", "DATOVKA_CONF['NSZM']"]),
            $findings[1]->evidence,
        );
        self::assertSame('misc/datovka/datovka_conf.php has keys AA does not read: 3', $findings[1]->title);
        self::assertSame("Move an ISDS account under DATOVKA_CONF['accounts'], or delete what AA does not read: at the top AA reads instances, test, standard and accounts, in test and standard cert and atsId, in an installation only url.", $findings[1]->advice);
        self::assertSame([['Setting' => Txt::safe("DATOVKA_CONF['test']['url_login']")]], $findings[2]->evidence);
        self::assertSame('AA does not read url_* and wsdl_* from the file - the addresses are part of AA. Delete them.', $findings[2]->advice);
        $this->assertNoSecret($findings);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function twice(): array
    {
        return [
            'an account and the accounts' => ["'accounts' => ['NSZM' => [], 'nszm' => []],\n'accounts' => ['NSZM' => [], 'NSZM' => [], 'nszm' => [], 'nszm' => []],", ["DATOVKA_CONF['accounts']", "DATOVKA_CONF['accounts']['NSZM']"]],
            'commented out'               => ["'accounts' => [\n    // 'NSZM' => ['password' => '" . self::SECRET . "'],\n    'NSZM' => [],\n],", []],
            'a name AA does not read'     => ["'accounts' => ['nszm' => [], 'nszm' => []],", []],
        ];
    }

    /**
     * Only names Accounts reads: nothing else of the text of the file shows
     * @param list<string> $settings
     */
    #[DataProvider('twice')]
    public function test_findings_keysWrittenTwice(string $body, array $settings): void
    {
        $this->write(DatovkaConf::FILE, "<?php\nconst DATOVKA_CONF = [\n$body\n];\n");

        $findings = $this->check(['accounts' => ['NSZM' => [], 'nszm' => []]])->findings();

        $this->assertNoSecret($findings);
        if (!$settings) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        self::assertSame(['twice'], self::keys($findings));
        self::assertSame([Finding::ERROR, 'misc/datovka/datovka_conf.php writes keys twice: ' . count($settings)], [$findings[0]->severity, $findings[0]->title]);
        self::assertSame(array_map(static fn(string $setting): array => ['Setting' => Txt::safe($setting)], $settings), $findings[0]->evidence);
        self::assertSame('PHP keeps only the last of a key written twice and leaves out the first without a word. Join the two into one, or rename one of the accounts.', $findings[0]->advice);
    }

    /**
     * Accounts of the sites of other databases written twice: counted in one row, never named here. The
     * database is asked only about the sites of the accounts written twice.
     */
    public function test_findings_accountOfAnotherDatabaseWrittenTwice(): void
    {
        $this->write(DatovkaConf::FILE, "<?php\nconst DATOVKA_CONF = ['accounts' => ['NSZM' => [], 'NSZM' => [], 'ECN' => [], 'ECN' => [], 'ECO' => [], 'ECO' => [], 'FREE' => [], 'FREE' => [], 'ONCE' => []]];\n");
        $check       = $this->check(['accounts' => ['NSZM' => ['sites' => [Sending::SITE]], 'ECN' => ['sites' => [Sending::SITE2]], 'ECO' => ['sites' => [Sending::SITE2]], 'FREE' => [], 'ONCE' => ['sites' => [Sending::OTHER]]]]);
        $check->here = [Sending::SITE];

        $findings = $check->findings();

        self::assertSame(['twice'], self::keys($findings));
        self::assertSame(['misc/datovka/datovka_conf.php writes keys twice: 4', 4], [$findings[0]->title, $findings[0]->count]);
        self::assertSame([
            ['Setting' => Txt::safe("DATOVKA_CONF['accounts']['FREE']")],
            ['Setting' => Txt::safe("DATOVKA_CONF['accounts']['NSZM']")],
            ['Setting' => 'ISDS accounts of the sites of other databases: 2'],
        ], $findings[0]->evidence, 'an account of no site read is a fault of the file, named in each database');
        self::assertStringEndsWith('or rename one of the accounts. An account of the sites of another database: the State Check of the database of its sites names it.', $findings[0]->advice);
        self::assertStringNotContainsString('EC', serialize($findings));
        self::assertSame([[Sending::SITE2, Sending::SITE]], $check->asked, 'in the order of the names');
    }

    // the tokens of a long file would take a basic check too long - not checked, and it says so
    public function test_findings_longFileNotCheckedForKeysWrittenTwice(): void
    {
        $this->write(DatovkaConf::FILE, str_pad("<?php\nconst DATOVKA_CONF = ['accounts' => ['NSZM' => [], 'NSZM' => []]];\n", DatovkaConf::MAX_BYTES + 1, ' '));

        $findings = $this->check(['accounts' => ['NSZM' => []]])->findings();

        self::assertSame(['twice-unchecked'], self::keys($findings));
        self::assertSame([Finding::INFO, 'misc/datovka/datovka_conf.php is not checked for keys written twice - the file is over 128 KB'], [$findings[0]->severity, $findings[0]->title]);
    }

    /** @return array<string, array{int, ?int, array<mixed>, bool}> */
    public static function modes(): array
    {
        $password = ['accounts' => ['NSZM' => ['password' => self::SECRET]]];

        return [
            'a password'                => [0o644, 0o640, $password, true],
            'a passphrase'              => [0o604, 0o600, ['accounts' => ['NSZM' => ['passphrase' => self::SECRET]]], true],
            'a password of digits'      => [0o644, 0o640, ['accounts' => ['NSZM' => ['password' => 12345678]]], true],
            'a block out of its place'  => [0o644, 0o640, ['test' => ['NSZM' => ['nested' => ['password' => self::SECRET]]]], true],
            'as include/config.php'     => [0o644, 0o644, $password, false],
            'the group only'            => [0o640, 0o600, $password, false],
            'no password'               => [0o644, 0o640, ['accounts' => ['NSZM' => ['login' => 'nszm01']]], false],
            'an empty password'         => [0o644, 0o640, ['accounts' => ['NSZM' => ['password' => '']]], false],
            'no include/config.php'     => [0o644, null, $password, false],
        ];
    }

    /**
     * A password others may read while they may not read include/config.php. The group is not judged:
     * the PHP of every site of the tree reads the file.
     * @param array<mixed> $settings
     */
    #[DataProvider('modes')]
    public function test_findings_permissionsAsIncludeConfig(int $mode, ?int $configMode, array $settings, bool $error): void
    {
        $this->write(DatovkaConf::FILE, '<?php', $mode);
        if (!is_null($configMode)) {
            $this->write('include/config.php', '<?php', $configMode);
        }

        $findings = $this->check($settings)->findings();

        if (!$error) {
            self::assertNotContains('access', self::keys($findings));
            return;
        }
        $finding = array_values(array_filter($findings, static fn(Finding $f): bool => $f->key === 'access'))[0];
        self::assertSame([Finding::ERROR, 'misc/datovka/datovka_conf.php is readable to all users of the server, include/config.php is not'], [$finding->severity, $finding->title]);
        self::assertSame([['File' => 'misc/datovka/datovka_conf.php', 'Permissions' => sprintf('%04o', $mode)], ['File' => 'include/config.php', 'Permissions' => sprintf('%04o', $configMode)]], $finding->evidence);
        self::assertStringContainsString('owner, group and mode of include/config.php', $finding->advice);
        $this->assertNoSecret($findings);
    }

    /** any name with datovka_conf in it, next to the file and in the directory of AA - the names only */
    public function test_findings_copies(): void
    {
        foreach (['datovka_conf.php', 'datovka_conf.php.default', 'datovka_conf.php.bak', '.datovka_conf.php.swp', 'datovka_conf.php~', 'Datovka_Conf.php.orig', 'service.php'] as $name) {
            $this->write("misc/datovka/$name", self::SECRET);
        }
        $this->write('datovka_conf.keep', self::SECRET);
        $this->write('index.php', self::SECRET);

        $findings = $this->check()->findings();

        self::assertSame(['copies'], self::keys($findings));
        self::assertSame([Finding::ERROR, 'Copies of misc/datovka/datovka_conf.php in the directory of AA: 5'], [$findings[0]->severity, $findings[0]->title]);
        self::assertSame(['datovka_conf.keep', 'misc/datovka/.datovka_conf.php.swp', 'misc/datovka/Datovka_Conf.php.orig', 'misc/datovka/datovka_conf.php.bak', 'misc/datovka/datovka_conf.php~'], array_column($findings[0]->evidence, 'File'));
        self::assertStringContainsString('only outside the web tree', $findings[0]->advice);
        $this->assertNoSecret($findings);
    }

    /**
     * What other requests logged, and on which site: one that could not use the file is an error while it
     * is fresh, another problem a warning; one this request meets too - even cut by the log - shows once,
     * above.
     */
    public function test_findings_problemsOtherRequestsMet(): void
    {
        $check  = $this->check(['test' => ['cert' => 7]]);
        $now    = $check->conf->problems()[0];
        $site   = 'https://example.org/aa/?a=1&b=<2>';
        $row    = static fn(string $problem, string $failure, int $ago = self::DAY - 60): array => [
            'selector' => $problem, 'days' => '2', 'first' => (string) ($check->now - 3 * self::DAY), 'last' => (string) ($check->now - $ago), 'params' => ParamImplode([$failure, $site]),
        ];
        $unread = 'PHP cannot read misc/datovka/datovka_conf.php - give it the owner, group and mode of include/config.php, and check that open_basedir lets PHP there';
        $broken = 'misc/datovka/datovka_conf.php is not valid PHP (line 3) - AA runs as if the file were not there';
        $accounts = "DATOVKA_CONF['accounts'] is not an array - AA has no ISDS account";
        $check->rows[DatovkaConf::LOG_EVENT] = [$row($unread, 'unread'), $row($broken, 'broken'), $row($accounts, 'accounts'), $row('<b>instances</b> left out', ''), $row($now, ''), $row(substr($now, 0, 30), '')];

        $findings = $check->findings();

        self::assertSame(['problems', 'logged:' . md5($unread), 'logged:' . md5($broken), 'logged:' . md5($accounts), 'logged:' . md5('<b>instances</b> left out')], self::keys($findings));
        [, $failed, $brokenThere, $accountsThere, $other] = $findings;
        self::assertSame([Finding::ERROR, 'Other requests could not use misc/datovka/datovka_conf.php', 2], [$failed->severity, $failed->title, $failed->count]);
        self::assertSame(['Problem' => $unread, 'Site, one of them' => 'https://example.org/aa/?a=1&amp;b=&lt;2&gt;', 'Days seen' => '2', 'Last' => date('Y-m-d', $check->now - self::DAY + 60)], $failed->evidence[0]);
        self::assertSame([$check->now - 3 * self::DAY, $check->now - self::DAY + 60], [$failed->since, $failed->last]);
        self::assertStringStartsWith('Give the file the owner, group and mode of include/config.php', $failed->advice);
        self::assertStringEndsWith('Seen on one day only, it is usually a network file system refusing PHP for a while. The finding disappears 7 days after Last.', $failed->advice);
        self::assertStringContainsString('srchbr_value%5B1%5D=ISDS_FILE', $failed->links['Log rows']);
        self::assertSame([Finding::ERROR, 'Other requests could not use misc/datovka/datovka_conf.php'], [$brokenThere->severity, $brokenThere->title]);
        self::assertStringStartsWith('The file has changed since, or another tree of AA over this database reads a file of its own', $brokenThere->advice);
        self::assertSame([Finding::ERROR, 'Other requests could not use the accounts of misc/datovka/datovka_conf.php'], [$accountsThere->severity, $accountsThere->title]);
        self::assertSame([Finding::WARNING, 'Other requests met a problem in misc/datovka/datovka_conf.php', '&lt;b&gt;instances&lt;/b&gt; left out'], [$other->severity, $other->title, $other->evidence[0]['Problem']]);
        self::assertSame($brokenThere->advice, $other->advice);
    }

    /**
     * A failure last logged before yesterday was likely a passing one, or is fixed. LogOnce keeps the first
     * failing request of a day, so one logged yesterday at midnight may still go on.
     */
    public function test_findings_failureOfOtherRequestsAgesToAWarning(): void
    {
        $check     = $this->check();
        $yesterday = (int) strtotime('yesterday', $check->now);
        $row       = static fn(string $problem, int $last): array => ['selector' => $problem, 'days' => '1', 'first' => (string) $last, 'last' => (string) $last, 'params' => 'unread'];
        $check->rows[DatovkaConf::LOG_EVENT] = [$row('PHP cannot read misc/datovka/datovka_conf.php', $yesterday), $row('PHP cannot read it', $yesterday - 1)];

        $findings = $check->findings();

        self::assertSame([[Finding::ERROR, 'Other requests could not use misc/datovka/datovka_conf.php'], [Finding::WARNING, 'Other requests could not use misc/datovka/datovka_conf.php']], array_map(static fn(Finding $f): array => [$f->severity, $f->title], $findings));
        self::assertSame(['Problem', 'Days seen', 'Last'], array_keys($findings[0]->evidence[0]), 'no site logged');
    }

    /**
     * The token of a message of this database names the installation missing in instances by its id; any
     * other token is foreign or made up, logged under one selector - its id only one of them
     */
    public function test_findings_refusedAnswers(): void
    {
        $check = $this->check(['instances' => [1000 => ['url' => 'https://' . self::SECRET . '/']], 'accounts' => ['NSZM' => ['password' => self::SECRET]]]);
        $check->rows[DatovkaConf::INSTANCE_EVENT] = [
            ['selector' => 'known:1001', 'days' => '2', 'first' => '1790700000', 'last' => '1790800000', 'params' => '1001'],
            ['selector' => 'unknown', 'days' => '3', 'first' => '1790600000', 'last' => '1790800000', 'params' => '<b>999</b>'],
        ];

        $findings = $check->findings();

        self::assertSame(['instance:known:1001', 'instance:unknown'], self::keys($findings));
        [$known, $unknown] = $findings;
        self::assertSame([Finding::ERROR, "Answers of ISDS to messages of this database were refused: DATOVKA_CONF['instances'] does not list installation 1001", 2], [$known->severity, $known->title, $known->count]);
        self::assertSame(['Installation id' => '1001', 'Days seen' => '2', 'Last' => date('Y-m-d', 1790800000)], $known->evidence[0]);
        self::assertStringStartsWith("Add the installation of that id to DATOVKA_CONF['instances'], with its AA_INSTAL_URL", $known->advice);
        self::assertSame([Finding::INFO, "Answers of ISDS refused: the installation in their token is not in DATOVKA_CONF['instances']", 3], [$unknown->severity, $unknown->title, $unknown->count]);
        self::assertSame(['Installation id, one of them' => '&lt;b&gt;999&lt;/b&gt;', 'Days seen' => '3', 'Last' => date('Y-m-d', 1790800000)], $unknown->evidence[0]);
        self::assertStringStartsWith('No message of this database has the token.', $unknown->advice);
        self::assertStringContainsString('srchbr_value%5B1%5D=ISDS_INST', $unknown->links['Log rows']);
        $this->assertNoSecret($findings);
    }

    public function test_notChecked_onlyOfAFileAaCannotUse(): void
    {
        foreach ([Sending::fileConf(false), Sending::fileConf(true), DatovkaConf::fromArray(['accounts' => self::SECRET])] as $conf) {
            $finding = CheckIsdsConf::notChecked($conf);
            self::assertSame([[Finding::INFO, 'file', 'ISDS accounts not checked: AA cannot use the accounts of misc/datovka/datovka_conf.php']], array_map(static fn(Finding $f): array => [$f->severity, $f->key, $f->title], $finding));
        }
        self::assertSame([], CheckIsdsConf::notChecked(DatovkaConf::fromArray(['instances' => self::SECRET])));
    }

    // ------------------------------------------------------------ helpers

    /** @param array<mixed> $settings as DATOVKA_CONF */
    private function check(array $settings = []): ScriptedIsdsConfCheck
    {
        $check       = new ScriptedIsdsConfCheck($this->base);
        $check->conf = DatovkaConf::fromArray($settings);

        return $check;
    }

    /** @return string the file, in the directory of AA of the test */
    private function write(string $path, string $text, int $mode = 0o600): string
    {
        $file = $this->base . $path;
        file_put_contents($file, $text);
        chmod($file, $mode);
        clearstatcache(true, $file);

        return $file;
    }

    /**
     * No value of the file, nor a path beyond the directory of AA
     * @param Finding[] $findings
     */
    private function assertNoSecret(array $findings): void
    {
        self::assertStringNotContainsString(self::SECRET, serialize($findings));
        self::assertStringNotContainsString(rtrim($this->base, '/'), serialize($findings));
    }

    /**
     * @param Finding[] $findings
     * @return list<string>
     */
    private static function severities(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->severity, array_values($findings));
    }

    /**
     * @param Finding[] $findings
     * @return list<string>
     */
    private static function keys(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->key, array_values($findings));
    }
}
