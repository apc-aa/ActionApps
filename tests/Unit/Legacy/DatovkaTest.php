<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Isds\DatovkaConf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The client behind {datovka} (misc/datovka/datovka.php) and its settings (AA\Isds\DatovkaConf): which
 * installation an answer of ISDS belongs to, and the addresses, WSDL and certificate of each environment.
 * Unless a test says otherwise, the settings are those datovka_conf.php had while AA shipped it. Each test
 * in its own process: AA_INSTAL_URL is a constant.
 */
#[RunTestsInSeparateProcesses]
final class DatovkaTest extends TestCase
{
    private const DATAPLAN = 'https://www.dataplan.info/aa-dev/';
    private const ADAMS    = 'https://adamsbeauty.ecn.cz/aa-dev/';
    private const UNLISTED = 'https://example.org/apc-aa/';

    /** the test and the production environment of ISDS */
    private const ENVIRONMENTS = [
        'test' => [
            'login'      => 'https://www.datovka-test.gov.cz/as/login',
            'credential' => 'https://cert.datovka-test.gov.cz/asws/extIs2Endpoint',
            'concept'    => 'https://cert.datovka-test.gov.cz/asws/konceptEndpoint',
            'view'       => 'https://www.datovka-test.gov.cz/as/koncept/view',
            'cert'       => 'myTestCert.crt',
        ],
        'standard' => [
            'login'      => 'https://www.datovka.gov.cz/as/login',
            'credential' => 'https://cert.datovka.gov.cz/asws/extIs2Endpoint',
            'concept'    => 'https://cert.datovka.gov.cz/asws/konceptEndpoint',
            'view'       => 'https://www.datovka.gov.cz/as/koncept/view',
            'cert'       => 'myRealCert.crt',
        ],
    ];

    /** @param array<mixed>|null $settings DATOVKA_CONF; null = as AA shipped it */
    private static function boot(string $installUrl, ?array $settings = null): void
    {
        define('AA_BASE_PATH', dirname(__DIR__, 3) . '/');
        define('AA_INSTAL_URL', $installUrl);
        require_once __DIR__ . '/fixtures/datovka_harness.php';
        \RecordingDatovka::$settings = DatovkaConf::fromArray($settings ?? [
            'instances' => [1000 => ['url' => self::DATAPLAN], 1001 => ['url' => self::ADAMS]],
            'test'      => ['cert' => AA_BASE_PATH . '/config/certs/myTestCert.crt', 'atsId' => ''],
            'standard'  => ['cert' => AA_BASE_PATH . '/config/certs/myRealCert.crt', 'atsId' => ''],
        ]);
    }

    private static function datovka(string $conf): \RecordingDatovka
    {
        return new \RecordingDatovka($conf, 'abc1234', 'Annotation', '');
    }

    private static function wsdl(string $name): string
    {
        return dirname(__DIR__, 3) . "/misc/datovka/wsdls/$name.wsdl";
    }

    private static function cert(string $environment): string
    {
        return AA_BASE_PATH . '/config/certs/' . self::ENVIRONMENTS[$environment]['cert'];
    }

    /** @return iterable<string, array{string}> */
    public static function environments(): iterable
    {
        yield 'test' => ['test'];
        yield 'standard' => ['standard'];
    }

    /** @return iterable<string, array{string, int}> */
    public static function listedInstallations(): iterable
    {
        yield 'first' => [self::DATAPLAN, 1000];
        yield 'second' => [self::ADAMS, 1001];
    }

    #[DataProvider('listedInstallations')]
    public function test_construct_endsIdWithThisInstallation(string $installUrl, int $instance): void
    {
        self::boot($installUrl);

        $this->assertSame($instance, self::datovka('test')->getDatovkaId() % 10000);
    }

    /** an installation the settings do not list is 0, not the first one listed */
    public function test_construct_unlistedInstallationEndsIdWithZero(): void
    {
        self::boot(self::UNLISTED);

        $this->assertSame(0, self::datovka('test')->getDatovkaId() % 10000);
    }

    public function test_construct_noSettingsEndsIdWithZero(): void
    {
        self::boot(self::DATAPLAN, []);

        $this->assertSame(0, self::datovka('test')->getDatovkaId() % 10000);
    }

    #[DataProvider('environments')]
    public function test_getAtsId_emptyAsShipped(string $environment): void
    {
        self::boot(self::DATAPLAN);

        $this->assertSame('', self::datovka($environment)->getAtsId());
    }

    #[DataProvider('environments')]
    public function test_startWithLogin_sendsAtsIdOfThisInstallation(string $environment): void
    {
        self::boot(self::DATAPLAN, [$environment => ['atsId' => 'a1b2c3d4e5f60718']]);
        $datovka = self::datovka($environment);

        $this->assertSame('a1b2c3d4e5f60718', $datovka->getAtsId());
        try {
            $datovka->startWithLogin();
            $this->fail('no redirect');
        } catch (\DatovkaRedirect $redirect) {
            $this->assertSame(['atsId' => 'a1b2c3d4e5f60718', 'appToken' => $datovka->getDatovkaId()], $redirect->params);
        }
    }

    #[DataProvider('environments')]
    public function test_startWithLogin_sendsToLoginOfEnvironment(string $environment): void
    {
        self::boot(self::DATAPLAN);
        $datovka = self::datovka($environment);

        try {
            $datovka->startWithLogin();
            $this->fail('no redirect');
        } catch (\DatovkaRedirect $redirect) {
            $this->assertSame(self::ENVIRONMENTS[$environment]['login'], $redirect->url);
            $this->assertSame(['atsId' => '', 'appToken' => $datovka->getDatovkaId()], $redirect->params);
        }
    }

    #[DataProvider('environments')]
    public function test_getCredentials_asksAuthenticationServiceOfEnvironment(string $environment): void
    {
        self::boot(self::DATAPLAN);
        $_REQUEST['datovkaSessionId'] = 'session-1';

        self::datovka($environment)->credentials();

        $call = \RecordingDatovka::$calls[0];
        $this->assertSame(self::wsdl('GetCredential'), $call['wsdl']);
        $this->assertFileExists($call['wsdl']);
        $this->assertSame('authConfirmation', $call['method']);
        $this->assertSame(['sessionId' => 'session-1'], $call['params']);
        $this->assertSame(self::ENVIRONMENTS[$environment]['credential'], $call['options']['location']);
        $this->assertSame(self::cert($environment), $call['options']['local_cert']);
    }

    #[DataProvider('environments')]
    public function test_prepareConcept_asksConceptServiceOfEnvironment(string $environment): void
    {
        self::boot(self::DATAPLAN);

        self::datovka($environment)->concept('T08-limited');

        $call = \RecordingDatovka::$calls[0];
        $this->assertSame(self::wsdl('SetConcept'), $call['wsdl']);
        $this->assertFileExists($call['wsdl']);
        $this->assertSame('SetConcept', $call['method']);
        $this->assertSame(self::ENVIRONMENTS[$environment]['concept'], $call['options']['location']);
        $this->assertSame(self::cert($environment), $call['options']['local_cert']);
        $this->assertSame('ExtWS', $call['options']['login']);
        $this->assertSame('T08-limited', $call['options']['password']);
    }

    #[DataProvider('environments')]
    public function test_run_showsConceptInEnvironment(string $environment): void
    {
        self::boot(self::DATAPLAN);
        $_REQUEST['datovkaSessionId'] = 'session-1';

        try {
            self::datovka($environment)->run();
            $this->fail('no redirect');
        } catch (\DatovkaRedirect $redirect) {
            $this->assertSame(self::ENVIRONMENTS[$environment]['view'], $redirect->url);
            $this->assertSame(['konceptId' => '4711'], $redirect->params);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function badTokens(): iterable
    {
        yield 'missing' => [''];
        yield 'null' => [null];
        yield 'array' => [['491032843381000']];
        yield 'not a number' => ['abc'];
        yield 'number and text' => ['12abc'];
        yield '14 digits' => ['49103284338100'];
        yield '16 digits' => ['4910328433810001'];
        yield 'leading zero' => ['091032843381000'];
        yield 'line break after' => ["491032843381000\n"];
        yield 'an int, not a string' => [491032843381000];
    }

    /** service.php answers 404 to anything but a token datovka_id could have made */
    #[DataProvider('badTokens')]
    public function test_validAppToken_refusesOtherValues(mixed $token): void
    {
        self::boot(self::DATAPLAN);

        $this->assertNull(\RecordingDatovka::validAppToken($token));
    }

    /** @return iterable<string, array{string}> */
    public static function edgeTokens(): iterable
    {
        yield 'lowest' => ['100000000000000'];
        yield 'highest' => ['999999999999999'];
    }

    #[DataProvider('edgeTokens')]
    public function test_validAppToken_passesEdges(string $token): void
    {
        self::boot(self::DATAPLAN);

        $this->assertSame($token, \RecordingDatovka::validAppToken($token));
    }

    public function test_validAppToken_passesIdOfDatovka(): void
    {
        self::boot(self::ADAMS);
        $token = (string) self::datovka('test')->getDatovkaId();

        $this->assertSame($token, \RecordingDatovka::validAppToken($token));
    }

    public function test_possiblyRedirectToAa_ownInstanceStays(): void
    {
        self::boot(self::ADAMS);

        $this->assertTrue(\RecordingDatovka::possiblyRedirectToAa('491032843381001'));
    }

    public function test_possiblyRedirectToAa_otherInstanceGetsTheAnswer(): void
    {
        self::boot(self::ADAMS);
        $_GET = ['appToken' => '491032843381000', 'datovkaSessionId' => 's 1'];

        try {
            \RecordingDatovka::possiblyRedirectToAa($_GET['appToken']);
            $this->fail('no redirect');
        } catch (\DatovkaRedirect $redirect) {
            $this->assertSame(self::DATAPLAN . 'misc/datovka/service.php?appToken=491032843381000&datovkaSessionId=s+1', $redirect->url);
            $this->assertSame('', $redirect->params);
        }
    }

    /** id 0 = sent by an installation the settings did not list: the answer is this one's */
    public function test_possiblyRedirectToAa_instanceZeroStays(): void
    {
        self::boot(self::ADAMS);
        $_GET = ['appToken' => '491032843380000'];

        $this->assertTrue(\RecordingDatovka::possiblyRedirectToAa($_GET['appToken']));
    }

    /** an id the settings do not list has no installation to go to: service.php answers 404 */
    public function test_possiblyRedirectToAa_unknownInstanceRefused(): void
    {
        self::boot(self::ADAMS);
        $_GET = ['appToken' => '491032843380007'];

        $this->assertFalse(\RecordingDatovka::possiblyRedirectToAa($_GET['appToken']));
    }
}
