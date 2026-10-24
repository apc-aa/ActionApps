<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\SliceRules;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsAccounts;
use AA\Optimize\Finding;
use AA\Util\Txt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\Certificates;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Optimize\Fixtures\ScriptedIsdsAccountsCheck;

require_once __DIR__ . '/../Isds/fixtures/Sending.php';

/**
 * CheckIsdsAccounts: the ISDS accounts of datovka_conf.php with what to fix in their settings - named,
 * never their values - and whether their site and slices pass SliceRules. The accounts are passed in,
 * the slices come from FakeSliceRules.
 */
final class CheckIsdsAccountsTest extends TestCase
{
    private const SITE     = '51de0000000000000000000000000001';
    private const SITE2    = '5e7e0000000000000000000000000002';
    private const CAMPAIGN = 'cafe0000000000000000000000000001';
    private const MESSAGE  = 'beef0000000000000000000000000002';

    private const LOGIN      = 'nszm01login';
    private const PASSWORD   = 'Test-Pa55-never-shown';
    private const PASSPHRASE = 'Pass-phrase-never-shown';

    private const DAY = 86400;

    /**
     * The files of this run, made in setUpBeforeClass(): $root/aa is the directory of AA of the tests ($inside),
     * $root/aa-keys a directory beside it ($outside), $root/aa-link and $root/alias links to $root/aa
     */
    private static string $root = '';
    private static string $inside = '';
    private static string $outside = '';

    /** validity of the certificate, unix seconds */
    private static int $from = 0;
    private static int $until = 0;

    /** a line of the private key, which no finding may show */
    private static string $keyLine = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../fixtures/Certificates.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/../Isds/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/../Isds/fixtures/FakeSliceRules.php';
        require_once __DIR__ . '/fixtures/ScriptedIsdsAccountsCheck.php';
        self::$root    = Certificates::directory();
        self::$inside  = self::$root . '/aa';
        self::$outside = self::$root . '/aa-keys';
        mkdir(self::$inside, 0o700);
        mkdir(self::$outside, 0o700);
        [$certificate, $key] = Certificates::make();
        foreach ([self::$inside, self::$outside] as $dir) {
            Certificates::file($dir, 'both.pem', $certificate . $key);
            Certificates::file($dir, 'group.pem', $certificate . $key, 0o640);
            Certificates::file($dir, 'other.pem', $certificate . $key, 0o604);
        }
        Certificates::file(self::$outside, 'cert.pem', $certificate, 0o644);
        Certificates::file(self::$outside, 'key.pem', $key);
        Certificates::file(self::$outside, 'encrypted.key', Certificates::encrypted($key, self::PASSPHRASE));
        Certificates::file(self::$outside, 'another.key', Certificates::make('Another', 43)[1]);
        Certificates::file(self::$outside, 'garbage.pem', 'not a certificate');
        Certificates::file(self::$outside, 'locked.pem', $certificate . $key, 0o000);
        Certificates::file(self::$outside, 'weak.pem', implode('', Certificates::make('Weak', 44, 400, 1024)));
        Certificates::file(self::$outside, 'huge.pem', str_pad($certificate . $key, 1024 * 1024 + 1, "\n"));
        // a key others may read, beyond the part of the file that is read
        Certificates::file(self::$outside, 'late.pem', str_repeat("\n", 1024 * 1024) . $certificate . $key, 0o604);
        // a web server follows links, wherever they point
        symlink(self::$outside . '/group.pem', self::$inside . '/link.pem');
        symlink(self::$inside . '/group.pem', self::$outside . '/in.pem');
        symlink(self::$outside, self::$inside . '/keys');
        symlink(self::$inside, self::$root . '/aa-link');
        symlink(self::$inside, self::$root . '/alias');
        $details       = (array) openssl_x509_parse($certificate);
        self::$from    = (int) $details['validFrom_time_t'];
        self::$until   = (int) $details['validTo_time_t'];
        self::$keyLine = explode("\n", $key)[1];
    }

    public static function tearDownAfterClass(): void
    {
        Certificates::remove(self::$root);
    }

    protected function tearDown(): void
    {
        FakeSliceRules::forget();
    }

    public function test_types_basicOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_BASIC], CheckIsdsAccounts::types());
    }

    public function test_findings_noAccountIsOk(): void
    {
        $findings = self::check([])->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('No ISDS account in misc/datovka/datovka_conf.php sends for a site of this database', $findings[0]->title);
    }

    /** a file AA cannot use has no account read - no OK, CheckIsdsConf tells what to fix */
    #[DataProvider('fileAaCannotUse')]
    public function test_findings_fileAaCannotUseIsNoOk(bool $broken): void
    {
        $check        = self::check([]);
        $check->conf  = Sending::fileConf($broken);
        $check->pages = [self::notReadyPage()];

        $findings = $check->findings();

        self::assertSame(['page:' . self::SITE . ':' . self::CAMPAIGN, 'file'], self::keys($findings));
        self::assertSame([Finding::INFO, 'ISDS accounts not checked: AA cannot use the accounts of misc/datovka/datovka_conf.php', 'The check "ISDS settings file" tells what to fix.'], [$findings[1]->severity, $findings[1]->title, $findings[1]->advice]);
    }

    /** @return array<string, array{bool}> */
    public static function fileAaCannotUse(): array
    {
        return ['unreadable' => [false], 'broken' => [true]];
    }

    /**
     * One tree serves several databases: each deals with the accounts of its sites. An account of no
     * site read is a fault of the file, named in each - its files and slices only in the database of its
     * slices. The message slice is not ready, so a check of the slices shows.
     * @param list<string> $here     the sites and slices of this database
     * @param list<string> $findings keys, or the title of an OK
     */
    #[DataProvider('databases')]
    public function test_findings_accountsOfThisDatabase(array $accounts, array $here, array $findings): void
    {
        $check                               = self::check($accounts);
        $check->here                         = $here;
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, ['reading_password' => '']);

        $found = $check->findings();

        self::assertSame($findings, array_map(static fn(Finding $f): string => ($f->severity === Finding::OK) ? $f->title : $f->key, $found));
        self::assertNoSecret($found, ['short-1']);
        self::assertCount(1, $check->asked, 'one query for all accounts');
    }

    // the sites of all accounts and the slices of those of no site read, in one query
    public function test_findings_asksTheDatabaseOnce(): void
    {
        $other              = ['cafe0000000000000000000000000005', 'beef0000000000000000000000000006'];
        $accounts           = self::account(['sites' => null]) + ['ECN' => Sending::account(self::SITE2, $other, ['sites' => 'x'])] + ['ABC' => Sending::account(self::SITE2, ['cafe0000000000000000000000000007', 'beef0000000000000000000000000008'])];
        $check              = self::check($accounts);
        $check->here        = [];

        self::assertSame(['account:test:ECN', 'account:test:NSZM'], self::keys($check->findings()));
        self::assertSame([[self::SITE2, ...$other, self::CAMPAIGN, self::MESSAGE]], $check->asked);
    }

    public static function databases(): array
    {
        $broken = self::account(['password' => 'short-1']);
        $both   = ['account:test:NSZM', 'slices:test:NSZM'];
        $none   = 'No ISDS account in misc/datovka/datovka_conf.php sends for a site of this database';
        return [
            'an account of this database'          => [$broken, [self::SITE], $both],
            'an account of another'                => [$broken, [self::SITE2, self::CAMPAIGN], [$none]],
            'two sites, one of this database'      => [self::account(['sites' => [self::SITE2, self::SITE], 'password' => 'short-1']), [self::SITE], $both],
            'a usable account of this database'    => [self::account(), [self::SITE], ['slices:test:NSZM']],
            'no site read, slices of another'      => [self::account(['sites' => null]), [self::SITE2], ['account:test:NSZM']],
            'sites no list, slices of another'     => [self::account(['sites' => self::SITE]), [], ['account:test:NSZM']],
            'no site read, slices of this one'     => [self::account(['sites' => null]), [self::CAMPAIGN], $both],
            'certificate of an account of another' => [self::certificateAccount(['cert' => 'none.pem']), [self::SITE2], [$none]],
            'certificate of no site read'          => [['NSZM' => ['sites' => null] + self::certificateAccount(['cert' => 'none.pem'])['NSZM']], [self::SITE2], ['account:production:NSZM']],
        ];
    }

    public function test_findings_readyIsOk(): void
    {
        $findings = self::check(self::account())->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('ISDS accounts ready to send: NSZM (test)', $findings[0]->title);
    }

    public function test_findings_brokenAccountNamesTheSettingsNeverTheValues(): void
    {
        $findings = self::check(self::account(['password' => 'short-1']))->findings();

        self::assertSame([Finding::ERROR], self::severities($findings), 'the slices are fine');
        $finding = $findings[0];
        self::assertSame('ISDS account NSZM (test) cannot be used', $finding->title);
        self::assertSame('account:test:NSZM', $finding->key);
        self::assertStringContainsString(self::setting('password'), $finding->evidence[0]['Problem']);
        self::assertStringContainsString("The account is DATOVKA_CONF['accounts']['NSZM'], with the keys environment, auth, sites, slices, test_boxes, login, password.", $finding->advice);
        self::assertNoSecret($findings, ['short-1']);
    }

    // without its site the slices are still checked; without its slices there is nothing to check
    public function test_findings_accountWithoutSiteOrSlices(): void
    {
        $check = self::check(self::account(['sites' => null]));
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, ['reading_password' => '']);

        self::assertSame(['account:test:NSZM', 'slices:test:NSZM'], self::keys($check->findings()));

        $accounts = self::account();
        unset($accounts['NSZM']['slices']);
        $findings = self::check($accounts)->findings();
        self::assertSame(['account:test:NSZM'], self::keys($findings));
        self::assertStringStartsWith('Set ' . self::setting('slices') . ' to ', $findings[0]->evidence[0]['Problem']);
        self::assertNoSecret($findings);
    }

    /**
     * A misspelt key of an account of another database is for that one to show; an account of a name AA
     * does not read, or of no site read, is a fault of the file, shown in each.
     */
    public function test_findings_straysOfThisDatabase(): void
    {
        $accounts            = ['NSZM' => Sending::account(self::SITE2, [self::CAMPAIGN, self::MESSAGE], ['pasword' => 'stray-value-1'])];
        $accounts['ECN']     = Sending::account(self::SITE, ['cafe0000000000000000000000000005', 'beef0000000000000000000000000006'], ['sites' => null, 'cret' => 'stray-value-2']);
        $accounts['Nszm']    = ['password' => 'stray-value-3'];
        $check               = self::check($accounts);
        $check->here         = [self::SITE];

        $findings = $check->findings();

        self::assertSame(['account:test:ECN', 'strays'], self::keys($findings));
        self::assertSame([['Setting' => Txt::safe("DATOVKA_CONF['accounts']['ECN']['cret']")], ['Setting' => Txt::safe("DATOVKA_CONF['accounts']['Nszm']")]], $findings[1]->evidence);
        self::assertNoSecret($findings, ['stray-value-1', 'stray-value-2', 'stray-value-3']);
    }

    public function test_findings_straysNamed(): void
    {
        $accounts                    = self::account();
        $accounts['NSZM']['pasword'] = 'stray-value-1';
        $accounts['Nszm']            = ['password' => 'stray-value-2'];

        $findings = self::check($accounts)->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        $finding = $findings[0];
        self::assertSame('misc/datovka/datovka_conf.php has settings of ISDS accounts AA does not read: 2', $finding->title);
        self::assertSame([2, 'strays'], [$finding->count, $finding->key]);
        self::assertSame([['Setting' => self::setting('pasword')], ['Setting' => Txt::safe("DATOVKA_CONF['accounts']['Nszm']")]], $finding->evidence);
        self::assertStringContainsString('AA reads only the keys environment, auth, sites, slices, test_boxes, login, password, box, cert, key, passphrase.', $finding->advice);
        self::assertNoSecret($findings, ['stray-value-1', 'stray-value-2']);
    }

    // production lets an application in with a certificate only
    public function test_findings_productionWithNameAndPassword(): void
    {
        $check = self::check(self::account(['environment' => 'production']));
        $check->rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '']);

        $findings = $check->findings();

        self::assertSame([Finding::ERROR, Finding::ERROR], self::severities($findings));
        self::assertSame('ISDS account NSZM (production) cannot be used', $findings[0]->title);
        self::assertSame(['account:production:NSZM', 'slices:production:NSZM'], self::keys($findings));
        self::assertSame([['Problem' => Txt::safe("Set DATOVKA_CONF['accounts']['NSZM']['auth'] to 'cert' or 'hosted' and remove DATOVKA_CONF['accounts']['NSZM']['login'] and DATOVKA_CONF['accounts']['NSZM']['password'] - AA never logs in to production ISDS with a name and a password")]], $findings[0]->evidence);
        self::assertStringContainsString('with the keys environment, auth, sites, slices, test_boxes, box, cert, key, passphrase.', $findings[0]->advice, 'the keys it needs');
        self::assertStringNotContainsString('login', $findings[0]->advice);
        self::assertNoSecret($findings);
    }

    /** @param array<string, string> $set cert, key, box, passphrase */
    #[DataProvider('readyCertificates')]
    public function test_findings_certificateReady(array $set, string $where = 'outside'): void
    {
        $findings = self::check(self::certificateAccount($set, $where))->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('ISDS accounts ready to send: NSZM (production)', $findings[0]->title);
    }

    public static function readyCertificates(): array
    {
        return [
            'one file'                        => [['cert' => 'both.pem']],
            'hosted'                          => [['cert' => 'both.pem', 'box' => 'kv62bqf']],
            'a certificate anyone reads, the key apart' => [['cert' => 'cert.pem', 'key' => 'key.pem']],
            'encrypted key'                   => [['cert' => 'cert.pem', 'key' => 'encrypted.key', 'passphrase' => self::PASSPHRASE]],
            'in the directory of AA'          => [['cert' => 'both.pem'], 'inside'],
        ];
    }

    /**
     * What PHP finds in the files: one finding per thing to fix, the files named - never the key or
     * the passphrase
     * @param array<string, string> $set  cert, key, passphrase: files outside the directory of AA
     * @param list<string>          $keys
     */
    #[DataProvider('certificateProblems')]
    public function test_findings_certificateFiles(array $set, array $keys, string $evidence): void
    {
        $findings = self::check(self::certificateAccount($set))->findings();

        self::assertSame($keys, self::keys($findings));
        self::assertSame(array_fill(0, count($keys), Finding::ERROR), self::severities($findings));
        self::assertStringContainsString($evidence, print_r(array_column($findings, 'evidence'), true));
        self::assertNoSecret($findings, [self::PASSPHRASE, self::$keyLine]);
    }

    public static function certificateProblems(): array
    {
        $invalid = ['certificate-invalid:production:NSZM'];
        return [
            'no such file'           => [['cert' => 'none.pem'], ['certificate-file:production:NSZM'], self::setting('cert')],
            'no such key file'       => [['cert' => 'cert.pem', 'key' => 'none.key'], ['certificate-file:production:NSZM'], 'none.key'],
            'a key others may read in the certificate file' => [['cert' => 'other.pem', 'key' => 'key.pem'], ['certificate-access:production:NSZM'], self::setting('cert')],
            'a key others may read apart' => [['cert' => 'cert.pem', 'key' => 'other.pem'], ['certificate-access:production:NSZM'], self::setting('key')],
            'a key others may read, no other file' => [['cert' => 'other.pem', 'key' => 'none.key'], ['certificate-access:production:NSZM', 'certificate-file:production:NSZM'], 'other.pem'],
            'not a certificate'      => [['cert' => 'garbage.pem', 'key' => 'key.pem'], $invalid, self::setting('cert') . ' names a file with no PEM certificate'],
            'no key'                 => [['cert' => 'cert.pem'], $invalid, 'The private key in ' . self::setting('cert') . ' does not open'],
            'another key'            => [['cert' => 'cert.pem', 'key' => 'another.key'], $invalid, 'does not belong to the certificate'],
            'a weak key'             => [['cert' => 'weak.pem'], $invalid, 'The private key is shorter than 2048 bits'],
            'a huge file'            => [['cert' => 'huge.pem'], $invalid, self::setting('cert') . ' names a file with no PEM certificate'],
            'a key beyond 1 MiB'     => [['cert' => 'late.pem'], $invalid, self::setting('cert') . ' names a file with no PEM certificate'],
            'no passphrase'          => [['cert' => 'cert.pem', 'key' => 'encrypted.key'], $invalid, self::setting('passphrase') . ' does not fit'],
            'another passphrase'     => [['cert' => 'cert.pem', 'key' => 'encrypted.key', 'passphrase' => 'Another-pass-phrase'], $invalid, self::setting('passphrase') . ' does not fit'],
            'damaged setting'        => [['cert' => "both.pem\n"], ['account:production:NSZM'], self::setting('cert') . ' holds a line break'],
        ];
    }

    /**
     * Inside the directory of AA the web server may serve a key the group may read; outside, only other
     * users must not read it. Inside is decided on the path as written and as resolved, with the links
     * the web server follows - and on the directory of AA as config.php writes it and as PHP resolved it.
     * @param string       $cert  the file, under $root
     * @param list<string> $bases the directory of AA under $root; none = not known
     * @param string       $title '' = no finding
     */
    #[DataProvider('keyAccess')]
    public function test_findings_privateKeyAccess(string $cert, array $bases, string $title, string $mode = ''): void
    {
        $accounts                 = self::certificateAccount([]);
        $accounts['NSZM']['cert'] = self::$root . "/$cert";
        $check                    = self::check($accounts);
        $check->bases             = array_map(static fn(string $base): string => self::$root . "/$base", $bases);

        $findings = $check->findings();

        if ($title === '') {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        self::assertSame(['certificate-access:production:NSZM'], self::keys($findings));
        self::assertSame("$title NSZM (production)", $findings[0]->title);
        self::assertSame([['Setting' => self::setting('cert'), 'File' => self::$root . "/$cert", 'Permissions' => $mode]], $findings[0]->evidence);
    }

    public static function keyAccess(): array
    {
        $served = 'The web server may serve the private key of ISDS account';
        $others = 'Other users of the server may read the private key of ISDS account';
        return [
            'outside, the owner only'         => ['aa-keys/both.pem', ['aa'], ''],
            'beside, the group'               => ['aa-keys/group.pem', ['aa'], ''],
            'outside, others'                 => ['aa-keys/other.pem', ['aa'], $others, '0604'],
            'inside, the owner only'          => ['aa/both.pem', ['aa'], ''],
            'inside, the group'               => ['aa/group.pem', ['aa'], $served, '0640'],
            'inside, others'                  => ['aa/other.pem', ['aa'], $served, '0604'],
            'a link inside to a key outside'  => ['aa/link.pem', ['aa'], $served, '0640'],
            'a link outside to a key inside'  => ['aa-keys/in.pem', ['aa'], $served, '0640'],
            'a link inside, by a linked path' => ['alias/link.pem', ['aa'], $served, '0640'],
            'up and out by ..'                => ['aa/../aa-keys/group.pem', ['aa'], ''],
            'a linked base'                   => ['aa/group.pem', ['aa-link'], $served, '0640'],
            'a link out of the linked base'   => ['aa-link/keys/group.pem', ['aa-link'], $served, '0640'],
            'the same, the base with ..'      => ['aa-link/keys/group.pem', ['aa-keys/../aa-link'], $served, '0640'],
            // config.php names the tree by a link, PHP knows it resolved: the web server serves the link too
            'a link out of the written base'  => ['aa-link/keys/group.pem', ['aa-link', 'aa'], $served, '0640'],
            'the same, the written base second' => ['aa-link/keys/group.pem', ['aa', 'aa-link'], $served, '0640'],
            'the same, the resolved base only' => ['aa-link/keys/group.pem', ['aa'], ''],
            'no base, the group'              => ['aa-keys/group.pem', [], $served, '0640'],
        ];
    }

    // PHP inside the web server reads the key for it: inside the directory of AA no mode helps
    #[DataProvider('webServerModule')]
    public function test_findings_keyOfPhpInsideTheWebServer(string $where, bool $served): void
    {
        $check       = self::check(self::certificateAccount(['cert' => 'both.pem'], $where));
        $check->sapi = 'apache2handler';

        $findings = $check->findings();

        if (!$served) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        self::assertSame(['certificate-access:production:NSZM'], self::keys($findings));
        self::assertSame('The web server may serve the private key of ISDS account NSZM (production)', $findings[0]->title);
        self::assertStringContainsString('Move the file outside the directory of AA', $findings[0]->advice);
    }

    public static function webServerModule(): array
    {
        return ['inside, the owner only' => ['inside', true], 'outside, the owner only' => ['outside', false]];
    }

    // reading a FIFO nobody writes to would wait for ever
    public function test_findings_fifoNeverRead(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('no posix_mkfifo()');
        }
        $dir = Certificates::directory();
        posix_mkfifo("$dir/fifo.pem", 0o604);
        try {
            $accounts                 = self::certificateAccount([]);
            $accounts['NSZM']['cert'] = "$dir/fifo.pem";
            $findings                 = self::check($accounts)->findings();
        } finally {
            Certificates::remove($dir);
        }

        self::assertSame(['certificate-file:production:NSZM'], self::keys($findings));
    }

    // a file that is there, but PHP may not read it
    public function test_findings_unreadableCertificate(): void
    {
        if (function_exists('posix_geteuid') && (posix_geteuid() === 0)) {
            self::markTestSkipped('root reads any file');
        }

        $findings = self::check(self::certificateAccount(['cert' => 'locked.pem']))->findings();

        self::assertSame(['certificate-file:production:NSZM'], self::keys($findings));
        self::assertStringContainsString('open_basedir', $findings[0]->advice);
    }

    public function test_findings_certificateNotValidYet(): void
    {
        $check      = self::check(self::certificateAccount(['cert' => 'both.pem']));
        $check->now = self::$from - 1;

        $findings = $check->findings();

        self::assertSame(['certificate-invalid:production:NSZM'], self::keys($findings));
        self::assertSame('The certificate is valid from ' . date('Y-m-d H:i', self::$from) . ' only', $findings[0]->evidence[0]['Problem']);
    }

    // ISDS wants a new certificate 30 days ahead
    #[DataProvider('expiries')]
    public function test_findings_certificateExpiry(int $left, ?string $severity, string $title): void
    {
        $check      = self::check(self::certificateAccount(['cert' => 'both.pem']));
        $check->now = self::$until - $left;

        $findings = $check->findings();

        if (is_null($severity)) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        self::assertSame([[$severity], ['certificate-expiry:production:NSZM']], [self::severities($findings), self::keys($findings)]);
        self::assertSame(sprintf($title, date('Y-m-d', self::$until)), $findings[0]->title);
        self::assertSame(['Subject', 'Serial number', 'Valid until'], array_keys($findings[0]->evidence[0]));
        self::assertStringContainsString('CN=AA test', $findings[0]->evidence[0]['Subject']);
        self::assertSame('2A', $findings[0]->evidence[0]['Serial number']);
    }

    public static function expiries(): array
    {
        $expires = 'The certificate of ISDS account NSZM (production) expires on %s';
        return [
            '30 days'          => [30 * self::DAY, null, ''],
            'under 30 days'    => [30 * self::DAY - 1, Finding::WARNING, $expires],
            '7 days'           => [7 * self::DAY, Finding::WARNING, $expires],
            'under 7 days'     => [7 * self::DAY - 1, Finding::ERROR, $expires],
            'expired'          => [-1, Finding::ERROR, 'The certificate of ISDS account NSZM (production) expired on %s'],
        ];
    }

    public function test_findings_sliceProblemWithItsPage(): void
    {
        $check = self::check(self::account());
        $check->rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '0']);
        $check->rules->outlets[self::CAMPAIGN] = ['notify'];
        $check->rules->names[self::CAMPAIGN]   = 'Rozesílky <b>NSZM</b>';

        $findings = $check->findings();

        self::assertSame([Finding::ERROR], self::severities($findings));
        $finding = $findings[0];
        self::assertSame('The slices of ISDS account NSZM (test) are not ready for sending', $finding->title);
        self::assertSame(['slices:test:NSZM', 2, 'Rozesílky <b>NSZM</b>'], [$finding->key, $finding->count, $finding->place]);
        self::assertSame(safe('Slice "Rozesílky <b>NSZM</b>" has no reading password - fill in Password for Reading in the settings of the slice'), $finding->evidence[0]['Problem']);
        self::assertSame('Fix what the evidence names before sending from these slices.', $finding->advice);
        self::assertSame(['Slice settings: Rozesílky <b>NSZM</b>', 'Email Notification: Rozesílky <b>NSZM</b>'], array_keys($finding->links));
        self::assertStringContainsString(SliceRules::SLICE_PAGE . '?change_id=' . self::CAMPAIGN, $finding->links['Slice settings: Rozesílky <b>NSZM</b>']);
        self::assertStringContainsString(SliceRules::NOTIFY_PAGE . '?change_id=' . self::CAMPAIGN, $finding->links['Email Notification: Rozesílky <b>NSZM</b>']);
    }

    public function test_findings_openSliceOfAnUnusableAccount(): void
    {
        $check = self::check(self::account(['login' => null]));
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, ['permit_anonymous_post' => '1']);

        $findings = $check->findings();

        self::assertSame([Finding::ERROR, Finding::ERROR], self::severities($findings));
        self::assertSame(['account:test:NSZM', 'slices:test:NSZM'], self::keys($findings));
        self::assertStringContainsString('Allow anonymous posting of items: Not allowed', $findings[1]->evidence[0]['Problem']);
    }

    // a slice's own problem once, a site's for each site; two sites of one name told apart
    public function test_findings_twoSitesOfOneName(): void
    {
        $check = self::check(self::account(['sites' => [self::SITE, self::SITE2]]));
        $check->rules->sites[]              = self::SITE2;
        $check->rules->names[self::SITE2]   = 'Terminal';
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, without: ['switch.........1']);

        $finding = $check->findings()[0];

        self::assertSame([
            ['Problem' => 'Site &quot;Terminal&quot; does not use slice ' . self::CAMPAIGN . ' - add the slice to Uses modules in the settings of the site'],
            ['Problem' => 'Site &quot;Terminal&quot; does not use slice ' . self::MESSAGE . ' - add the slice to Uses modules in the settings of the site'],
            ['Problem' => 'Slice &quot;ISDS messages&quot; lacks fields of a message slice: switch.........1'],
        ], $finding->evidence);
        self::assertSame(['Site settings: Terminal', 'Fields: ISDS messages'], array_keys($finding->links));
        self::assertStringContainsString('change_id=' . self::SITE2, $finding->links['Site settings: Terminal']);

        $check->rules->related[self::SITE] = [];
        $links = $check->findings()[0]->links;
        self::assertSame(['Site settings: Terminal (51de0000)', 'Site settings: Terminal (5e7e0000)', 'Fields: ISDS messages'], array_keys($links));
        self::assertStringContainsString('change_id=' . self::SITE, $links['Site settings: Terminal (51de0000)']);
    }

    /** a page of SITE logged by AA\Isds\Gate, as LogOnce::recent() gives it */
    private static function notReadyPage(string $campaign = self::CAMPAIGN): array
    {
        return ['selector' => self::SITE . ':' . $campaign, 'days' => '3', 'first' => '1790600000', 'last' => '1790800000', 'params' => 'Slice "ISDS <b>campaigns</b>" has no reading password'];
    }

    public function test_findings_pageNotReady(): void
    {
        $check        = self::check(self::account());
        $check->pages = [self::notReadyPage(), self::notReadyPage('-')];

        $findings = $check->findings();

        self::assertSame([Finding::WARNING, Finding::WARNING], self::severities($findings), 'the accounts are ready - the pages only');
        $finding = $findings[0];
        self::assertSame('A page of site Terminal has a mass sending whose settings are not ready', $finding->title);
        self::assertSame(['page:' . self::SITE . ':' . self::CAMPAIGN, 3, 1790600000, 1790800000, 'Terminal'], [$finding->key, $finding->count, $finding->since, $finding->last, $finding->place]);
        self::assertSame(['Campaign slice' => 'ISDS campaigns', 'Problem' => 'Slice &quot;ISDS &lt;b&gt;campaigns&lt;/b&gt;&quot; has no reading password', 'Days seen' => '3', 'Last' => date('Y-m-d', 1790800000)], $finding->evidence[0]);
        self::assertStringContainsString('srchbr_value%5B1%5D=ISDS_SETUP', $finding->links['Log rows']);
        self::assertSame('-', $findings[1]->evidence[0]['Campaign slice'], 'settings naming no campaign slice');
    }

    // nobody may fix it on the page there - the administrator switches the site
    public function test_findings_pageNotReadyOnASiteOnlyReadersLogInto(): void
    {
        $check                              = self::check(self::account());
        $check->pages                       = [self::notReadyPage()];
        $check->rules->permModes[self::SITE] = '2';

        $finding = array_values(array_filter($check->findings(), static fn(Finding $f): bool => str_starts_with($f->key, 'page:')))[0];

        self::assertStringStartsWith('Site "Terminal" lets only readers of its Reader Management slices log in, so nobody may send there, nor see on the page what to fix', $finding->advice);
        self::assertSame(['Log rows', 'Site settings'], array_keys($finding->links));
        self::assertStringContainsString(SliceRules::SITE_PAGE . '?change_id=' . self::SITE, $finding->links['Site settings']);
    }

    public function test_findings_pageNotReadyWithoutAnAccount(): void
    {
        $check        = self::check([]);
        $check->pages = [self::notReadyPage()];

        self::assertSame(['page:' . self::SITE . ':' . self::CAMPAIGN], self::keys($check->findings()));
    }

    // a campaign whose stored filter the page no longer offers - the page showed no recipients for it
    public function test_findings_campaignCannotTellItsRecipients(): void
    {
        $check            = self::check([]);
        $selector         = self::SITE . ':' . self::CAMPAIGN . ':ca00000000000000000000000000000b';
        $check->campaigns = [
            ['selector' => $selector, 'days' => '2', 'first' => '1790700000', 'last' => '1790800000', 'params' => 'The page does not offer the types <b>kraj</b>'],
            ['selector' => 'x', 'days' => '1', 'first' => '1790800000', 'last' => '1790800000', 'params' => ''],
        ];

        $findings = $check->findings();

        self::assertSame(['recipients:' . $selector, 'recipients:x'], self::keys($findings));
        $finding = $findings[0];
        self::assertSame([Finding::WARNING, 'A campaign of a mass sending on site Terminal cannot tell its recipients', 2, 1790700000, 1790800000, 'Terminal'], [$finding->severity, $finding->title, $finding->count, $finding->since, $finding->last, $finding->place]);
        self::assertSame(['Campaign slice' => 'ISDS campaigns', 'Campaign' => 'ca00000000000000000000000000000b', 'Problem' => 'The page does not offer the types &lt;b&gt;kraj&lt;/b&gt;', 'Days seen' => '2', 'Last' => date('Y-m-d', 1790800000)], $finding->evidence[0]);
        self::assertStringContainsString('save its recipients again', $finding->advice);
        self::assertStringContainsString('srchbr_value%5B1%5D=ISDS_RCPT', $finding->links['Log rows']);
        self::assertSame(['-', '-'], [$findings[1]->evidence[0]['Campaign slice'], $findings[1]->evidence[0]['Campaign']], 'a selector of another shape');
    }

    // an action of a page that failed inside AA: where, never what the exception said
    public function test_findings_internalActionFailures(): void
    {
        $check          = self::check([]);
        $selector       = 'resume:ca00000000000000000000000000000b';
        $check->actions = [
            ['selector' => $selector, 'days' => '2', 'first' => '1790700000', 'last' => '1790800000', 'params' => '#' . self::SITE . ' RuntimeException src/Isds/<b>SendRun.php:309'],
            ['selector' => 'save:', 'days' => '1', 'first' => '1790800000', 'last' => '1790800000', 'params' => ''],
        ];

        $findings = $check->findings();

        self::assertSame(['action:' . $selector, 'action:save:'], self::keys($findings));
        $finding = $findings[0];
        self::assertSame([Finding::WARNING, 'An action of a page of a mass sending failed on an internal error: resume', 2, 1790700000, 1790800000, 'Terminal'], [$finding->severity, $finding->title, $finding->count, $finding->since, $finding->last, $finding->place]);
        self::assertSame(['Action' => 'resume', 'Campaign' => 'ca00000000000000000000000000000b', 'Where' => '#' . self::SITE . ' RuntimeException src/Isds/&lt;b&gt;SendRun.php:309', 'Days seen' => '2', 'Last' => date('Y-m-d', 1790800000)], $finding->evidence[0]);
        self::assertStringContainsString('may have done part of its work', $finding->advice);
        self::assertStringContainsString('srchbr_value%5B1%5D=ISDS_ACT', $finding->links['Log rows']);
        self::assertSame(['-', ''], [$findings[1]->evidence[0]['Campaign'], $findings[1]->place], 'a new draft has no campaign yet; no site logged');
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, string> $set   cert and key name files in $where, passphrase and box are as they are
     * @param string                $where 'inside' or 'outside' the directory of AA
     * @return array<string, array<string, mixed>> a production account with a certificate, as DATOVKA_CONF['accounts']
     */
    private static function certificateAccount(array $set, string $where = 'outside'): array
    {
        $account = ['environment' => 'production', 'auth' => isset($set['box']) ? 'hosted' : 'cert', 'sites' => [self::SITE], 'slices' => [self::CAMPAIGN, self::MESSAGE]];
        foreach ($set as $key => $value) {
            $account[$key] = in_array($key, ['cert', 'key'], true) ? self::dir($where) . "/$value" : $value;
        }

        return ['NSZM' => $account];
    }

    private static function dir(string $where): string
    {
        return ($where === 'inside') ? self::$inside : self::$outside;
    }

    /** @param array<array-key, mixed> $accounts as DATOVKA_CONF['accounts'] */
    private static function check(array $accounts): ScriptedIsdsAccountsCheck
    {
        $check           = new ScriptedIsdsAccountsCheck(FakeSliceRules::ready(self::SITE, self::CAMPAIGN, self::MESSAGE));
        $check->accounts = $accounts;
        $check->bases    = [self::$inside];
        $check->now      = self::$from + self::DAY;

        return $check;
    }

    /**
     * @param array<string, mixed> $over keys to set instead; null leaves one out
     * @return array<string, array<string, mixed>> the test account NSZM of SITE, as DATOVKA_CONF['accounts']
     */
    private static function account(array $over = []): array
    {
        return ['NSZM' => Sending::account(self::SITE, [self::CAMPAIGN, self::MESSAGE], $over)];
    }

    /** a key of the account NSZM as the evidence shows it */
    private static function setting(string $key): string
    {
        return Txt::safe("DATOVKA_CONF['accounts']['NSZM']['$key']");
    }

    /**
     * @param Finding[]    $findings
     * @param list<string> $more other values that must not show
     */
    private static function assertNoSecret(array $findings, array $more = []): void
    {
        $all = serialize($findings);
        foreach ([self::PASSWORD, self::LOGIN, ...$more] as $secret) {
            self::assertStringNotContainsString($secret, $all);
        }
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
