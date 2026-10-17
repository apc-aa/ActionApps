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
use Tests\Unit\Fixtures\FakeHttp;
use Tests\Unit\Optimize\Fixtures\ScriptedIsdsCheck;

/**
 * CheckIsds: logs in with each usable test account - the box, the rights of the user, the expiry of
 * the password; a refused login is an error that names the constants, never the login or the
 * password, and the daily run never tries it again. ISDS answers from the fixtures of the client's
 * tests; the refusals live in a MemoryLatch.
 */
final class CheckIsdsTest extends TestCase
{
    private const SITE     = '51de0000000000000000000000000001';
    private const CAMPAIGN = 'cafe0000000000000000000000000001';
    private const MESSAGE  = 'beef0000000000000000000000000002';

    private const LOGIN    = 'nszm01login';
    private const PASSWORD = 'Test-Pa55-never-shown';

    /** the expiry in password-info.xml: 2011-07-06 13:33:39 CEST */
    private const EXPIRY = 1309952019;

    private const DAY = 86400;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
        require_once __DIR__ . '/../Isds/fixtures/MemoryLatch.php';
        require_once __DIR__ . '/fixtures/ScriptedIsdsCheck.php';
    }

    public function test_types_extendedOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_EXTENDED], CheckIsds::types());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function nothingToTry(): array
    {
        return [
            'no account'     => [[]],
            'half set'       => [self::account(password: null)],
            // production lets an application in with a certificate only; CheckIsdsAccounts warns
            'production'     => [['AA_APIKEY_ISDS_NSZM_SITE' => self::SITE, 'AA_APIKEY_ISDS_NSZM_SLICES' => self::CAMPAIGN . ',' . self::MESSAGE]],
        ];
    }

    /** @param array<string, string> $constants */
    #[DataProvider('nothingToTry')]
    public function test_findings_nothingToTryIsOk(array $constants): void
    {
        $check = self::check($constants);

        $findings = $check->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('No usable ISDS account to log in with', $findings[0]->title);
        self::assertSame([], self::operations($check));
    }

    public function test_findings_loginOkNamesTheBox(): void
    {
        $check = self::check(self::account(), self::answers('owner-info.xml', 'user-info.xml', 'password-info-nil.xml'));

        $findings = $check->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('ISDS accounts log in to their data boxes: AA_APIKEY_ISDSTEST_NSZM (kv62bqf)', $findings[0]->title);
        self::assertSame(['GetOwnerInfoFromLogin2', 'GetUserInfoFromLogin2', 'GetPasswordInfo'], self::operations($check));
    }

    public function test_findings_loginRefusedNamesTheConstantsOnly(): void
    {
        $check = self::check(self::account(), self::refusing());

        $findings = $check->findings();

        self::assertSame([Finding::ERROR], self::severities($findings));
        $finding = $findings[0];
        self::assertSame('ISDS refused the login of account AA_APIKEY_ISDSTEST_NSZM', $finding->title);
        self::assertSame('auth:test:NSZM', $finding->key);
        self::assertStringContainsString('Login blocked until', $finding->evidence[0]['ISDS']);
        self::assertStringContainsString('Check AA_APIKEY_ISDSTEST_NSZM_LOGIN and AA_APIKEY_ISDSTEST_NSZM_PASSWORD in include/config.php', $finding->advice);
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
        $constants = self::account() + self::account('ECN', site: '51de0000000000000000000000000002', slices: 'cafe0000000000000000000000000003,beef0000000000000000000000000004');
        $check     = self::check($constants, self::refusing());
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
        $check = self::check(self::account() + self::account('ECN', site: '51de0000000000000000000000000002', slices: 'cafe0000000000000000000000000003,beef0000000000000000000000000004'), self::blocking());

        $findings = $check->findings();

        self::assertSame(['blocked:test', 'not-tried:test'], self::keys($findings));
        self::assertSame([Finding::ERROR, Finding::INFO], self::severities($findings));
        self::assertSame('ISDS blocked logins from the address of this server', $findings[0]->title);
        self::assertStringContainsString('Wait: each attempt extends the block. AA tries once more by itself 24 hours later', $findings[0]->advice);
        self::assertStringNotContainsString('AA_APIKEY_', $findings[0]->advice);
        self::assertSame('ISDS accounts not tried, as ISDS blocked the address of this server: AA_APIKEY_ISDSTEST_NSZM', $findings[1]->title);
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
        $constants = self::account('ECN', site: '51de0000000000000000000000000002', slices: 'cafe0000000000000000000000000003,beef0000000000000000000000000004') + self::account();
        $badRights = str_replace('<p:userPrivils>28</p:userPrivils>', '<p:userPrivils>x</p:userPrivils>', self::fixture('user-info.xml'));
        $check     = self::check($constants, new FakeHttp(array_map(static fn(string $body): Response => new Response(200, $body), [
            '<html>a gateway</html>', self::fixture('user-info.xml'), self::fixture('password-info-nil.xml'),
            self::fixture('owner-info.xml'), $badRights, self::fixture('password-info-nil.xml'),
        ])));

        $findings = $check->findings();

        self::assertSame(['unreadable:test:ECN', 'rights-unknown:test:NSZM'], self::keys($findings));
        self::assertSame('ISDS gave account AA_APIKEY_ISDSTEST_ECN an answer AA cannot read', $findings[0]->title);
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
        $check = self::check(self::account() + self::account('ECN', site: '51de0000000000000000000000000002', slices: 'cafe0000000000000000000000000003,beef0000000000000000000000000004'), new FakeHttp([new Response(503, 'Service Unavailable')]));

        $findings = $check->findings();

        self::assertSame(['busy:test:ECN', 'not-tried:test'], self::keys($findings));
        self::assertSame([Finding::WARNING, Finding::INFO], self::severities($findings));
        self::assertSame('ISDS asked account AA_APIKEY_ISDSTEST_ECN to come back later', $findings[0]->title);
        self::assertSame('ISDS accounts not tried, as ISDS did not answer: AA_APIKEY_ISDSTEST_NSZM', $findings[1]->title);
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
        self::assertSame('ISDS gave account AA_APIKEY_ISDSTEST_NSZM no answer', $findings[0]->title);
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

        self::assertSame('The user of ISDS account AA_APIKEY_ISDSTEST_NSZM lacks the rights 4 (send messages), 8 (lists of messages and delivery info)', $findings[0]->title);
        self::assertStringContainsString('without them ISDS refuses CreateMessage, GetMessageStateChanges, GetListOfSentMessages', $findings[0]->advice);
        self::assertSame('The user of ISDS account AA_APIKEY_ISDSTEST_NSZM lacks the rights 16 (search for data boxes)', $findings[1]->title);
        self::assertStringContainsString('without them ISDS refuses CheckDataBox', $findings[1]->advice);
        self::assertSame('The user of ISDS account AA_APIKEY_ISDSTEST_NSZM has rights AA must not have: 1 (read received messages), 2 (read received messages to own hands), 32 (manage the data box)', $findings[2]->title);
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
        self::assertSame('The password of ISDS account AA_APIKEY_ISDSTEST_NSZM expires on ' . date('Y-m-d', self::EXPIRY), $findings[0]->title);
        self::assertStringContainsString('AA_APIKEY_ISDSTEST_NSZM_PASSWORD', $findings[0]->advice);
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

    // ------------------------------------------------------------ helpers

    /** @param array<string, string> $constants */
    private static function check(array $constants, ?ClientInterface $http = null): ScriptedIsdsCheck
    {
        $check            = new ScriptedIsdsCheck();
        $check->constants = $constants;
        if ($http) {
            $check->http = $http;
        }

        return $check;
    }

    /** @return array<string, string> the constants of a test account; null leaves one out */
    private static function account(string $name = 'NSZM', ?string $password = self::PASSWORD, string $site = self::SITE, string $slices = self::CAMPAIGN . ',' . self::MESSAGE): array
    {
        return array_filter([
            "AA_APIKEY_ISDSTEST_{$name}_SITE"     => $site,
            "AA_APIKEY_ISDSTEST_{$name}_SLICES"   => $slices,
            "AA_APIKEY_ISDSTEST_{$name}_LOGIN"    => self::LOGIN,
            "AA_APIKEY_ISDSTEST_{$name}_PASSWORD" => $password,
        ], static fn(?string $v): bool => !is_null($v));
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
