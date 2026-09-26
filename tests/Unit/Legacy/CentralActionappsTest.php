<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Central\ResponderPolicy;
use AA_Actionapps;
use AA_Request;
use AA_Response;
use ItemContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\CentralConfDb;
use Tests\Unit\Legacy\Fixtures\CentralTestHelpers;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use Tests\Unit\Legacy\Fixtures\StubbedActionapps;

// class declarations only
require_once dirname(__DIR__, 3) . '/central/include/actionapps.class.php';
require_once __DIR__ . '/fixtures/central_fixtures.php';   // the trait must exist before the class

/** An AA whose getResponse() answers from a script and keeps the requests. */
class CentralScriptedAa extends AA_Actionapps
{
    /** @var AA_Request[] */
    public array $requests = [];

    /** @param AA_Response[] $answers  one per request, the last one repeats */
    public function __construct(private array $answers)
    {
        parent::__construct(new ItemContent());
    }

    public function getResponse($request)
    {
        $this->requests[] = $request;
        return count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
    }
}

/**
 * Characterization of AA_Actionapps (central/include/actionapps.class.php) - one AA installation as
 * Central sees it: its central_conf row, the remote commands it sends, the JWT login.
 * No DB (RecordingDb answers the SELECTs) and no network: the request methods run against a scripted
 * getResponse(), getResponse() and the Get_Jwt login against a stubbed responder behind the real
 * RemoteSource (StubbedActionapps).
 *
 * Pins today's behaviour; "pinned:" marks behaviour that is questionable rather than wanted.
 */
final class CentralActionappsTest extends TestCase
{
    use CentralTestHelpers;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';    // _m(), now(), safe()
        require_once __DIR__ . '/fixtures/store_fixtures.php';   // RecordingDb
        require_once __DIR__ . '/../../../include/varset.php';   // Cvarset of DB_AA::update()
    }

    protected function setUp(): void
    {
        RecordingDb::reset();   // other tests leave their statements and answers behind
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
    }

    /** @param array<string, string> $values  central_conf column => value */
    private static function aa(array $values): AA_Actionapps
    {
        $content = new ItemContent();
        foreach ($values as $column => $value) {
            $content->setValue($column, $value);
        }
        return new AA_Actionapps($content);
    }

    /** An AA whose responder answers every request with $answer
     *  @param array<string, string> $values central_conf column => value */
    private static function stubbedAa(AA_Response $answer, array $values = []): StubbedActionapps
    {
        $content = new ItemContent();
        foreach ($values + ['AA_HTTP_DOMAIN' => 'https://r.example.org/', 'AA_BASE_DIR' => 'aa/'] as $column => $value) {
            $content->setValue($column, $value);
        }
        return StubbedActionapps::answering($content, [$answer]);
    }

    // --- local data (central_conf) --------------------------------------------------------------

    /** @return array<string, array{string, string, string, string}> domain, base dir => name, responder URL */
    public static function locations(): array
    {
        return [
            'usual'                     => ['https://example.org/', 'apc-aa/', 'Org (https://example.org/apc-aa/)', 'https://example.org/apc-aa/central/responder.php'],
            'base dir without slash'    => ['https://example.org/', 'aa', 'Org (https://example.org/aa)', 'https://example.org/aa/central/responder.php'],
            'both with slash'           => ['https://example.org/', '/aa/', 'Org (https://example.org//aa/)', 'https://example.org//aa/central/responder.php'],
            // pinned: plain concatenation, the host runs into the path
            'domain without slash'      => ['https://example.org', 'aa/', 'Org (https://example.orgaa/)', 'https://example.orgaa/central/responder.php'],
            'empty base dir'            => ['https://example.org/', '', 'Org (https://example.org/)', 'https://example.org/central/responder.php'],
        ];
    }

    #[DataProvider('locations')]
    public function test_getName_and_getComunicatorUrl_concatenate_domain_and_base_dir(string $domain, string $base, string $name, string $url): void
    {
        $aa = self::aa(['ORG_NAME' => 'Org', 'AA_HTTP_DOMAIN' => $domain, 'AA_BASE_DIR' => $base]);

        $this->assertSame($name, $aa->getName());
        $this->assertSame($url, $aa->getComunicatorUrl());
    }

    /** @return array<string, array{string, string, ?string}> domain, base dir => URL of another page, null: refused */
    public static function pageLocations(): array
    {
        return [
            'usual'                => ['https://example.org/', 'apc-aa/', 'https://example.org/apc-aa/central/installapp.php'],
            'base dir without slash' => ['https://example.org/', 'aa', 'https://example.org/aa/central/installapp.php'],
            'both with slash'      => ['https://example.org/', '/aa/', 'https://example.org/aa/central/installapp.php'],
            'empty base dir'       => ['https://example.org/', '', 'https://example.org/central/installapp.php'],
            'upper case scheme'    => ['HTTP://example.org/', 'aa/', 'HTTP://example.org/aa/central/installapp.php'],
            'bare host, base dir from root' => ['https://actionapps.org', '/aadev', 'https://actionapps.org/aadev/central/installapp.php'],
            'bare host, empty base dir' => ['https://360re.ecn.cz', '', 'https://360re.ecn.cz/central/installapp.php'],
            'path in the domain'   => ['https://example.org/fff', '', 'https://example.org/fff/central/installapp.php'],
            'bare host glued to base dir' => ['https://example.org', 'aa/', null],
            'user info'            => ['https://user:pw@example.org/', '', null],
            'path in the domain, base dir from root' => ['https://example.org/fff', '/aa', 'https://example.org/fff/aa/central/installapp.php'],
            'path in the domain glued to base dir' => ['https://example.org/fff', 'aa', null],
            'trailing newline'     => ["https://example.org\n", '', null],
            'empty domain'         => ['', 'aa/', null],
            'relative domain'      => ['example.org/', 'aa/', null],
            'no host'              => ['https://', '/aa/', null],
            'other scheme'         => ['javascript:alert(1)//', '', null],
        ];
    }

    #[DataProvider('pageLocations')]
    public function test_getPageUrl_joins_domain_and_base_dir_or_refuses(string $domain, string $base, ?string $url): void
    {
        $aa = self::aa(['AA_HTTP_DOMAIN' => $domain, 'AA_BASE_DIR' => $base]);

        $this->assertSame($url, $aa->getPageUrl('central/installapp.php'));
    }

    public function test_getName_does_not_escape(): void
    {
        // pinned: the callers escape (synchronize3.php safe()s it), the select boxes do not
        $this->assertSame('<b>Org</b> (x/y/)', self::aa(['ORG_NAME' => '<b>Org</b>', 'AA_HTTP_DOMAIN' => 'x/', 'AA_BASE_DIR' => 'y/'])->getName());
    }

    public function test_getName_without_org_name_is_the_address(): void
    {
        $this->assertSame('https://example.org/aa/', self::aa(['ORG_NAME' => '', 'AA_HTTP_DOMAIN' => 'https://example.org/', 'AA_BASE_DIR' => 'aa/'])->getName());
        $this->assertSame('https://example.org/aa/', self::aa(['ORG_NAME' => ' ', 'AA_HTTP_DOMAIN' => 'https://example.org/', 'AA_BASE_DIR' => 'aa/'])->getName());
    }

    private const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI3In0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

    /** @return array<string, array{string, ?string}> AA_ADMIN_PWD => auth token */
    public static function passwords(): array
    {
        return [
            'jwt'             => ['jwt:' . self::TOKEN, self::TOKEN],
            'jwt cut short'   => ['jwt:eyJ.abc.def', null],
            'plain password'  => ['secret', null],
            'empty'           => ['', null],
            'jwt prefix only' => ['jwt:', null],
            'upper case'      => ['JWT:x', null],
        ];
    }

    #[DataProvider('passwords')]
    public function test_getAuthToken_reads_a_whole_legacy_token(string $pwd, ?string $token): void
    {
        $aa = self::aa(['AA_ADMIN_USER' => 'central', 'AA_ADMIN_PWD' => $pwd]);

        $this->assertSame($token, $aa->getAuthToken());
        $this->assertSame($pwd, $aa->getValue('AA_ADMIN_PWD'));
        $this->assertSame('central', $aa->getAccessUsername());
    }

    public function test_setAuthToken_stores_it_encrypted_as_the_password(): void
    {
        $aa = self::aa(['AA_HTTP_DOMAIN' => 'https://example.org/', 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => 'secret']);

        $aa->setAuthToken('tok');

        $this->assertStringStartsWith('v1:', $aa->getValue('AA_ADMIN_PWD'));
        $this->assertSame('tok', $aa->getAuthToken());
    }

    public function test_saveAuthToken_updates_central_conf_by_numeric_id(): void
    {
        CentralConfDb::install([['id' => '12', 'AA_ADMIN_PWD' => '']], static fn() => 1024);

        self::collectingWarnings(static fn() => self::aa(['id' => '12', 'AA_HTTP_DOMAIN' => 'https://example.org/', 'AA_BASE_DIR' => 'aa/'])->saveAuthToken('abc'), $warnings);

        $this->assertMatchesRegularExpression("/^UPDATE `central_conf` SET  AA_ADMIN_PWD = 'v1:[\\w-]+' WHERE id = 12$/", CentralConfDb::updates()[0]);
        $this->assertSame([], $warnings);
    }

    // --- loading from central_conf ---------------------------------------------------------------

    /** central_conf rows as the DB returns them - in id order, not by name */
    private static function answerCentralConf(): void
    {
        RecordingDb::$answer = static fn(string $sql): array => match (true) {
            str_starts_with($sql, 'SELECT DISTINCT `id` FROM `central_conf`') => [['id' => '12'], ['id' => '7']],
            str_starts_with($sql, 'SELECT * FROM central_conf')             => [
                ['id' => '7', 'ORG_NAME' => 'Beta', 'AA_HTTP_DOMAIN' => 'https://b.example.org/', 'AA_BASE_DIR' => 'apc-aa/', 'AA_ADMIN_PWD' => 'secret'],
                ['id' => '12', 'ORG_NAME' => 'Alpha', 'AA_HTTP_DOMAIN' => 'https://a.example.org/', 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => 'jwt:' . self::TOKEN],
            ],
            default => [],
        };
        $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
    }

    public function test_getArray_returns_approved_aas_in_the_order_of_the_name_query(): void
    {
        self::answerCentralConf();

        // MakeSQLOrderBy() warns about the central_conf search fields having no 'table' - not the engine's
        $aas = self::collectingWarnings(static fn() => AA_Actionapps::getArray());

        $this->assertSame([
            'SELECT DISTINCT `id` FROM `central_conf` WHERE  central_conf.status_code=1  ORDER BY ORG_NAME',
            "SELECT * FROM central_conf WHERE id   IN ('12','7') ",
        ], RecordingDb::$log);
        $this->assertSame([12, 7], array_keys($aas), 'keyed by central_conf.id (int keys), ordered as the id query returned');
        $this->assertContainsOnlyInstancesOf(AA_Actionapps::class, $aas);
        $this->assertSame('Alpha (https://a.example.org/aa/)', $aas[12]->getName());
        $this->assertSame(self::TOKEN, $aas[12]->getAuthToken());
        $this->assertSame('7', $aas[7]->getValue('id'));
        $this->assertSame('secret', $aas[7]->getValue('AA_ADMIN_PWD'));
    }

    public function test_listed_is_a_row_of_getArray_only(): void
    {
        self::answerCentralConf();

        $aa = self::collectingWarnings(static fn() => AA_Actionapps::listed(12));
        $this->assertSame('Alpha (https://a.example.org/aa/)', $aa?->getName());
        $this->assertStringContainsString('central_conf.status_code=1', RecordingDb::$log[0], 'the approved bin, as the pickers');

        self::answerCentralConf();
        $this->assertNull(self::collectingWarnings(static fn() => AA_Actionapps::listed(2)), 'a row in no bin, or no row at all');
    }

    public function test_getArray_without_approved_aa_is_empty_after_one_query(): void
    {
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        $this->assertSame([], self::collectingWarnings(static fn() => AA_Actionapps::getArray()));
        $this->assertCount(1, RecordingDb::$log, 'no content query for no ids');
    }

    public function test_getActionapps_loads_once_per_id(): void
    {
        RecordingDb::$answer = static fn(string $sql): array => [['id' => '9901', 'ORG_NAME' => 'Cached']];
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        $first  = AA_Actionapps::getActionapps('9901');
        $second = AA_Actionapps::getActionapps('9901');

        $this->assertSame($first, $second);   // a static cache for the whole request - the id is unique to this test
        $this->assertSame(["SELECT * FROM central_conf WHERE id   = '9901' "], RecordingDb::$log);
        $this->assertSame('Cached', $first->getValue('ORG_NAME'));
    }

    // --- remote commands (getResponse() scripted) ------------------------------------------------

    public function test_requestModules_sends_the_types_and_returns_the_answer(): void
    {
        $modules = ['0123456789abcdef0123456789abcdef' => 'News', 'fedcba9876543210fedcba9876543210' => 'Events'];
        $aa      = new CentralScriptedAa([new AA_Response($modules)]);

        $this->assertSame($modules, $aa->requestModules(['S', 'W']));
        $this->assertSame('Get_Modules', $aa->requests[0]->getCommand());
        $this->assertSame(['types' => ['S', 'W']], $aa->requests[0]->getParameters());
    }

    public function test_requestSlices_asks_for_type_S(): void
    {
        $aa = new CentralScriptedAa([new AA_Response(['x' => 'News'])]);

        $this->assertSame(['x' => 'News'], $aa->requestSlices());
        $this->assertSame(['types' => ['S']], $aa->requests[0]->getParameters());
    }

    /** @return array<string, array{AA_Response, array}> */
    public static function moduleAnswers(): array
    {
        return [
            'error'        => [new AA_Response('No response recieved', 3), []],
            'scalar'       => [new AA_Response('odd'), ['odd']],   // pinned: cast, not rejected
            'null'         => [new AA_Response(null), []],
        ];
    }

    #[DataProvider('moduleAnswers')]
    public function test_requestModules_casts_any_answer_to_array(AA_Response $answer, array $expected): void
    {
        $this->assertSame($expected, (new CentralScriptedAa([$answer]))->requestModules(['S']));
    }

    public function test_requestDefinitions_sends_type_ids_and_limited(): void
    {
        $defs = ['a' => new \AA_Definition_Module_Slice()];
        $aa   = new CentralScriptedAa([new AA_Response($defs)]);

        $this->assertSame($defs, $aa->requestDefinitions('Slice', ['a'], ['definitions' => true]));
        $this->assertSame('Get_Module_Defs', $aa->requests[0]->getCommand());
        $this->assertSame(['type' => 'Slice', 'ids' => ['a'], 'limited' => ['definitions' => true]], $aa->requests[0]->getParameters());

        $aa->requestDefinitions('Site', ['b']);
        $this->assertSame(['type' => 'Site', 'ids' => ['b'], 'limited' => []], $aa->requests[1]->getParameters(), 'limited defaults to []');
    }

    public function test_requestDefinitions_error_is_empty(): void
    {
        $this->assertSame([], (new CentralScriptedAa([new AA_Response('Unknown module type', 6)]))->requestDefinitions('Foo', ['a']));
    }

    public function test_requestDefinitions_non_array_answer_throws(): void
    {
        // pinned: unlike requestModules() there is no cast; the array return type throws
        $this->expectException(\TypeError::class);
        (new CentralScriptedAa([new AA_Response('odd')]))->requestDefinitions('Slice', ['a']);
    }

    public function test_synchronize_sends_the_commands_and_returns_the_report(): void
    {
        $aa = new CentralScriptedAa([new AA_Response(['field x updated'])]);

        $this->assertSame(['field x updated'], $aa->synchronize(['cmd1', 'cmd2']));
        $this->assertSame('Do_Synchronize', $aa->requests[0]->getCommand());
        $this->assertSame(['sync' => ['cmd1', 'cmd2']], $aa->requests[0]->getParameters());
    }

    public function test_synchronize_returns_the_error_code_and_text(): void
    {
        // the task log says why it failed
        $aa = new CentralScriptedAa([new AA_Response('No response recieved (2 - timeout)', 3)]);

        $this->assertSame('3 No response recieved (2 - timeout)', $aa->synchronize(['cmd']));
    }

    public function test_requestAAName_asks_once_and_caches_the_name(): void
    {
        $aa = new CentralScriptedAa([new AA_Response(['org_name' => 'Remote org', 'domain' => 'https://r.example.org/'])]);

        // pinned: the first call reads the not yet set cache key (a warning)
        $name = self::collectingWarnings(static fn() => $aa->requestAAName(), $warnings);

        $this->assertSame('Remote org', $name);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('org_name', $warnings[0]);
        $this->assertSame('Remote org', $aa->requestAAName());
        $this->assertCount(1, $aa->requests);
        $this->assertSame('Get_Aaname', $aa->requests[0]->getCommand());
    }

    public function test_requestAAName_caches_the_error_code_as_the_name(): void
    {
        $aa   = new CentralScriptedAa([new AA_Response('timeout', 3), new AA_Response(['org_name' => 'Later'])]);
        $name = self::collectingWarnings(static fn() => $aa->requestAAName());

        // pinned: an error is never retried, and it names the code, not the text
        $this->assertSame("Can't get org_name - 3", $name);
        $this->assertSame("Can't get org_name - 3", $aa->requestAAName());
        $this->assertCount(1, $aa->requests);
    }

    // --- getResponse(): the JWT login -------------------------------------------------------------

    public function test_getResponse_with_jwt_sends_bearer_and_no_session(): void
    {
        $aa = self::stubbedAa(new AA_Response(['ok']));
        $aa->setAuthToken(self::TOKEN);

        $this->assertEquals(new AA_Response(['ok']), $aa->getResponse(new AA_Request('Get_Aaname')));
        $this->assertSame('https://r.example.org/aa/central/responder.php', $aa->opened[0]['url']);
        $this->assertStringContainsString('Authorization: Bearer ' . self::TOKEN, $aa->opened[0]['options']['http']['header']);
        $this->assertStringNotContainsString('Cookie', $aa->opened[0]['options']['http']['header']);
        $this->assertSame(['request'], array_keys($aa->posted()));
        $this->assertNull($aa->getLastError());
    }

    public function test_getResponse_with_jwt_error_keeps_the_code_and_does_not_fall_back_to_login(): void
    {
        $aa = self::stubbedAa(new AA_Response('expired', 101), ['AA_ADMIN_USER' => 'central']);
        $aa->setAuthToken(self::TOKEN);

        $response = $aa->getResponse(new AA_Request('Get_Aaname'));

        $this->assertSame(101, $response->getError());
        $this->assertCount(1, $aa->opened);
        $this->assertSame('101 expired', $aa->getLastError(), 'the text too - a refused token (14) says to authenticate again');
        $this->assertSame(['request'], array_keys($aa->posted()), 'no login happened');
    }

    /** @return array<string, array{0: string}> AA_ADMIN_PWD without a token */
    public static function noToken(): array
    {
        return ['a password' => ['secret'], 'empty' => ['']];
    }

    #[DataProvider('noToken')]
    public function test_getResponse_without_jwt_asks_to_authenticate_and_sends_nothing(string $pwd): void
    {
        $aa = self::stubbedAa(new AA_Response(['ok']), ['AA_ADMIN_USER' => 'central', 'AA_ADMIN_PWD' => $pwd]);

        $response = $aa->getResponse(new AA_Request('Get_Aaname'));

        $this->assertTrue($response->isError());
        $this->assertSame(ResponderPolicy::ERR_NOT_AUTHENTICATED, $response->getError());
        $this->assertStringContainsString('Authenticate this AA on the ActionApps Central page', (string) $response->getResponse());
        $this->assertSame($response->getError() . ' ' . $response->getResponse(), $aa->getLastError());
        $this->assertSame([], $aa->opened, 'no password or session login');
    }

    public function test_lastErrorHtml_names_the_aa_and_is_cleared_by_a_success(): void
    {
        $aa = self::stubbedAa(new AA_Response(['ok']), ['ORG_NAME' => '<b>Org</b>']);
        $aa->getResponse(new AA_Request('Get_Aaname'));
        $this->assertStringStartsWith('&lt;b&gt;Org&lt;/b&gt; (https://r.example.org/aa/): 15 ', $aa->lastErrorHtml(), 'escaped - Central pages print it as HTML');

        $aa->setAuthToken(self::TOKEN);
        $aa->getResponse(new AA_Request('Get_Aaname'));
        $this->assertSame('', $aa->lastErrorHtml());
        $this->assertNull($aa->getLastError());
    }

    public function test_requestJwt_returns_the_token(): void
    {
        $this->assertSame(self::TOKEN, self::stubbedAa(new AA_Response(self::TOKEN))->requestJwt('central', 'secret'));
    }

    public function test_requestJwt_failure_is_empty_with_the_error_code(): void
    {
        $aa = StubbedActionapps::answering(new ItemContent(), []);   // no address at all: refused before any connection

        $this->assertSame('', $aa->requestJwt('central', 'secret'));
        $this->assertStringStartsWith('3 ', $aa->getLastError());
        $this->assertSame([], $aa->opened);
    }

    public function test_requestJwt_failure_keeps_the_text_of_the_remote(): void
    {
        $aa = self::stubbedAa(new AA_Response('Needs a login by password', 13));

        $this->assertSame('', $aa->requestJwt('central', 'secret'));
        $this->assertSame('13 Needs a login by password', $aa->getLastError());
    }

    public function test_requestJwt_non_token_answer_is_refused(): void
    {
        $aa = self::stubbedAa(new AA_Response(['not', 'a token']));

        $this->assertSame('', $aa->requestJwt('central', 'secret'));
        $this->assertSame('The remote AA gave no login token - update it', $aa->getLastError());
    }

    // --- queued with the tasks -------------------------------------------------------------------

    public function test_serialized_aa_carries_its_login(): void
    {
        $aa = self::aa(['ORG_NAME' => 'Org', 'AA_ADMIN_USER' => 'central', 'AA_ADMIN_PWD' => 'secret']);

        // pinned: the serialized AA holds the password or JWT; the Central tasks queue only its
        // central_conf id, only older queued rows hold this object
        $this->assertStringContainsString('secret', serialize($aa));
    }
}
