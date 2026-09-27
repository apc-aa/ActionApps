<?php

declare(strict_types=1);

/**
 * Actionapps: the login token of a connected AA is stored encrypted, bound to the responder URL it was
 * given for; any other value (a password, a plain token) is never sent; every call that carries the
 * token or a password goes through RemoteSource -
 * https only, no redirect, the peer verified, the answer capped, only plain data (or the module
 * definitions) unserialized.
 */

namespace Tests\Unit\Central;

use AA\Central\Actionapps;
use AA\Central\ResponderPolicy;
use AA\Central\SourceFailed;
use AA\Util\Txt;
use AA_Definition_Module_Slice;
use AA_Request;
use AA_Response;
use Closure;
use ItemContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Legacy\Fixtures\CentralConfDb;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use Tests\Unit\Legacy\Fixtures\StubbedActionapps;

/** a request whose own ask() - the HTTP client - must never run */
final class ForbiddenAskRequest extends AA_Request
{
    public function ask($url, $parameters = [], $headers = [], ?\AA\Http\ClientInterface $http = null)
    {
        throw new \LogicException('AA_Request::ask() used');
    }
}

/** an AA on a PHP without AES-GCM */
final class NoAesActionapps extends Actionapps
{
    protected static function aesGcmAvailable(): bool
    {
        return false;
    }
}

final class ActionappsCredentialTest extends TestCase
{
    private const DOMAIN = 'https://r.example.org/';
    private const URL    = 'https://r.example.org/aa/central/responder.php';
    /** a JWT of the form LoginToken issues */
    private const TOKEN  = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI3In0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';          // _m(), safe()
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php'; // RecordingDb
        require_once __DIR__ . '/../../../include/varset.php';
        require_once __DIR__ . '/../../../central/include/actionapps.class.php';
        require_once __DIR__ . '/../Legacy/fixtures/central_fixtures.php';   // StubbedActionapps, CentralConfDb
    }

    protected function setUp(): void
    {
        RecordingDb::reset();
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
    }

    /** @param array<string, string> $values */
    private static function content(array $values): ItemContent
    {
        $content = new ItemContent();
        foreach ($values + ['AA_HTTP_DOMAIN' => self::DOMAIN, 'AA_BASE_DIR' => 'aa/'] as $column => $value) {
            $content->setValue($column, $value);
        }
        return $content;
    }

    /** @param array<string, string> $values */
    private static function aa(array $values = []): Actionapps
    {
        return new Actionapps(self::content($values));
    }

    /** @param list<AA_Response> $answers */
    private static function stubbed(array $answers, array $values = []): StubbedActionapps
    {
        return StubbedActionapps::answering(self::content($values), $answers);
    }

    /** a token of these claims, signed by nobody - Central never checks the signature */
    private static function jwt(array $claims): string
    {
        return 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.' . str_repeat('x', 43);
    }

    /** a token issued now for $lifetime seconds */
    private static function tokenFor(int $lifetime): string
    {
        return self::jwt(['sub' => '7', 'iat' => time(), 'exp' => time() + $lifetime, 'pwd' => 'abc']);
    }

    /** an AA with the token stored as setAuthToken() stores it */
    private static function authenticated(array $answers, array $values = []): StubbedActionapps
    {
        $aa = self::stubbed($answers, $values);
        $aa->setAuthToken(self::TOKEN);
        return $aa;
    }

    /** the stored value of the token for the default address */
    private static function sealed(): string
    {
        $aa = self::aa();
        $aa->setAuthToken(self::TOKEN);
        return (string) $aa->getValue('AA_ADMIN_PWD');
    }

    /** central_conf row 12 holding $stored */
    private static function row(string $stored): void
    {
        CentralConfDb::install([['id' => '12', 'AA_ADMIN_PWD' => $stored]]);
    }

    // --- stored encrypted ----------------------------------------------------------------------

    public function test_the_token_is_stored_encrypted_and_read_back(): void
    {
        $aa = self::aa();
        $aa->setAuthToken(self::TOKEN);

        $stored = (string) $aa->getValue('AA_ADMIN_PWD');
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString(self::TOKEN, $stored);
        self::assertSame(self::TOKEN, $aa->getAuthToken());
        self::assertSame(Actionapps::CREDENTIAL_TOKEN, $aa->credentialState());
        self::assertSame(self::TOKEN, self::aa(['AA_ADMIN_PWD' => $stored])->getAuthToken(), 'the same row loaded again');
    }

    public function test_the_address_costs_no_byte(): void
    {
        // version, nonce, the token and the tag - the address is bound as additional data, not stored
        self::assertSame(strlen('v1:') + 12 + strlen(Txt::bin2url(self::TOKEN . str_repeat("\0", 16))), strlen(self::sealed()));
    }

    public function test_two_encryptions_of_one_token_differ(): void
    {
        self::assertNotSame(self::sealed(), self::sealed());
    }

    public function test_a_token_stored_for_another_address_is_not_used(): void
    {
        // someone edited the domain of the row - the token must not go to the new host
        $moved = self::stubbed([new AA_Response(['ok'])], ['AA_HTTP_DOMAIN' => 'https://evil.example/', 'AA_ADMIN_PWD' => self::sealed()]);

        self::assertNull($moved->getAuthToken());
        self::assertSame(Actionapps::CREDENTIAL_UNREADABLE, $moved->credentialState());
        $response = $moved->getResponse(new AA_Request('Get_Aaname'));
        self::assertSame(ResponderPolicy::ERR_NOT_AUTHENTICATED, $response->getError());
        self::assertStringContainsString('authenticate this AA again', (string) $response->getResponse());
        self::assertSame([], $moved->opened, 'nothing sent');
    }

    public function test_an_undecryptable_token_is_not_used(): void
    {
        $aa = self::stubbed([new AA_Response(['ok'])], ['AA_ADMIN_PWD' => 'v1:AAAAAAAAAAAAnotaciphertext']);

        self::assertNull($aa->getAuthToken());
        self::assertSame(Actionapps::CREDENTIAL_UNREADABLE, $aa->credentialState());
        self::assertStringContainsString('authenticate this AA again', (string) $aa->getResponse(new AA_Request('Get_Aaname'))->getResponse());
        self::assertSame([], $aa->opened);
    }

    /** @return array<string, array{string, string}> */
    public static function states(): array
    {
        return [
            'empty'           => ['', Actionapps::CREDENTIAL_NONE],
            'a password'      => ['secret', Actionapps::CREDENTIAL_PLAIN],
            'plain token'     => ['jwt:' . self::TOKEN, Actionapps::CREDENTIAL_PLAIN],
            'upper case'      => ['V1:x', Actionapps::CREDENTIAL_PLAIN],
            'v1 garbage'      => ['v1:', Actionapps::CREDENTIAL_UNREADABLE],
        ];
    }

    #[DataProvider('states')]
    public function test_the_state_of_a_stored_value(string $stored, string $state): void
    {
        self::assertSame($state, self::aa(['AA_ADMIN_PWD' => $stored])->credentialState());
    }

    // --- saved to central_conf --------------------------------------------------------------------

    public function test_saveAuthToken_writes_the_encrypted_value_by_the_row_id(): void
    {
        self::row('');
        $aa = self::aa(['id' => '12']);

        $aa->saveAuthToken(self::TOKEN);

        $updates = CentralConfDb::updates();
        self::assertCount(1, $updates);
        self::assertMatchesRegularExpression("/^UPDATE `central_conf` SET  AA_ADMIN_PWD = 'v1:[\\w-]+' WHERE id = 12$/", $updates[0]);
        self::assertStringNotContainsString(self::TOKEN, $updates[0]);
        self::assertSame(self::TOKEN, self::aa(['AA_ADMIN_PWD' => (string) $aa->getValue('AA_ADMIN_PWD')])->getAuthToken());
    }

    public function test_saveAuthToken_without_aes_gcm_is_refused(): void
    {
        self::row('');

        try {
            (new NoAesActionapps(self::content(['id' => '12'])))->saveAuthToken(self::TOKEN);
            self::fail('saved');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('AES-GCM', $e->getMessage());
        }
        self::assertSame([], CentralConfDb::updates());
    }

    // --- anything else ----------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function plainValues(): array
    {
        return ['a plain token of older Central' => ['jwt:' . self::TOKEN], 'a password' => ['hunter2']];
    }

    #[DataProvider('plainValues')]
    public function test_a_plain_value_is_not_authenticated_and_never_sent(string $stored): void
    {
        self::row($stored);
        $aa = self::stubbed([new AA_Response(['ok'])], ['id' => '12', 'AA_ADMIN_PWD' => $stored]);

        $response = $aa->getResponse(new AA_Request('Get_Aaname'));

        self::assertNull($aa->getAuthToken());
        self::assertSame(ResponderPolicy::ERR_NOT_AUTHENTICATED, $response->getError());
        self::assertStringContainsString('Authenticate this AA', (string) $response->getResponse());
        self::assertSame([], $aa->opened, 'nothing sent');
        self::assertSame([], CentralConfDb::updates(), 'nothing rewritten');
    }

    // --- the list on the Central page -------------------------------------------------------------

    public function test_the_list_shows_the_state_and_the_expiry_instead_of_the_stored_value(): void
    {
        $sealed = static function (string $token, string $domain = self::DOMAIN): string {
            $aa = self::aa(['AA_HTTP_DOMAIN' => $domain]);
            $aa->setAuthToken($token);
            return (string) $aa->getValue('AA_ADMIN_PWD');
        };
        $valid = self::tokenFor(3600);
        CentralConfDb::install([
            ['id' => '1', 'AA_HTTP_DOMAIN' => self::DOMAIN, 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => $sealed($valid)],
            ['id' => '2', 'AA_HTTP_DOMAIN' => 'https://moved.example/', 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => $sealed($valid)],
            ['id' => '3', 'AA_HTTP_DOMAIN' => self::DOMAIN, 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => 'hunter2'],
            ['id' => '4', 'AA_HTTP_DOMAIN' => self::DOMAIN, 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => $sealed(self::jwt(['iat' => time() - 90000, 'exp' => time() - 3600]))],
            ['id' => '5', 'AA_HTTP_DOMAIN' => self::DOMAIN, 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => ''],
        ]);

        $content = Actionapps::listContent(new \zids(['1', '2', '3', '4', '5'], 's'), ['table' => 'central_conf']);

        self::assertSame(
            ['1' => 'Authenticated until ' . date('Y-m-d H:i', time() + 3600), '2' => 'Not authenticated - get a token', '3' => 'Not authenticated - get a token', '4' => 'Expired - get a token', '5' => 'Not configured'],
            array_map(static fn($c) => $c->getValue('AA_ADMIN_PWD'), $content),
        );
    }

    /** @return array<string, array{array<string, int>, string}> claims => the badge */
    public static function badges(): array
    {
        $now = 1_800_000_000;
        return [
            'a year of exp, issued two days ago' => [['iat' => $now - 2 * 86400, 'exp' => $now + 300 * 86400], 'Expired - get a token'],
            'a year of exp, issued an hour ago'  => [['iat' => $now - 3600, 'exp' => $now + 300 * 86400], 'Authenticated until ' . date('Y-m-d H:i', $now - 3600 + 86400 + 300)],
            'a day, issued an hour ago'          => [['iat' => $now - 3600, 'exp' => $now - 3600 + 86400], 'Authenticated until ' . date('Y-m-d H:i', $now - 3600 + 86400)],
            'no iat'                             => [['exp' => $now + 3600], 'Not authenticated - get a token'],
        ];
    }

    /** @param array<string, int> $claims */
    #[DataProvider('badges')]
    public function test_the_badge_shows_when_the_token_dies_by_exp_or_by_its_age(array $claims, string $badge): void
    {
        $aa = self::aa();
        $aa->setAuthToken(self::jwt(['sub' => '7'] + $claims));

        self::assertSame($badge, $aa->credentialBadge(1_800_000_000));
    }

    // --- the transport -----------------------------------------------------------------------------

    /** @return array<string, array{Closure(Actionapps): mixed, string, mixed}> every remote call Central makes with the token */
    public static function calls(): array
    {
        return [
            'getResponse'        => [static fn(Actionapps $aa) => $aa->getResponse(new ForbiddenAskRequest('Get_Aaname')), 'Get_Aaname', []],
            'requestAAName'      => [static fn(Actionapps $aa) => @$aa->requestAAName(), 'Get_Aaname', ['org_name' => 'Remote', 'domain' => self::DOMAIN]],
            'requestModules'     => [static fn(Actionapps $aa) => $aa->requestModules(['S']), 'Get_Modules', []],
            'requestSlices'      => [static fn(Actionapps $aa) => $aa->requestSlices(), 'Get_Modules', []],
            'requestDefinitions' => [static fn(Actionapps $aa) => $aa->requestDefinitions('Slice', ['x']), 'Get_Module_Defs', []],
            'synchronize'        => [static fn(Actionapps $aa) => $aa->synchronize(['cmd']), 'Do_Synchronize', []],
            'doOptimize'         => [static fn(Actionapps $aa) => $aa->doOptimize('AA\Optimize\UpdateDbStructure', 'test'), 'Do_Optimize', 'OK'],
            'doOptimizeType'     => [static fn(Actionapps $aa) => $aa->doOptimizeType('sql_update', 'test'), 'Do_Optimize_Type', 'OK'],
        ];
    }

    /** @param Closure(Actionapps): mixed $call */
    #[DataProvider('calls')]
    public function test_every_call_goes_through_the_hardened_transport(Closure $call, string $command, mixed $answer): void
    {
        $aa = self::authenticated([new AA_Response($answer)]);

        $call($aa);

        self::assertCount(1, $aa->opened);
        [$url, $options] = [$aa->opened[0]['url'], $aa->opened[0]['options']];
        self::assertSame(self::URL, $url);
        self::assertSame(0, $options['http']['follow_location']);
        self::assertTrue($options['ssl']['verify_peer']);
        self::assertTrue($options['ssl']['verify_peer_name']);
        self::assertStringContainsString('Authorization: Bearer ' . self::TOKEN . "\r\n", $options['http']['header']);
        $posted = $aa->posted();
        self::assertSame($command, unserialize($posted['request'], ['allowed_classes' => [AA_Request::class, ForbiddenAskRequest::class]])->getCommand());
        self::assertSame(['request'], array_keys($posted), 'no login in the request');
        self::assertNull($aa->getLastError());
    }

    /** @return array<string, array{Closure(StubbedActionapps): void, string}> */
    public static function failures(): array
    {
        return [
            'no answer of AA_Response' => [static function (StubbedActionapps $aa): void {}, 'no answer of plain data'],
            'no connection'            => [static function (StubbedActionapps $aa): void {
                $aa->refuse = new SourceFailed(SourceFailed::UNREACHABLE, 'The remote AA cannot be reached - the details are in the PHP error log');
            }, 'cannot be reached'],
            'HTTP 500'                 => [static function (StubbedActionapps $aa): void {
                $aa->headers = ['HTTP/1.1 500 Internal Server Error'];
            }, 'HTTP 500'],
            'a redirect'               => [static function (StubbedActionapps $aa): void {
                $aa->headers = ['HTTP/1.1 301 Moved Permanently', 'Location: https://elsewhere.example/'];
            }, 'redirects'],
        ];
    }

    /** @param Closure(StubbedActionapps): void $break */
    #[DataProvider('failures')]
    public function test_a_transport_failure_is_an_error_answer(Closure $break, string $text): void
    {
        $aa = new StubbedActionapps(self::content([]), ['no serialized answer']);
        $aa->setAuthToken(self::TOKEN);
        $break($aa);

        $response = $aa->getResponse(new AA_Request('Get_Aaname'));

        self::assertSame(3, $response->getError(), 'no usable answer');
        self::assertStringContainsString($text, (string) $response->getResponse());
        self::assertStringStartsWith('3 ', (string) $aa->getLastError());
        self::assertStringNotContainsString(self::TOKEN, (string) $aa->getLastError());
    }

    public function test_an_http_row_gets_no_token_sent(): void
    {
        $aa = self::authenticated([new AA_Response(['ok'])], ['AA_HTTP_DOMAIN' => 'http://r.example.org/']);

        $response = $aa->getResponse(new AA_Request('Get_Aaname'));

        self::assertTrue($response->isError());
        self::assertStringContainsString('https', (string) $response->getResponse());
        self::assertSame([], $aa->opened);
    }

    public function test_module_definitions_may_be_big_other_answers_not(): void
    {
        $big  = str_repeat('x', 2 * 1024 * 1024);
        $defs = self::authenticated([new AA_Response(['x' => new AA_Definition_Module_Slice(), 'pad' => $big])])->requestDefinitions('Slice', ['x']);
        self::assertInstanceOf(AA_Definition_Module_Slice::class, $defs['x'], 'the definitions are objects');

        $aa = self::authenticated([new AA_Response(['x' => $big])]);
        self::assertSame([], $aa->requestModules(['S']));
        self::assertStringContainsString('too big', (string) $aa->getLastError());
    }

    public function test_a_report_may_be_a_few_megabytes(): void
    {
        self::assertSame(str_repeat('x', 3 * 1024 * 1024), self::authenticated([new AA_Response(str_repeat('x', 3 * 1024 * 1024))])->doOptimize('AA\Optimize\UpdateDbStructure', 'test'));

        $aa = self::authenticated([new AA_Response(str_repeat('x', 5 * 1024 * 1024))]);
        self::assertStringContainsString('too big', $aa->doOptimizeType('sql_update', 'test'));
    }

    public function test_no_other_object_is_unserialized(): void
    {
        $aa = self::authenticated([new AA_Response(['x' => new \ArrayObject([])])]);

        self::assertSame([], $aa->requestDefinitions('Slice', ['x']));
        self::assertSame([], $aa->requestModules(['S']));
        self::assertStringStartsWith('3 ', (string) $aa->getLastError());
    }

    // --- the login ---------------------------------------------------------------------------------

    public function test_requestJwt_sends_the_login_through_the_transport(): void
    {
        $token = self::tokenFor(24 * 3600);
        $aa    = self::stubbed([new AA_Response($token)]);

        self::assertSame($token, $aa->requestJwt('central', 'pw'));
        $options = $aa->opened[0]['options'];
        self::assertSame(0, $options['http']['follow_location']);
        self::assertTrue($options['ssl']['verify_peer']);
        self::assertStringNotContainsString('Authorization', $options['http']['header']);
        $posted = $aa->posted();
        self::assertSame(['central', 'pw'], [$posted['username'], $posted['password']]);
        self::assertSame('Get_Jwt', AA_Request::decode($posted['request'])->getCommand());
    }

    public function test_requestJwt_refuses_http(): void
    {
        // the real transport: it refuses before it connects
        $aa = self::aa(['AA_HTTP_DOMAIN' => 'http://r.example.org/']);

        self::assertSame('', $aa->requestJwt('central', 'pw'));
        self::assertStringContainsString('https', (string) $aa->getLastError());
    }

    /** @return array<string, array{string}> */
    public static function longTokens(): array
    {
        return [
            'a year (an older AA ignores ttl)' => [self::tokenFor(365 * 24 * 3600)],
            'a day and ten minutes'            => [self::tokenFor(24 * 3600 + 600)],
            'no iat'                           => [self::jwt(['sub' => '7', 'exp' => time() + 3600])],
            'no claims'                        => ['eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.bm90IGpzb24.' . str_repeat('x', 43)],
        ];
    }

    #[DataProvider('longTokens')]
    public function test_requestJwt_refuses_a_token_of_more_than_a_day(string $token): void
    {
        $aa = self::stubbed([new AA_Response($token)], ['id' => '12']);

        self::assertSame('', $aa->requestJwt('central', 'pw'));
        self::assertStringContainsString('update the remote AA', (string) $aa->getLastError());
        self::assertStringNotContainsString($token, (string) $aa->getLastError());
    }

    public function test_requestJwt_accepts_a_day_and_the_clock_slack(): void
    {
        $token = self::tokenFor(24 * 3600 + 300);
        self::assertSame($token, self::stubbed([new AA_Response($token)])->requestJwt('central', 'pw'));
    }

    public function test_requestJwt_refuses_an_answer_of_no_token(): void
    {
        $aa = self::stubbed([new AA_Response(['not', 'a token'])]);

        self::assertSame('', $aa->requestJwt('central', 'pw'));
        self::assertNotSame('', (string) $aa->getLastError());
    }

    public function test_requestJwt_keeps_the_error_of_the_remote(): void
    {
        $aa = self::stubbed([new AA_Response('Needs a login by password', 13)]);

        self::assertSame('', $aa->requestJwt('central', 'pw'));
        self::assertSame('13 Needs a login by password', $aa->getLastError());
    }
}
