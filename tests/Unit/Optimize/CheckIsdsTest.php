<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Http\ClientInterface;
use AA\Http\Response;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\Client;
use AA\Isds\LoginLatch;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsds;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Tests\Unit\Fixtures\Certificates;
use Tests\Unit\Fixtures\FakeHttp;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Optimize\Fixtures\ScriptedIsdsCheck;

require_once __DIR__ . '/../Isds/fixtures/Sending.php';

/**
 * CheckIsds: logs in with each usable test account - the box, the rights of the user, the expiry of
 * the password; a refused login is an error that names the settings, never the login or the
 * password, and the daily run never tries it again. ISDS answers from the fixtures of the client's
 * tests; the refusals live in a MemoryLatch.
 */
final class CheckIsdsTest extends TestCase
{
    private const SITE     = '51de0000000000000000000000000001';
    private const CAMPAIGN = 'cafe0000000000000000000000000001';
    private const MESSAGE  = 'beef0000000000000000000000000002';

    /** two more accounts, each with slices of its own */
    private const SITE2   = '51de0000000000000000000000000002';
    private const SLICES2 = ['cafe0000000000000000000000000003', 'beef0000000000000000000000000004'];
    private const SITE3   = '51de0000000000000000000000000005';
    private const SLICES3 = ['cafe0000000000000000000000000006', 'beef0000000000000000000000000007'];

    private const LOGIN    = 'nszm01login';
    private const PASSWORD = 'Test-Pa55-never-shown';

    /** the expiry in password-info.xml: 2011-07-06 13:33:39 CEST */
    private const EXPIRY = 1309952019;

    private const DAY = 86400;

    private const PASSPHRASE = 'Pass-phrase-never-shown';

    /** the certificates of this run, made in setUpBeforeClass() */
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
        require_once __DIR__ . '/../fixtures/Certificates.php';
        require_once __DIR__ . '/../Isds/fixtures/MemoryLatch.php';
        require_once __DIR__ . '/fixtures/ScriptedIsdsCheck.php';
        self::$dir           = Certificates::directory();
        [$certificate, $key] = Certificates::make();
        Certificates::file(self::$dir, 'both.pem', $certificate . $key);
        Certificates::file(self::$dir, 'cert.pem', $certificate);
        Certificates::file(self::$dir, 'encrypted.key', Certificates::encrypted($key, self::PASSPHRASE));
    }

    public static function tearDownAfterClass(): void
    {
        Certificates::remove(self::$dir);
    }

    public function test_types_extendedOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_EXTENDED], CheckIsds::types());
    }

    /** @return array<string, array{array<string, array<string, mixed>>}> */
    public static function nothingToTry(): array
    {
        return [
            'no account'     => [[]],
            'half set'       => [self::account(password: null)],
            // production lets an application in with a certificate only; CheckIsdsAccounts warns
            'production'     => [['NSZM' => ['environment' => 'production', 'auth' => 'cert', 'sites' => [self::SITE], 'slices' => [self::CAMPAIGN, self::MESSAGE]]]],
        ];
    }

    /** @param array<string, array<string, mixed>> $accounts */
    #[DataProvider('nothingToTry')]
    public function test_findings_nothingToTryIsOk(array $accounts): void
    {
        $check = self::check($accounts);

        $findings = $check->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('No usable ISDS account to log in with', $findings[0]->title);
        self::assertSame([], self::operations($check));
    }

    /** a file AA cannot use has no account read - nothing tried, but no OK */
    #[DataProvider('fileAaCannotUse')]
    public function test_findings_fileAaCannotUseIsNoOk(bool $broken): void
    {
        $check       = self::check([]);
        $check->conf = Sending::fileConf($broken);

        $findings = $check->findings();

        self::assertSame([Finding::INFO], self::severities($findings));
        self::assertSame(['file', 'ISDS accounts not checked: AA cannot use the accounts of misc/datovka/datovka_conf.php'], [$findings[0]->key, $findings[0]->title]);
        self::assertSame([], self::operations($check));
    }

    /** @return array<string, array{bool}> */
    public static function fileAaCannotUse(): array
    {
        return ['unreadable' => [false], 'broken' => [true]];
    }

    public function test_findings_loginOkNamesTheBox(): void
    {
        $check = self::check(self::account(), self::answers('owner-info.xml', 'user-info.xml', 'password-info-nil.xml'));

        $findings = $check->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('ISDS accounts log in to their data boxes: NSZM (test): kv62bqf', $findings[0]->title);
        self::assertSame(['GetOwnerInfoFromLogin2', 'GetUserInfoFromLogin2', 'GetPasswordInfo'], self::operations($check));
    }

    /**
     * One tree serves several databases, each with its own log of refused logins: a database logs in
     * only with the accounts of its sites - a wrong password would be tried from each of them.
     * @param list<string> $here       the sites of this database
     * @param list<string> $operations
     */
    #[DataProvider('databases')]
    public function test_findings_logsInForSitesOfThisDatabaseOnly(array $accounts, array $here, string $title, array $operations): void
    {
        $check       = self::check($accounts, self::answers('owner-info.xml', 'user-info.xml', 'password-info-nil.xml'));
        $check->here = $here;

        $findings = $check->findings();

        self::assertSame([$title], array_map(static fn(Finding $f): string => $f->title, $findings));
        self::assertSame($operations, self::operations($check));
    }

    public static function databases(): array
    {
        $login = ['GetOwnerInfoFromLogin2', 'GetUserInfoFromLogin2', 'GetPasswordInfo'];
        return [
            'an account of this database'     => [self::account(), [self::SITE], 'ISDS accounts log in to their data boxes: NSZM (test): kv62bqf', $login],
            'an account of another'           => [self::account(), [self::SITE2, self::CAMPAIGN], 'No usable ISDS account to log in with', []],
            'two sites, one of this database' => [['NSZM' => Sending::account(self::SITE, [self::CAMPAIGN, self::MESSAGE], ['sites' => [self::SITE2, self::SITE]])], [self::SITE], 'ISDS accounts log in to their data boxes: NSZM (test): kv62bqf', $login],
            'one account of each'             => [self::account() + self::account('ECN', site: self::SITE2, slices: self::SLICES2), [self::SITE2], 'ISDS accounts log in to their data boxes: ECN (test): kv62bqf', $login],
        ];
    }

    public function test_findings_loginRefusedNamesTheSettingsOnly(): void
    {
        $check = self::check(self::account(), self::refusing());

        $findings = $check->findings();

        self::assertSame([Finding::ERROR], self::severities($findings));
        $finding = $findings[0];
        self::assertSame('ISDS refused the login of account NSZM (test)', $finding->title);
        self::assertSame('auth:test:NSZM', $finding->key);
        self::assertStringContainsString('Login blocked until', $finding->evidence[0]['ISDS']);
        self::assertStringContainsString("Check DATOVKA_CONF['accounts']['NSZM']['login'] and DATOVKA_CONF['accounts']['NSZM']['password'] in misc/datovka/datovka_conf.php", $finding->advice);
        self::assertStringContainsString('Run extended checks or Recheck here tries it once more.', $finding->advice);
        self::assertSame(['GetOwnerInfoFromLogin2'], self::operations($check), 'nothing more after a refused login');
        self::assertNoSecret($findings);
    }

    // ISDS blocks a login after failures: the daily run never asks it again
    public function test_findings_unattendedRunNeverRetries(): void
    {
        $check = self::check(self::account(), self::refusing());
        $check->findings();
        $check->latch = $check->latch->nextRun(self::DAY);
        $check->http  = self::answers('owner-info.xml');

        $findings = $check->findings();

        self::assertSame(['auth:test:NSZM'], self::keys($findings));
        self::assertStringStartsWith('ISDS refused this login at ', $findings[0]->evidence[0]['ISDS']);
        self::assertSame([], self::operations($check), 'no request');
    }

    // a person checks again: one try, not one per account of that login
    public function test_findings_explicitRunTriesOnce(): void
    {
        $accounts = self::account() + self::account('ECN', site: self::SITE2, slices: self::SLICES2);
        $check    = self::check($accounts, self::refusing());
        $check->findings();
        self::assertCount(1, self::operations($check), 'the second account of the login is not tried either');

        $check->latch      = $check->latch->nextRun();
        $check->http       = self::refusing();
        $check->unattended = false;
        $findings          = $check->findings();

        self::assertSame(['auth:test:ECN', 'auth:test:NSZM'], self::keys($findings));
        self::assertCount(1, self::operations($check));
        self::assertSame([LoginLatch::REFUSED, LoginLatch::REFUSED], array_column($check->latch->rows, 'params'));

        $check->latch = $check->latch->nextRun();
        $check->http  = self::answers('owner-info.xml', 'user-info.xml', 'password-info-nil.xml', 'owner-info.xml', 'user-info.xml', 'password-info-nil.xml');
        self::assertSame([Finding::OK], self::severities($check->findings()), 'fixed in the portal meanwhile');
        self::assertSame([LoginLatch::REFUSED, LoginLatch::REFUSED, LoginLatch::FORGOTTEN], array_column($check->latch->rows, 'params'));
    }

    // the server's address, not the logins: the other accounts would only extend the block
    public function test_findings_blockedAddressStopsTheEnvironment(): void
    {
        $check = self::check(self::account() + self::account('ECN', site: self::SITE2, slices: self::SLICES2), self::blocking());

        $findings = $check->findings();

        self::assertSame(['blocked:test', 'not-tried:test'], self::keys($findings));
        self::assertSame([Finding::ERROR, Finding::INFO], self::severities($findings));
        self::assertSame('ISDS blocked logins from the address of this server', $findings[0]->title);
        self::assertStringContainsString('Wait: each attempt extends the block. AA tries once more by itself 24 hours later', $findings[0]->advice);
        self::assertStringNotContainsString('DATOVKA_CONF', $findings[0]->advice);
        self::assertSame('ISDS accounts not tried, as ISDS blocked the address of this server: NSZM (test)', $findings[1]->title);
        self::assertSame('Wait - each attempt from this server extends the block.', $findings[1]->advice);
        self::assertCount(1, self::operations($check));
        self::assertSame(['blocked:test'], array_column($check->latch->rows, 'selector'), 'no login latched');
        self::assertNoSecret($findings);

        $check->latch = $check->latch->nextRun();
        $check->http  = self::blocking();
        self::assertSame(['blocked:test', 'not-tried:test'], self::keys($check->findings()));
        self::assertSame([], self::operations($check), 'the daily run waits');

        $check->unattended = false;
        self::assertSame(['blocked:test', 'not-tried:test'], self::keys($check->findings()));
        self::assertCount(1, self::operations($check), 'a person tries once');
    }

    // an answer AA cannot read is that question's problem: ISDS answers, the next question is asked
    public function test_findings_unreadableAnswerStopsNothing(): void
    {
        $accounts  = self::account('ECN', site: self::SITE2, slices: self::SLICES2) + self::account();
        $badRights = str_replace('<p:userPrivils>28</p:userPrivils>', '<p:userPrivils>x</p:userPrivils>', self::fixture('user-info.xml'));
        $check     = self::check($accounts, new FakeHttp(array_map(static fn(string $body): Response => new Response(200, $body), [
            '<html>a gateway</html>', self::fixture('user-info.xml'), self::fixture('password-info-nil.xml'),
            self::fixture('owner-info.xml'), $badRights, self::fixture('password-info-nil.xml'),
        ])));

        $findings = $check->findings();

        self::assertSame(['unreadable:test:ECN', 'rights-unknown:test:NSZM'], self::keys($findings));
        self::assertSame('ISDS gave account ECN (test) an answer AA cannot read', $findings[0]->title);
        self::assertCount(6, self::operations($check));
    }

    public function test_findings_refusedAccount(): void
    {
        $refused = strtr(self::fixture('owner-info.xml'), ['0000' => '2011', 'Provedeno úspěšně.' => 'Schránka je znepřístupněna']);
        $check   = self::check(self::account(), new FakeHttp([new Response(200, $refused)]));

        $findings = $check->findings();

        self::assertSame(['refused:test:NSZM'], self::keys($findings));
        self::assertSame([Finding::ERROR], self::severities($findings));
        self::assertSame('Schránka je znepřístupněna', $findings[0]->evidence[0]['ISDS']);
    }

    // one host per environment: the next account would wait out the limit too
    public function test_findings_unavailableStopsTheRun(): void
    {
        $check = self::check(self::account() + self::account('ECN', site: self::SITE2, slices: self::SLICES2), new FakeHttp([new Response(503, 'Service Unavailable')]));

        $findings = $check->findings();

        self::assertSame(['busy:test:ECN', 'not-tried:test:ws1.datovka-test.gov.cz'], self::keys($findings));
        self::assertSame([Finding::WARNING, Finding::INFO], self::severities($findings));
        self::assertSame('ISDS asked account ECN (test) to come back later', $findings[0]->title);
        self::assertSame('ISDS accounts not tried, as ISDS did not answer: NSZM (test)', $findings[1]->title);
        self::assertSame('Check again later.', $findings[1]->advice);
        self::assertCount(1, self::operations($check));
    }

    public function test_findings_noAnswerNamesTheHost(): void
    {
        $http = new class implements ClientInterface {
            public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] ?string $body = null, #[\SensitiveParameter] array $headers = []): Response
            {
                throw new HttpTransportException('No answer', 0, new TimeoutException('Idle timeout reached'));
            }
        };

        $findings = self::check(self::account(), $http)->findings();

        self::assertSame(['transport:test:NSZM'], self::keys($findings));
        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertSame('ISDS gave account NSZM (test) no answer', $findings[0]->title);
        self::assertStringContainsString('ws1.datovka-test.gov.cz', $findings[0]->advice);
        self::assertNoSecret($findings);
    }

    /** @return array<string, array{int, list<string>, list<string>}> */
    public static function rights(): array
    {
        return [
            'what AA needs'            => [4 + 8 + 16, [], []],
            'and the dropped vault 64' => [4 + 8 + 16 + 64, [], []],
            'no sending'               => [8 + 16, ['rights:test:NSZM'], [Finding::ERROR]],
            'no lists'                 => [4 + 16, ['rights:test:NSZM'], [Finding::ERROR]],
            'no search'                => [4 + 8, ['rights-optional:test:NSZM'], [Finding::WARNING]],
            'none of them'             => [0, ['rights:test:NSZM', 'rights-optional:test:NSZM'], [Finding::ERROR, Finding::WARNING]],
            'reads received'           => [4 + 8 + 16 + 1, ['unwanted:test:NSZM'], [Finding::WARNING]],
            'reads to own hands'       => [4 + 8 + 16 + 2, ['unwanted:test:NSZM'], [Finding::WARNING]],
            'manages the box'          => [4 + 8 + 16 + 32, ['unwanted:test:NSZM'], [Finding::WARNING]],
            'erases the vault'         => [4 + 8 + 16 + 128, ['unwanted:test:NSZM'], [Finding::WARNING]],
        ];
    }

    /**
     * @param list<string> $keys
     * @param list<string> $severities
     */
    #[DataProvider('rights')]
    public function test_findings_rights(int $privileges, array $keys, array $severities): void
    {
        $check = self::check(self::account(), self::answers('owner-info.xml', self::user($privileges), 'password-info-nil.xml'));

        $findings = $check->findings();

        self::assertSame($severities ?: [Finding::OK], self::severities($findings));
        if ($keys) {
            self::assertSame($keys, self::keys($findings));
        }
    }

    public function test_findings_rightsNamedByWhatTheyAreFor(): void
    {
        $check = self::check(self::account(), self::answers('owner-info.xml', self::user(1 + 2 + 32), 'password-info-nil.xml'));

        $findings = $check->findings();

        self::assertSame('The user of ISDS account NSZM (test) lacks the rights 4 (send messages), 8 (lists of messages and delivery info)', $findings[0]->title);
        self::assertStringContainsString('without them ISDS refuses CreateMessage, GetMessageStateChanges, GetListOfSentMessages', $findings[0]->advice);
        self::assertSame('The user of ISDS account NSZM (test) lacks the rights 16 (search for data boxes)', $findings[1]->title);
        self::assertStringContainsString('without them ISDS refuses CheckDataBox', $findings[1]->advice);
        self::assertSame('The user of ISDS account NSZM (test) has rights AA must not have: 1 (read received messages), 2 (read received messages to own hands), 32 (manage the data box)', $findings[2]->title);
        self::assertStringContainsString('only the rights 4 (send messages), 8 (lists of messages and delivery info), 16 (search for data boxes)', $findings[2]->advice);
        self::assertSame([['Rights' => '35', 'User type' => 'ENTRUSTED_USER']], $findings[0]->evidence);
    }

    // nil (dbTypes.xsd allows it): not known, which is no "lacks 4, 8, 16"
    public function test_findings_rightsUnknown(): void
    {
        $user  = str_replace('<p:userPrivils>28</p:userPrivils>', '<p:userPrivils xsi:nil="true"/>', self::fixture('user-info.xml'));
        $check = self::check(self::account(), new FakeHttp([new Response(200, self::fixture('owner-info.xml')), new Response(200, $user), new Response(200, self::fixture('password-info-nil.xml'))]));

        $findings = $check->findings();

        self::assertSame(['rights-unknown:test:NSZM'], self::keys($findings));
        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertSame('unknown', $findings[0]->evidence[0]['Rights']);
    }

    public function test_findings_passwordExpiresSoon(): void
    {
        $check = self::check(self::account(), self::answers('owner-info.xml', 'user-info.xml', 'password-info.xml'));
        $check->now = self::EXPIRY - 21 * self::DAY;
        self::assertSame([Finding::OK], self::severities($check->findings()), '21 days left');

        $check->http = self::answers('owner-info.xml', 'user-info.xml', 'password-info.xml');
        $check->now  = self::EXPIRY - 21 * self::DAY + 1;
        $findings    = $check->findings();

        self::assertSame(['expiry:test:NSZM'], self::keys($findings));
        self::assertSame('The password of ISDS account NSZM (test) expires on ' . date('Y-m-d', self::EXPIRY), $findings[0]->title);
        self::assertStringContainsString("DATOVKA_CONF['accounts']['NSZM']['password']", $findings[0]->advice);
        self::assertNoSecret($findings);
    }

    // each question its own try: an unreadable answer to one leaves the other
    public function test_findings_questionsAskedEach(): void
    {
        $refusedExpiry = strtr(self::fixture('password-info.xml'), ['0000' => '2011']);
        $check         = self::check(self::account(), new FakeHttp([new Response(200, self::fixture('owner-info.xml')), new Response(200, self::user(28 + 1)), new Response(200, $refusedExpiry)]));

        self::assertSame(['unwanted:test:NSZM', 'expiry-unknown:test:NSZM'], self::keys($check->findings()));

        $refusedUser = strtr(self::fixture('user-info.xml'), ['0000' => '2011']);
        $check->http = new FakeHttp([new Response(200, self::fixture('owner-info.xml')), new Response(200, $refusedUser), new Response(200, self::fixture('password-info.xml'))]);
        $check->now  = self::EXPIRY - self::DAY;
        self::assertSame(['rights-unknown:test:NSZM', 'expiry:test:NSZM'], self::keys($check->findings()));
    }

    // no answer to a question: the rest would wait out the limit too
    public function test_findings_questionWithoutAnswerStops(): void
    {
        $check = self::check(self::account(), new FakeHttp([new Response(200, self::fixture('owner-info.xml')), new Response(503, 'Service Unavailable')]));

        $findings = $check->findings();

        self::assertSame(['busy:test:NSZM'], self::keys($findings));
        self::assertSame(['GetOwnerInfoFromLogin2', 'GetUserInfoFromLogin2'], self::operations($check));
    }

    /**
     * A certificate has the rights 159 and no password: ISDS answers 2102 to both questions, and the
     * list of operations of the client keeps it from the received messages - no warning about rights
     */
    #[DataProvider('certificateLogins')]
    public function test_findings_certificateLoginAsksTheBoxOnly(string $box): void
    {
        $check = self::check(self::certificateAccount($box), self::answers('owner-info.xml', 'user-info.xml', 'password-info.xml'));

        $findings = $check->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('ISDS accounts log in to their data boxes: NSZM (production): kv62bqf', $findings[0]->title);
        self::assertSame(['GetOwnerInfoFromLogin2'], self::operations($check));
    }

    public static function certificateLogins(): array
    {
        return ['system certificate' => [''], 'hosted filing service' => ['kv62bqf']];
    }

    #[DataProvider('certificateLogins')]
    public function test_findings_refusedCertificateAdvice(string $box): void
    {
        $finding = self::check(self::certificateAccount($box), self::refusing())->findings()[0];

        self::assertSame('auth:production:NSZM', $finding->key);
        self::assertStringContainsString("certificate DATOVKA_CONF['accounts']['NSZM']['cert'] names", $finding->advice);
        self::assertStringNotContainsString("['password']", $finding->advice);
        if ($box !== '') {
            self::assertStringContainsString("the data box $box allows the hosting service", $finding->advice);
        }
    }

    /**
     * Nothing is sent without a certificate that works, and CheckIsdsAccounts names the files: here it
     * is a note that stops no other account
     * @param array<string, string> $files
     */
    #[DataProvider('unusableCertificates')]
    public function test_findings_unusableCertificateNotTried(array $files, string $problem): void
    {
        $check = self::check(self::certificateAccount(name: 'ECN', files: $files) + self::certificateAccount(site: self::SITE2, slices: self::SLICES2), self::answers('owner-info.xml'));

        $findings = $check->findings();

        self::assertSame(['certificate-unusable:production:ECN'], self::keys($findings));
        self::assertSame([Finding::INFO], self::severities($findings));
        self::assertStringStartsWith($problem, $findings[0]->evidence[0]['Problem']);
        self::assertSame(['GetOwnerInfoFromLogin2'], self::operations($check), 'the other account is tried');
        self::assertNoSecret($findings, [self::PASSPHRASE, self::$dir]);
    }

    public static function unusableCertificates(): array
    {
        return [
            'no file'            => [['cert' => 'none.pem'], 'PHP cannot read the certificate'],
            'another passphrase' => [['cert' => 'cert.pem', 'key' => 'encrypted.key', 'passphrase' => 'Another-pass-phrase'], 'The private key of the ISDS account does not open'],
        ];
    }

    /**
     * What only the TLS library of this server refuses - a key under its policy, a weak certificate in
     * the chain - no other check sees: an error with the reason of OpenSSL, never the path
     * @param array<string, string> $evidence
     */
    #[DataProvider('refusedByTls')]
    public function test_findings_certificateRefusedByTls(string $error, array $evidence): void
    {
        $http = new class ($error) implements ClientInterface {
            public function __construct(private readonly string $error) {}

            public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] ?string $body = null, #[\SensitiveParameter] array $headers = []): Response
            {
                throw new HttpTransportException($this->error);
            }
        };

        $findings = self::check(self::certificateAccount(), $http)->findings();

        self::assertSame(['certificate-refused:production:NSZM'], self::keys($findings));
        self::assertSame([Finding::ERROR], self::severities($findings));
        self::assertSame('The TLS library of this server refuses the certificate of ISDS account NSZM (production)', $findings[0]->title);
        self::assertSame([$evidence + ['Problem' => 'This server could not use the certificate of the ISDS account for the connection - nothing was sent; the State Check tells more']], $findings[0]->evidence);
        self::assertNoSecret($findings, ['/etc/aa-isds']);
    }

    public static function refusedByTls(): array
    {
        return [
            'curl'        => ['Could not load PEM client certificate from /etc/aa-isds/nszm.pem, OpenSSL error error:0A00018F:SSL routines::ee key too small, (no key found, wrong passphrase, or wrong file format?)', ['Reason of OpenSSL' => 'ee key too small']],
            'php streams' => ["fopen(): Unable to set local cert chain file `/etc/aa-isds/nszm.pem'; Check that your cafile/capath settings include details of your certificate and its issuer", []],
            // the last code of OpenSSL, not a path that looks like one
            'a code in the path'     => ['Could not load PEM client certificate from /etc/aa-isds/error:0A00018F:SSL routines::nszm.pem, OpenSSL error error:0A00018F:SSL routines::ee key too small, (no key found, wrong passphrase, or wrong file format?)', ['Reason of OpenSSL' => 'ee key too small']],
            'routines:: in a path cut by curl' => ['Could not load PEM client certificate from /etc/aa-isds/routines::' . str_repeat('d/', 110) . ' for "https://ws1c.datovka.gov.cz/cert/DS/DsManage".', []],
            // curl cuts its text at 256 bytes, then Symfony adds the URL
            'cut by curl' => ['Could not load PEM client certificate from /etc/aa-isds/' . str_repeat('d/', 70) . 'nszm.pem, OpenSSL error error:0A00018F:SSL routines::ca key too smal for "https://ws1c.datovka.gov.cz/cert/DS/DsManage".', ['Reason of OpenSSL' => 'ca key too smal']],
            'cut right after the code' => ['Could not load PEM client certificate from /etc/aa-isds/' . str_repeat('d/', 85) . 'nszm.pem, OpenSSL error error:0A00018F:SSL routines:: for "https://ws1c.datovka.gov.cz/cert/DS/DsManage".', []],
            'markup in the reason' => ['Could not load PEM client certificate, OpenSSL error error:0A00018F:SSL routines::key <b>weak</b>', ['Reason of OpenSSL' => 'key &lt;b&gt;weak&lt;/b&gt;']],
        ];
    }

    // a name and a password log in on ws1, a certificate on ws1c: one host without an answer stops only its accounts
    public function test_findings_noAnswerStopsTheHost(): void
    {
        $accounts = self::account('ECN', site: self::SITE2, slices: self::SLICES2)
            + self::certificateAccount(name: 'MPSV', environment: 'test', site: self::SITE3, slices: self::SLICES3)
            + self::account();
        $check = self::check($accounts, new FakeHttp([new Response(503, 'Service Unavailable'), new Response(200, self::fixture('owner-info.xml'))]));

        $findings = $check->findings();

        self::assertSame(['busy:test:ECN', 'not-tried:test:ws1.datovka-test.gov.cz'], self::keys($findings));
        self::assertSame('ISDS accounts not tried, as ISDS did not answer: NSZM (test)', $findings[1]->title);
        self::assertSame(['GetOwnerInfoFromLogin2', 'GetOwnerInfoFromLogin2'], self::operations($check), 'the certificate is tried');
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param string                $box    the data box of a hosted filing service; '' = a system certificate
     * @param array<string, string> $files  cert and key name files of this run; passphrase as it is
     * @param list<string>          $slices
     * @return array<string, array<string, mixed>> an account with a certificate, as DATOVKA_CONF['accounts']
     */
    private static function certificateAccount(string $box = '', string $name = 'NSZM', array $files = ['cert' => 'both.pem'], string $environment = 'production', string $site = self::SITE, array $slices = [self::CAMPAIGN, self::MESSAGE]): array
    {
        $account = ['environment' => $environment, 'auth' => ($box === '') ? 'cert' : 'hosted', 'sites' => [$site], 'slices' => $slices];
        foreach ($files + ['box' => $box] as $key => $value) {
            if ($value !== '') {
                $account[$key] = in_array($key, ['cert', 'key'], true) ? self::$dir . "/$value" : $value;
            }
        }

        return [$name => $account];
    }

    /** @param array<string, array<string, mixed>> $accounts as DATOVKA_CONF['accounts'] */
    private static function check(array $accounts, ?ClientInterface $http = null): ScriptedIsdsCheck
    {
        $check           = new ScriptedIsdsCheck();
        $check->accounts = $accounts;
        if ($http) {
            $check->http = $http;
        }

        return $check;
    }

    /**
     * @param list<string> $slices
     * @return array<string, array<string, mixed>> a test account, as DATOVKA_CONF['accounts']; a null password left out
     */
    private static function account(string $name = 'NSZM', ?string $password = self::PASSWORD, string $site = self::SITE, array $slices = [self::CAMPAIGN, self::MESSAGE]): array
    {
        return [$name => Sending::account($site, $slices, ['password' => $password])];
    }

    /** fixtures in the order asked; the last one repeats */
    private static function answers(string ...$fixtures): FakeHttp
    {
        return new FakeHttp(array_map(static fn(string $f): Response => new Response(200, str_ends_with($f, '.xml') ? self::fixture($f) : $f), array_values($fixtures)));
    }

    private static function refusing(): FakeHttp
    {
        return new FakeHttp([new Response(401, self::fixture('unauthorized.html'))]);
    }

    private static function blocking(): FakeHttp
    {
        return new FakeHttp([new Response(401, self::fixture('address-blocked.html'))]);
    }

    /** user-info.xml with these rights */
    private static function user(int $privileges): string
    {
        return str_replace('<p:userPrivils>28<', "<p:userPrivils>$privileges<", self::fixture('user-info.xml'));
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Isds/fixtures/' . $name);
    }

    /** @return list<string> the SOAP operations asked, in order */
    private static function operations(ScriptedIsdsCheck $check): array
    {
        $http = $check->http;
        if (!$http instanceof FakeHttp) {
            return [];
        }
        $operations = '~<(?:[\w-]+:)?(' . implode('|', array_keys(Client::OPERATIONS)) . ')[\s>]~';

        return array_map(static fn(array $call): string => (preg_match($operations, (string) $call['body'], $m) === 1) ? $m[1] : '', $http->calls);
    }

    /**
     * @param Finding[]    $findings
     * @param list<string> $more other values that must not show
     */
    private static function assertNoSecret(array $findings, array $more = []): void
    {
        $all = serialize($findings);
        foreach ([self::PASSWORD, self::LOGIN, base64_encode(self::LOGIN . ':' . self::PASSWORD), ...$more] as $secret) {
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
