<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\Registry\RegisterIndex;
use AA\Isds\SliceRules;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsAccounts;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\Certificates;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Optimize\Fixtures\ScriptedIsdsAccountsCheck;

/**
 * CheckIsdsAccounts: the ISDS accounts of config.php with what to fix in their constants - named,
 * never their values - and whether their site and slices pass SliceRules. The constants are passed
 * in, the slices come from FakeSliceRules.
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
     * The files of this run, made in setUpBeforeClass(): $root/aa is AA_BASE_PATH of the tests ($inside),
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
        $findings = self::check(['AA_APIKEY_CSU' => 'x', 'AA_ID' => 'y'])->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('No ISDS account is set up in include/config.php', $findings[0]->title);
    }

    public function test_findings_readyIsOk(): void
    {
        $findings = self::check(self::account())->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('ISDS accounts ready to send: AA_APIKEY_ISDSTEST_NSZM', $findings[0]->title);
    }

    public function test_findings_brokenAccountNamesTheConstantsNeverTheValues(): void
    {
        $findings = self::check(self::account(password: 'short-1'))->findings();

        self::assertSame([Finding::ERROR], self::severities($findings), 'the slices are fine');
        $finding = $findings[0];
        self::assertSame('ISDS account AA_APIKEY_ISDSTEST_NSZM cannot be used', $finding->title);
        self::assertSame('account:test:NSZM', $finding->key);
        self::assertStringContainsString('AA_APIKEY_ISDSTEST_NSZM_PASSWORD', $finding->evidence[0]['Problem']);
        self::assertStringContainsString('AA_APIKEY_ISDSTEST_NSZM_SITE, AA_APIKEY_ISDSTEST_NSZM_SLICES, AA_APIKEY_ISDSTEST_NSZM_LOGIN, AA_APIKEY_ISDSTEST_NSZM_PASSWORD', $finding->advice);
        self::assertNoSecret($findings, ['short-1']);
    }

    // without its site the slices are still checked; without its slices there is nothing to check
    public function test_findings_accountWithoutSiteOrSlices(): void
    {
        $check = self::check(self::account(site: null));
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, ['reading_password' => '']);

        self::assertSame(['account:test:NSZM', 'slices:test:NSZM'], self::keys($check->findings()));

        $constants = self::account();
        unset($constants['AA_APIKEY_ISDSTEST_NSZM_SLICES']);
        $findings = self::check($constants)->findings();
        self::assertSame(['account:test:NSZM'], self::keys($findings));
        self::assertStringContainsString('Set AA_APIKEY_ISDSTEST_NSZM_SLICES in config.php', $findings[0]->evidence[0]['Problem']);
        self::assertNoSecret($findings);
    }

    // a mass sending checks its recipients in the register and sends nothing without it
    public function test_findings_registerNotDownloaded(): void
    {
        $check = self::check(self::account());
        $check->register = null;

        $findings = $check->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertSame('register:test:NSZM', $findings[0]->key);
        self::assertSame('ISDS account AA_APIKEY_ISDSTEST_NSZM cannot send a mass sending: the register of data boxes has not been downloaded', $findings[0]->title);
        self::assertSame(1, $check->registerLooks, 'one look for all accounts');

        // no slices, nothing it sends for
        $constants = self::account();
        unset($constants['AA_APIKEY_ISDSTEST_NSZM_SLICES']);
        $bare = self::check($constants);
        $bare->register = null;
        self::assertSame(['account:test:NSZM'], self::keys($bare->findings()));

        $none = self::check(['AA_ID' => 'y']);
        $none->register = null;
        self::assertSame([Finding::OK], self::severities($none->findings()));
        self::assertSame(0, $none->registerLooks, 'no account, no look');
    }

    // only a register the record vouches for serves a mass sending; the finding names the problem in
    // the words of the page of the register
    public function test_findings_registerOnlyAsTheRecordVouchesForIt(): void
    {
        $check = self::check(self::account());
        self::assertNotContains('register:test:NSZM', self::keys($check->findings()), 'the recorded one');

        $check->register = ['state' => RegisterIndex::UNRECORDED, 'time' => 0, 'size' => 0, 'sha256' => ''];
        $finding = $check->findings()[0];
        self::assertSame(['register:test:NSZM', 'ISDS account AA_APIKEY_ISDSTEST_NSZM cannot send a mass sending'], [$finding->key, $finding->title]);
        self::assertSame([['Problem' => 'AA has no record of downloading this copy of the register']], $finding->evidence);

        $check->register = ['state' => RegisterIndex::REPLACED, 'time' => 1790841600, 'size' => 17000000, 'sha256' => str_repeat('a', 64)];
        self::assertSame([['Problem' => 'The register of data boxes is not the one AA downloaded']], $check->findings()[0]->evidence);

        $check->register = new \RuntimeException('PHP cannot look into the directory /srv/aa/img_upload');
        self::assertNotContains('register:test:NSZM', self::keys($check->findings()), 'CheckIsdsRegistry names the directory');
    }

    // UploadDir reads no attachment where PHP cannot list /proc/self/fd
    public function test_findings_attachmentsWithoutKernelPaths(): void
    {
        $check              = self::check(self::account());
        $check->kernelPaths = false;

        $findings = $check->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertSame('kernel-paths:test:NSZM', $findings[0]->key);
        self::assertSame('Mass sending from ISDS account AA_APIKEY_ISDSTEST_NSZM cannot attach files until /proc/self/fd is added to open_basedir', $findings[0]->title);
        self::assertStringContainsString('the PHP that sends, on the web or in cron, needs the same', $findings[0]->advice);
        self::assertNoSecret($findings);

        // no slices, no mass sending
        $constants = self::account();
        unset($constants['AA_APIKEY_ISDSTEST_NSZM_SLICES']);
        $bare              = self::check($constants);
        $bare->kernelPaths = false;
        self::assertSame(['account:test:NSZM'], self::keys($bare->findings()));
    }

    public function test_findings_straysNamed(): void
    {
        $findings = self::check(self::account() + ['AA_APIKEY_ISDSTEST_LOGIN' => 'stray-value-1', 'AA_APIKEY_ISDSTEST_Nszm_PASSWORD' => 'stray-value-2'])->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        $finding = $findings[0];
        self::assertSame('include/config.php has ISDS constants AA does not read: 2', $finding->title);
        self::assertSame([2, 'strays'], [$finding->count, $finding->key]);
        self::assertSame([['Constant' => 'AA_APIKEY_ISDSTEST_LOGIN'], ['Constant' => 'AA_APIKEY_ISDSTEST_Nszm_PASSWORD']], $finding->evidence);
        self::assertStringContainsString('AA_APIKEY_ISDSTEST_<ACCOUNT>_SITE, AA_APIKEY_ISDSTEST_<ACCOUNT>_SLICES, AA_APIKEY_ISDSTEST_<ACCOUNT>_LOGIN, AA_APIKEY_ISDSTEST_<ACCOUNT>_PASSWORD', $finding->advice);
        self::assertStringContainsString('production ones start with AA_APIKEY_ISDS_.', $finding->advice);
        self::assertNoSecret($findings, ['stray-value-1', 'stray-value-2']);
    }

    // production lets an application in with a certificate only
    public function test_findings_productionWithNameAndPassword(): void
    {
        $check = self::check(self::account(environment: 'ISDS'));
        $check->rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '']);

        $findings = $check->findings();

        self::assertSame([Finding::ERROR, Finding::ERROR], self::severities($findings));
        self::assertSame('ISDS account AA_APIKEY_ISDS_NSZM cannot be used', $findings[0]->title);
        self::assertSame(['account:production:NSZM', 'slices:production:NSZM'], self::keys($findings));
        self::assertStringContainsString('Set AA_APIKEY_ISDS_NSZM_CERT', implode(' ', array_column($findings[0]->evidence, 'Problem')));
        self::assertStringContainsString('Remove AA_APIKEY_ISDS_NSZM_LOGIN and AA_APIKEY_ISDS_NSZM_PASSWORD', implode(' ', array_column($findings[0]->evidence, 'Problem')));
        self::assertStringContainsString('AA_APIKEY_ISDS_NSZM_CERT', $findings[0]->advice, 'the constants it needs');
        self::assertStringNotContainsString('_LOGIN', $findings[0]->advice);
        self::assertNoSecret($findings);
    }

    /** @param array<string, string> $set CERT, KEY, BOX, PASSPHRASE */
    #[DataProvider('readyCertificates')]
    public function test_findings_certificateReady(array $set, string $where = 'outside'): void
    {
        $findings = self::check(self::certificateAccount($set, $where))->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('ISDS accounts ready to send: AA_APIKEY_ISDS_NSZM', $findings[0]->title);
    }

    public static function readyCertificates(): array
    {
        return [
            'one file'                        => [['CERT' => 'both.pem']],
            'hosted'                          => [['CERT' => 'both.pem', 'BOX' => 'kv62bqf']],
            'a certificate anyone reads, the key apart' => [['CERT' => 'cert.pem', 'KEY' => 'key.pem']],
            'encrypted key'                   => [['CERT' => 'cert.pem', 'KEY' => 'encrypted.key', 'PASSPHRASE' => self::PASSPHRASE]],
            'in the directory of AA'          => [['CERT' => 'both.pem'], 'inside'],
        ];
    }

    /**
     * What PHP finds in the files: one finding per thing to fix, the files named - never the key or
     * the passphrase
     * @param array<string, string> $set  CERT, KEY, PASSPHRASE: files outside the directory of AA
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
            'no such file'           => [['CERT' => 'none.pem'], ['certificate-file:production:NSZM'], 'AA_APIKEY_ISDS_NSZM_CERT'],
            'no such key file'       => [['CERT' => 'cert.pem', 'KEY' => 'none.key'], ['certificate-file:production:NSZM'], 'none.key'],
            'a key others may read in the certificate file' => [['CERT' => 'other.pem', 'KEY' => 'key.pem'], ['certificate-access:production:NSZM'], 'AA_APIKEY_ISDS_NSZM_CERT'],
            'a key others may read apart' => [['CERT' => 'cert.pem', 'KEY' => 'other.pem'], ['certificate-access:production:NSZM'], 'AA_APIKEY_ISDS_NSZM_KEY'],
            'a key others may read, no other file' => [['CERT' => 'other.pem', 'KEY' => 'none.key'], ['certificate-access:production:NSZM', 'certificate-file:production:NSZM'], 'other.pem'],
            'not a certificate'      => [['CERT' => 'garbage.pem', 'KEY' => 'key.pem'], $invalid, 'AA_APIKEY_ISDS_NSZM_CERT names a file with no PEM certificate'],
            'no key'                 => [['CERT' => 'cert.pem'], $invalid, 'The private key in AA_APIKEY_ISDS_NSZM_CERT does not open'],
            'another key'            => [['CERT' => 'cert.pem', 'KEY' => 'another.key'], $invalid, 'does not belong to the certificate'],
            'a weak key'             => [['CERT' => 'weak.pem'], $invalid, 'The private key is shorter than 2048 bits'],
            'a huge file'            => [['CERT' => 'huge.pem'], $invalid, 'AA_APIKEY_ISDS_NSZM_CERT names a file with no PEM certificate'],
            'a key beyond 1 MiB'     => [['CERT' => 'late.pem'], $invalid, 'AA_APIKEY_ISDS_NSZM_CERT names a file with no PEM certificate'],
            'no passphrase'          => [['CERT' => 'cert.pem', 'KEY' => 'encrypted.key'], $invalid, 'AA_APIKEY_ISDS_NSZM_PASSPHRASE does not fit'],
            'another passphrase'     => [['CERT' => 'cert.pem', 'KEY' => 'encrypted.key', 'PASSPHRASE' => 'Another-pass-phrase'], $invalid, 'AA_APIKEY_ISDS_NSZM_PASSPHRASE does not fit'],
            'damaged constant'       => [['CERT' => "both.pem\n"], ['account:production:NSZM'], 'AA_APIKEY_ISDS_NSZM_CERT holds a line break'],
        ];
    }

    /**
     * Inside the directory of AA the web server may serve a key the group may read; outside, only other
     * users must not read it. Inside is decided on the path as written and as resolved, with the links
     * the web server follows.
     * @param string $cert  the file, under $root
     * @param string $base  AA_BASE_PATH under $root; '' = not defined
     * @param string $title '' = no finding
     */
    #[DataProvider('keyAccess')]
    public function test_findings_privateKeyAccess(string $cert, string $base, string $title, string $mode = ''): void
    {
        $constants = ['AA_APIKEY_ISDS_NSZM_CERT' => self::$root . "/$cert", 'AA_BASE_PATH' => self::$root . "/$base"] + self::certificateAccount([]);
        if ($base === '') {
            unset($constants['AA_BASE_PATH']);
        }

        $findings = self::check($constants)->findings();

        if ($title === '') {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        self::assertSame(['certificate-access:production:NSZM'], self::keys($findings));
        self::assertSame("$title AA_APIKEY_ISDS_NSZM", $findings[0]->title);
        self::assertSame([['Constant' => 'AA_APIKEY_ISDS_NSZM_CERT', 'File' => self::$root . "/$cert", 'Permissions' => $mode]], $findings[0]->evidence);
    }

    public static function keyAccess(): array
    {
        $served = 'The web server may serve the private key of ISDS account';
        $others = 'Other users of the server may read the private key of ISDS account';
        return [
            'outside, the owner only'         => ['aa-keys/both.pem', 'aa', ''],
            'beside, the group'               => ['aa-keys/group.pem', 'aa', ''],
            'outside, others'                 => ['aa-keys/other.pem', 'aa', $others, '0604'],
            'inside, the owner only'          => ['aa/both.pem', 'aa', ''],
            'inside, the group'               => ['aa/group.pem', 'aa', $served, '0640'],
            'inside, others'                  => ['aa/other.pem', 'aa', $served, '0604'],
            'a link inside to a key outside'  => ['aa/link.pem', 'aa', $served, '0640'],
            'a link outside to a key inside'  => ['aa-keys/in.pem', 'aa', $served, '0640'],
            'a link inside, by a linked path' => ['alias/link.pem', 'aa', $served, '0640'],
            'up and out by ..'                => ['aa/../aa-keys/group.pem', 'aa', ''],
            'a linked base'                   => ['aa/group.pem', 'aa-link', $served, '0640'],
            'a link out of the linked base'   => ['aa-link/keys/group.pem', 'aa-link', $served, '0640'],
            'the same, the base with ..'      => ['aa-link/keys/group.pem', 'aa-keys/../aa-link', $served, '0640'],
            'no base, the group'              => ['aa-keys/group.pem', '', $served, '0640'],
        ];
    }

    // PHP inside the web server reads the key for it: inside the directory of AA no mode helps
    #[DataProvider('webServerModule')]
    public function test_findings_keyOfPhpInsideTheWebServer(string $where, bool $served): void
    {
        $check       = self::check(self::certificateAccount(['CERT' => 'both.pem'], $where));
        $check->sapi = 'apache2handler';

        $findings = $check->findings();

        if (!$served) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        self::assertSame(['certificate-access:production:NSZM'], self::keys($findings));
        self::assertSame('The web server may serve the private key of ISDS account AA_APIKEY_ISDS_NSZM', $findings[0]->title);
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
            $findings = self::check(['AA_APIKEY_ISDS_NSZM_CERT' => "$dir/fifo.pem"] + self::certificateAccount([]))->findings();
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

        $findings = self::check(self::certificateAccount(['CERT' => 'locked.pem']))->findings();

        self::assertSame(['certificate-file:production:NSZM'], self::keys($findings));
        self::assertStringContainsString('open_basedir', $findings[0]->advice);
    }

    public function test_findings_certificateNotValidYet(): void
    {
        $check      = self::check(self::certificateAccount(['CERT' => 'both.pem']));
        $check->now = self::$from - 1;

        $findings = $check->findings();

        self::assertSame(['certificate-invalid:production:NSZM'], self::keys($findings));
        self::assertSame('The certificate is valid from ' . date('Y-m-d H:i', self::$from) . ' only', $findings[0]->evidence[0]['Problem']);
    }

    // ISDS wants a new certificate 30 days ahead
    #[DataProvider('expiries')]
    public function test_findings_certificateExpiry(int $left, ?string $severity, string $title): void
    {
        $check      = self::check(self::certificateAccount(['CERT' => 'both.pem']));
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
        $expires = 'The certificate of ISDS account AA_APIKEY_ISDS_NSZM expires on %s';
        return [
            '30 days'          => [30 * self::DAY, null, ''],
            'under 30 days'    => [30 * self::DAY - 1, Finding::WARNING, $expires],
            '7 days'           => [7 * self::DAY, Finding::WARNING, $expires],
            'under 7 days'     => [7 * self::DAY - 1, Finding::ERROR, $expires],
            'expired'          => [-1, Finding::ERROR, 'The certificate of ISDS account AA_APIKEY_ISDS_NSZM expired on %s'],
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
        self::assertSame('The slices of ISDS account AA_APIKEY_ISDSTEST_NSZM are not ready for sending', $finding->title);
        self::assertSame(['slices:test:NSZM', 2, 'Rozesílky <b>NSZM</b>'], [$finding->key, $finding->count, $finding->place]);
        self::assertSame(safe('Slice "Rozesílky <b>NSZM</b>" has no reading password - fill in Password for Reading in the settings of the slice'), $finding->evidence[0]['Problem']);
        self::assertSame('Fix what the evidence names before sending from these slices.', $finding->advice);
        self::assertSame(['Slice settings: Rozesílky <b>NSZM</b>', 'Email Notification: Rozesílky <b>NSZM</b>'], array_keys($finding->links));
        self::assertStringContainsString(SliceRules::SLICE_PAGE . '?change_id=' . self::CAMPAIGN, $finding->links['Slice settings: Rozesílky <b>NSZM</b>']);
        self::assertStringContainsString(SliceRules::NOTIFY_PAGE . '?change_id=' . self::CAMPAIGN, $finding->links['Email Notification: Rozesílky <b>NSZM</b>']);
    }

    public function test_findings_openSliceOfAnUnusableAccount(): void
    {
        $check = self::check(self::account(login: null));
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, ['permit_anonymous_post' => '1']);

        $findings = $check->findings();

        self::assertSame([Finding::ERROR, Finding::ERROR], self::severities($findings));
        self::assertSame(['account:test:NSZM', 'slices:test:NSZM'], self::keys($findings));
        self::assertStringContainsString('Allow anonymous posting of items: Not allowed', $findings[1]->evidence[0]['Problem']);
    }

    // a slice's own problem once, a site's for each site; two sites of one name told apart
    public function test_findings_twoSitesOfOneName(): void
    {
        $check = self::check(self::account(site: self::SITE . ',' . self::SITE2));
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

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, string> $set   CERT and KEY name files in $where, PASSPHRASE and BOX are as they are
     * @param string                $where 'inside' or 'outside' the directory of AA
     * @return array<string, string> a production account with a certificate
     */
    private static function certificateAccount(array $set, string $where = 'outside'): array
    {
        $constants = ['AA_BASE_PATH' => self::$inside, 'AA_APIKEY_ISDS_NSZM_SITE' => self::SITE, 'AA_APIKEY_ISDS_NSZM_SLICES' => self::CAMPAIGN . ',' . self::MESSAGE];
        foreach ($set as $kind => $value) {
            $constants["AA_APIKEY_ISDS_NSZM_$kind"] = in_array($kind, ['CERT', 'KEY'], true) ? self::dir($where) . "/$value" : $value;
        }

        return $constants;
    }

    private static function dir(string $where): string
    {
        return ($where === 'inside') ? self::$inside : self::$outside;
    }

    /** @param array<string, string> $constants */
    private static function check(array $constants): ScriptedIsdsAccountsCheck
    {
        $check            = new ScriptedIsdsAccountsCheck(FakeSliceRules::ready(self::SITE, self::CAMPAIGN, self::MESSAGE));
        $check->constants = $constants;
        $check->now       = self::$from + self::DAY;

        return $check;
    }

    /** @return array<string, string> the constants of an account; null leaves one out */
    private static function account(?string $site = self::SITE, ?string $login = self::LOGIN, ?string $password = self::PASSWORD, string $environment = 'ISDSTEST'): array
    {
        return array_filter([
            "AA_APIKEY_{$environment}_NSZM_SITE"     => $site,
            "AA_APIKEY_{$environment}_NSZM_SLICES"   => self::CAMPAIGN . ',' . self::MESSAGE,
            "AA_APIKEY_{$environment}_NSZM_LOGIN"    => $login,
            "AA_APIKEY_{$environment}_NSZM_PASSWORD" => $password,
        ], static fn(?string $v): bool => !is_null($v));
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
