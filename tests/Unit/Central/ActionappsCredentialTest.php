<?php

declare(strict_types=1);

/**
 * Actionapps: the login token of a connected AA is stored encrypted, bound to the responder URL it was
 * given for; a legacy plain "jwt:" row is converted on its first use, a cut one is never sent; the column
 * must hold the value whole; every call that carries the token or a password goes through RemoteSource -
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

/** a request whose own ask() - the AA_Http client - must never run */
final class ForbiddenAskRequest extends AA_Request
{
    public function ask($url, $parameters = [], $headers = [])
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
    /** a JWT of the form LoginToken issues - HS256, a signature of 43 characters */
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

    /** central_conf row 12 holding $stored, its column $width bytes wide */
    private static function row(string $stored, int $width = 1024): void
    {
        CentralConfDb::install([['id' => '12', 'AA_ADMIN_PWD' => $stored]], static fn() => $width);
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
            'a password'      => ['secret', Actionapps::CREDENTIAL_PASSWORD],
            'legacy token'    => ['jwt:' . self::TOKEN, Actionapps::CREDENTIAL_LEGACY],
            'legacy, cut'     => [substr('jwt:' . self::TOKEN, 0, 30), Actionapps::CREDENTIAL_CUT],
            'cut in the sign' => [substr('jwt:' . self::TOKEN, 0, -5), Actionapps::CREDENTIAL_CUT],
            'upper case'      => ['JWT:x', Actionapps::CREDENTIAL_PASSWORD],
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

    public function test_saveAuthToken_refuses_a_column_too_narrow(): void
    {
        self::row('', 30);

        try {
            self::aa(['id' => '12'])->saveAuthToken(self::TOKEN);
            self::fail('saved');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('State Check', $e->getMessage());
        }
        self::assertSame([], CentralConfDb::updates(), 'nothing written that would be cut');
    }

    public function test_saveAuthToken_with_an_unknown_width_relies_on_the_read_back(): void
    {
        CentralConfDb::install([['id' => '12', 'AA_ADMIN_PWD' => '']], static fn() => null);
        self::aa(['id' => '12'])->saveAuthToken(self::TOKEN);
        self::assertCount(1, CentralConfDb::updates(), 'written');

        CentralConfDb::install([['id' => '12', 'AA_ADMIN_PWD' => '']], static fn() => null, 30);
        $this->expectException(RuntimeException::class);
        self::aa(['id' => '12'])->saveAuthToken(self::TOKEN);
    }

    public function test_saveAuthToken_fails_when_the_row_does_not_hold_the_value(): void
    {
        // the schema claims enough, the column cuts anyway
        CentralConfDb::install([['id' => '12', 'AA_ADMIN_PWD' => '']], static fn() => 1024, 100);

        $this->expectException(RuntimeException::class);
        self::aa(['id' => '12'])->saveAuthToken(self::TOKEN);
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

    // --- a legacy plain token --------------------------------------------------------------------

    public function test_a_legacy_token_is_sent_and_saved_encrypted_on_its_first_use(): void
    {
        self::row('jwt:' . self::TOKEN);
        $aa = self::stubbed([new AA_Response(['org_name' => 'R'])], ['id' => '12', 'AA_ADMIN_PWD' => 'jwt:' . self::TOKEN]);

        $response = $aa->getResponse(new AA_Request('Get_Aaname'));

        self::assertFalse($response->isError());
        self::assertStringContainsString('Authorization: Bearer ' . self::TOKEN . "\r\n", $aa->opened[0]['options']['http']['header']);
        $updates = CentralConfDb::updates();
        self::assertCount(1, $updates);
        self::assertMatchesRegularExpression("/^UPDATE `central_conf` SET  AA_ADMIN_PWD = 'v1:[\\w-]+' WHERE id = 12 AND AA_ADMIN_PWD = 'jwt:/", $updates[0], 'only over the value it read');
        self::assertSame(Actionapps::CREDENTIAL_TOKEN, $aa->credentialState());

        $aa->getResponse(new AA_Request('Get_Aaname'));
        self::assertCount(1, CentralConfDb::updates(), 'converted once');
    }

    public function test_a_legacy_token_is_still_used_when_the_column_is_too_narrow_for_it_encrypted(): void
    {
        $legacy = 'jwt:' . self::TOKEN;
        self::row($legacy, strlen($legacy));
        $aa = self::aa(['id' => '12', 'AA_ADMIN_PWD' => $legacy]);

        self::assertSame(self::TOKEN, $aa->getAuthToken());
        self::assertSame([], CentralConfDb::updates());
        self::assertSame(Actionapps::CREDENTIAL_LEGACY, $aa->credentialState());
    }

    public function test_a_legacy_token_of_an_aa_without_row_is_used_as_it_is(): void
    {
        $aa = self::aa(['AA_ADMIN_PWD' => 'jwt:' . self::TOKEN]);

        self::assertSame(self::TOKEN, $aa->getAuthToken());
        self::assertSame([], RecordingDb::$log, 'no row to write');
    }

    public function test_a_legacy_token_without_aes_gcm_is_used_as_it_is(): void
    {
        self::row('jwt:' . self::TOKEN);
        $aa = new NoAesActionapps(self::content(['id' => '12', 'AA_ADMIN_PWD' => 'jwt:' . self::TOKEN]));

        self::assertSame(self::TOKEN, $aa->getAuthToken());
        self::assertFalse($aa->upgradeLegacyCredential());
        self::assertSame([], CentralConfDb::updates());
    }

    public function test_a_cut_legacy_token_is_not_authenticated_and_never_sent(): void
    {
        $cut = substr('jwt:' . self::TOKEN, 0, 30);
        self::row($cut);
        $aa = self::stubbed([new AA_Response(['ok'])], ['id' => '12', 'AA_ADMIN_PWD' => $cut]);

        $response = $aa->getResponse(new AA_Request('Get_Aaname'));

        self::assertSame(ResponderPolicy::ERR_NOT_AUTHENTICATED, $response->getError());
        self::assertStringContainsString('authenticate this AA again', (string) $response->getResponse());
        self::assertSame([], $aa->opened);
        self::assertFalse($aa->upgradeLegacyCredential(), 'never encrypted');
        self::assertSame([], CentralConfDb::updates());
    }

    public function test_clearCutCredential_empties_only_a_cut_token(): void
    {
        $cut = substr('jwt:' . self::TOKEN, 0, 30);
        self::row($cut);

        self::assertTrue(self::aa(['id' => '12', 'AA_ADMIN_PWD' => $cut])->clearCutCredential());
        self::assertSame(["UPDATE `central_conf` SET  AA_ADMIN_PWD = '' WHERE id = 12 AND AA_ADMIN_PWD = '$cut'"], CentralConfDb::updates());

        foreach (['', 'secret', 'jwt:' . self::TOKEN] as $stored) {
            self::assertFalse(self::aa(['id' => '12', 'AA_ADMIN_PWD' => $stored])->clearCutCredential());
        }
        self::assertCount(1, CentralConfDb::updates());
    }

    public function test_upgradeLegacyCredential_leaves_other_values(): void
    {
        self::row('');
        foreach (['', 'secret', 'v1:x'] as $stored) {
            self::assertFalse(self::aa(['id' => '12', 'AA_ADMIN_PWD' => $stored])->upgradeLegacyCredential());
        }
        self::assertSame([], CentralConfDb::updates());
    }

    // --- the list on the Central page -------------------------------------------------------------

    public function test_the_list_shows_the_state_instead_of_the_stored_value(): void
    {
        CentralConfDb::install([
            ['id' => '1', 'AA_HTTP_DOMAIN' => self::DOMAIN, 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => self::sealed()],
            ['id' => '2', 'AA_HTTP_DOMAIN' => 'https://moved.example/', 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => self::sealed()],
            ['id' => '3', 'AA_HTTP_DOMAIN' => self::DOMAIN, 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => 'hunter2'],
        ], static fn() => 1024);

        $content = Actionapps::listContent(new \zids(['1', '2', '3'], 's'), ['table' => 'central_conf']);

        self::assertSame(
            ['1' => Actionapps::CREDENTIAL_TOKEN, '2' => Actionapps::CREDENTIAL_UNREADABLE, '3' => Actionapps::CREDENTIAL_PASSWORD],
            array_map(static fn($c) => $c->getValue('AA_ADMIN_PWD'), $content),
        );
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
        $aa = self::stubbed([new AA_Response(self::TOKEN)]);

        self::assertSame(self::TOKEN, $aa->requestJwt('central', 'pw'));
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
