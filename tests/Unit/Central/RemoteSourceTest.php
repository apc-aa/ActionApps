<?php

declare(strict_types=1);

/**
 * RemoteSource: the client of the target for a source AA - https only, no redirect followed, the peer
 * verified, the answer cut off while it is read, only an AA_Response of plain data accepted; the login
 * of the source's superadmin gives a short token.
 */

namespace Tests\Unit\Central;

use AA\Central\RemoteSource;
use AA\Central\SourceFailed;
use AA\Central\ResponderPolicy;
use AA_Request;
use AA_Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RemoteSourceTest extends TestCase
{
    private const URL   = 'https://source.example/aa/central/responder.php';
    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.short-token.sig';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $opened = [];

    /** a remote answering $body with the status and header lines; every request is recorded
     *  @param string|iterable<string> $body @param list<string> $headers */
    private function remote(string|iterable $body, int $status = 200, array $headers = [], int $maxBytes = RemoteSource::MAX_BYTES): RemoteSource
    {
        return new RemoteSource(self::URL, $maxBytes, 5, new MockHttpClient(function (string $method, string $url, array $options) use ($body, $status, $headers): MockResponse {
            $this->opened[] = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
        }));
    }

    /** the request header lines of the request $n */
    private function sentHeaders(int $n = 0): string
    {
        return implode("\n", $this->opened[$n]['options']['headers']);
    }

    /** a JWT of these claims as the source signs it - the target can read, not check, the signature */
    private static function jwtOf(array $claims): string
    {
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64('{"typ":"JWT","alg":"HS256"}') . '.' . $b64(json_encode($claims)) . '.' . $b64('signature');
    }

    private static function appsToken(int $lifetime = RemoteSource::TOKEN_TTL, string $scope = 'apps'): string
    {
        return self::jwtOf(['sub' => '7', 'iat' => time(), 'exp' => time() + $lifetime, 'pwd' => 'x', 'scp' => $scope]);
    }

    /** @return array<string, string> the POST fields of the last request */
    private function posted(): array
    {
        parse_str((string) end($this->opened)['options']['body'], $fields);
        return $fields;
    }

    public function test_an_answer_of_plain_data_is_taken(): void
    {
        $response = $this->remote(serialize(new AA_Response('<aa-app/>')))->ask(new AA_Request('Get_App', ['part' => 'definitions']), [], self::TOKEN);

        self::assertSame('<aa-app/>', $response->getResponse());
        self::assertSame(self::URL, $this->opened[0]['url']);
        self::assertEquals(new AA_Request('Get_App', ['part' => 'definitions']), AA_Request::decode($this->posted()['request']));
    }

    public function test_no_redirect_is_followed_and_the_peer_is_verified(): void
    {
        $this->remote(serialize(new AA_Response('x')))->ask(new AA_Request('Get_App'), [], self::TOKEN);

        $options = $this->opened[0]['options'];
        self::assertSame('POST', $this->opened[0]['method']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame(5.0, (float) $options['max_duration']);
        self::assertSame(5.0, (float) $options['timeout']);
        self::assertTrue($options['verify_peer'] ?? true, 'the library verifies by default - nothing may turn it off');
        self::assertTrue($options['verify_host'] ?? true);
    }

    public function test_the_token_travels_only_in_the_authorization_header(): void
    {
        $this->remote(serialize(new AA_Response('x')))->ask(new AA_Request('Get_App'), [], self::TOKEN);

        self::assertStringContainsString('Authorization: Bearer ' . self::TOKEN, $this->sentHeaders());
        self::assertStringNotContainsString(self::TOKEN, $this->opened[0]['url'] . $this->opened[0]['options']['body']);
    }

    public function test_http_is_refused_before_any_connection(): void
    {
        $remote = new RemoteSource('http://source.example/aa/central/responder.php', transport: new MockHttpClient(function (): never {
            self::fail('no connection over http');
        }));

        $this->expectException(SourceFailed::class);
        $remote->ask(new AA_Request('Get_App'), [], self::TOKEN);
    }

    public function test_a_redirect_is_refused(): void
    {
        $remote = $this->remote(serialize(new AA_Response('x')), 302, ['Location: https://elsewhere.example/steal']);

        try {
            $remote->ask(new AA_Request('Get_App'), [], self::TOKEN);
            self::fail('a redirect');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::UNREACHABLE, $e->reason);
        }
        self::assertCount(1, $this->opened, 'not followed');
    }

    public function test_an_oversize_answer_is_cut_off_while_it_is_read(): void
    {
        $chunks = (static function (): \Generator {
            for ($i = 0; $i < 30; $i++) {
                yield str_repeat('a', 8192);
            }
        })();

        try {
            $this->remote($chunks, maxBytes: 100_000)->ask(new AA_Request('Get_App'), [], self::TOKEN);
            self::fail('an oversize answer');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::FAILED, $e->reason);
        }
    }

    public function test_a_declared_oversize_answer_is_not_read(): void
    {
        $remote = $this->remote(str_repeat('a', 200_000), 200, ['Content-Length: 200000'], 100_000);

        try {
            $remote->ask(new AA_Request('Get_App'), [], self::TOKEN);
            self::fail('a declared oversize answer');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::FAILED, $e->reason);
            self::assertStringContainsString('too big', $e->getMessage());
        }
    }

    public function test_a_silent_source_did_not_answer_in_time(): void
    {
        $silent = (static function (): \Generator {
            yield '';                               // the mock's inactivity timeout
        })();

        try {
            $this->remote($silent)->ask(new AA_Request('Get_App'), [], self::TOKEN);
            self::fail('no answer');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::TIMEOUT, $e->reason);
            self::assertSame('The remote AA did not answer in time', $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function foreignAnswers(): array
    {
        return [
            'another class'            => ['O:8:"stdClass":1:{s:1:"a";i:1;}'],
            'an object in the answer'  => [serialize(new AA_Response(['package' => new \ArrayObject(['x'])]))],
            'no serialized data'       => ['<html>login</html>'],
        ];
    }

    #[DataProvider('foreignAnswers')]
    public function test_an_answer_that_is_no_plain_aa_response_is_refused(string $body): void
    {
        try {
            $this->remote($body)->ask(new AA_Request('Get_App'), [], self::TOKEN);
            self::fail('a foreign answer');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::FAILED, $e->reason);
        }
    }

    public function test_the_login_asks_for_a_short_apps_token(): void
    {
        $apps  = self::appsToken();
        $token = $this->remote(serialize(new AA_Response($apps)))->jwt('source-admin', 'pa ss&word', '123456');

        self::assertSame($apps, $token);
        $posted = $this->posted();
        self::assertEquals(new AA_Request('Get_Jwt', ['ttl' => RemoteSource::TOKEN_TTL, 'scope' => 'apps']), AA_Request::decode($posted['request']));
        self::assertSame(24 * 3600, RemoteSource::TOKEN_TTL);
        self::assertSame(['source-admin', 'pa ss&word', '123456'], [$posted['username'], $posted['password'], $posted['code2fa']]);
        self::assertStringNotContainsString('Authorization', $this->sentHeaders());
    }

    public function test_a_sealed_token_opens_only_for_its_address(): void
    {
        $sealed = (new RemoteSource(self::URL))->seal(self::TOKEN);

        self::assertSame(self::TOKEN, (new RemoteSource(self::URL))->unseal($sealed));
        self::assertSame('', (new RemoteSource('https://elsewhere.example/aa/central/responder.php'))->unseal($sealed), 'a changed Central row gets no token');
        self::assertSame('', (new RemoteSource(self::URL))->unseal(self::TOKEN), 'a bare token');
    }

    /** the store's address is AppStore's - the request names only "store" */
    public function test_the_store_source_asks_the_store(): void
    {
        $store = RemoteSource::forSource(\AA\Central\InstallRun::STORE);

        self::assertSame(self::TOKEN, (new RemoteSource(\AA\Central\AppStore::responderUrl()))->unseal($store->seal(self::TOKEN)));
    }

    /** @return array<string, array{string}> */
    public static function noSources(): array
    {
        return ['a URL' => ['https://evil.example/aa/'], 'this AA' => ['local'], 'zero' => ['0'], 'empty' => [''], 'a word' => ['shop']];
    }

    #[DataProvider('noSources')]
    public function test_no_other_source_is_asked(string $source): void
    {
        $this->expectException(SourceFailed::class);
        RemoteSource::forSource($source);
    }

    public function test_the_apps_of_a_store_are_read_by_the_token(): void
    {
        $app  = ['id' => str_repeat('a', 32), 'name' => 'Web', 'description' => 'A web', 'version' => '1.2', 'published' => true, 'slices' => 2, 'alerts' => 0];
        $apps = (new \AA\Central\RemoteAppSource($this->remote(serialize(new AA_Response([$app]))), self::TOKEN))->apps();

        self::assertSame([$app], $apps);
        self::assertEquals(new AA_Request('Get_Apps', []), AA_Request::decode($this->posted()['request']));
        self::assertStringContainsString('Authorization: Bearer ' . self::TOKEN, $this->sentHeaders());
    }

    /** @return array<string, array{mixed}> */
    public static function noAppLists(): array
    {
        $app = ['id' => str_repeat('a', 32), 'name' => 'Web', 'description' => '', 'version' => '', 'published' => true, 'slices' => 1, 'alerts' => 0];
        return [
            'no list'          => ['apps'],
            'a keyed list'     => [['x' => $app]],
            'an id of no hex'  => [[['id' => '../x'] + $app]],
            'a name of no text' => [[['name' => ['x']] + $app]],
            'a count of text'  => [[['slices' => '1'] + $app]],
            'published as 1'   => [[['published' => 1] + $app]],
            'a key missing'    => [[array_diff_key($app, ['version' => 1])]],
        ];
    }

    /** a newer store may tell more of an app - what this AA does not know it leaves out */
    public function test_more_keys_of_an_app_are_left_out(): void
    {
        $app  = ['id' => str_repeat('a', 32), 'name' => 'Web', 'description' => 'A web', 'version' => '1.2', 'published' => true, 'slices' => 2, 'alerts' => 0];
        $apps = (new \AA\Central\RemoteAppSource($this->remote(serialize(new AA_Response([['icon' => 'x.png', 'rating' => 5] + array_reverse($app, true)]))), self::TOKEN))->apps();

        self::assertSame([$app], $apps);
    }

    #[DataProvider('noAppLists')]
    public function test_a_store_answer_of_another_form_is_refused(mixed $answer): void
    {
        $this->expectException(SourceFailed::class);
        (new \AA\Central\RemoteAppSource($this->remote(serialize(new AA_Response($answer))), self::TOKEN))->apps();
    }

    public function test_the_store_limit_is_told(): void
    {
        try {
            (new \AA\Central\RemoteAppSource($this->remote(serialize(new AA_Response('Too many requests', ResponderPolicy::ERR_RATE_LIMIT))), self::TOKEN))->apps();
            self::fail('no error');
        } catch (SourceFailed $e) {
            self::assertStringContainsString('busy', $e->getMessage());
            self::assertStringContainsString('later', $e->getMessage());
        }
    }

    public function test_a_row_of_no_utf8_gets_no_token(): void
    {
        $remote = new RemoteSource("https://r\xFF.example/aa/central/responder.php");

        self::assertSame('', $remote->unseal($remote->seal(self::TOKEN)));
    }

    /** @return array<string, array{string}> */
    public static function tokensNotKept(): array
    {
        return [
            'an admin token (no scp)'  => [self::jwtOf(['sub' => '7', 'iat' => time(), 'exp' => time() + 3600, 'pwd' => 'x'])],
            'a token of a year'        => [self::appsToken(365 * 24 * 3600)],
            'a day and ten minutes'    => [self::appsToken(RemoteSource::TOKEN_TTL + 600)],
            'another scope'            => [self::appsToken(3600, 'admin')],
            'no iat'                   => [self::jwtOf(['sub' => '7', 'exp' => time() + 3600, 'scp' => 'apps'])],
            'a payload of no JSON'     => [self::TOKEN],
        ];
    }

    /** an older source ignores ttl and scope - its long admin token is never kept */
    #[DataProvider('tokensNotKept')]
    public function test_a_token_other_than_a_short_apps_one_is_refused(string $token): void
    {
        try {
            $this->remote(serialize(new AA_Response($token)))->jwt('source-admin', 'pw');
            self::fail('kept');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::FAILED, $e->reason);
            self::assertStringContainsString('update the source AA', $e->getMessage());
            self::assertStringNotContainsString($token, $e->getMessage());
        }
    }

    public function test_a_day_and_five_minutes_of_clock_slack_is_kept(): void
    {
        $token = self::appsToken(RemoteSource::TOKEN_TTL + 300);

        self::assertSame($token, $this->remote(serialize(new AA_Response($token)))->jwt('source-admin', 'pw'));
    }

    public function test_a_small_part_has_a_small_limit(): void
    {
        $big = serialize(new AA_Response(str_repeat('x', 2 * 1024 * 1024)));

        self::assertSame(2 * 1024 * 1024, strlen($this->remote($big)->ask(new AA_Request('Get_App'), [], self::TOKEN, RemoteSource::MAX_BYTES)->getResponse()), 'a package may be big');
        $this->expectException(SourceFailed::class);
        $this->remote($big)->ask(new AA_Request('Get_App'), [], self::TOKEN);
    }

    /** the URL may hold a login (user:password@) - the page and the log never show it */
    public function test_an_unreachable_source_shows_a_generic_message(): void
    {
        $log     = tempnam(sys_get_temp_dir(), 'aa-remote-');
        $old     = ini_set('error_log', $log);
        try {
            (new RemoteSource('https://user:hunter2@127.0.0.1:1/aa/central/responder.php', timeout: 2))->ask(new AA_Request('Get_App'), [], self::TOKEN);
            self::fail('reached');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::UNREACHABLE, $e->reason);
            self::assertSame('The remote AA cannot be reached - the details are in the PHP error log', $e->getMessage());
        } finally {
            ini_set('error_log', (string) $old);
            $logged = (string) file_get_contents($log);
            unlink($log);
        }
        self::assertStringContainsString('127.0.0.1', $logged);
        self::assertStringNotContainsString('hunter2', $logged);
    }

    public function test_a_refused_login_is_a_login_failure(): void
    {
        try {
            $this->remote(serialize(new AA_Response('A token needs a login', ResponderPolicy::ERR_TOKEN_NEEDS_PASSWORD)))->jwt('source-admin', 'wrong');
            self::fail('no token');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::LOGIN, $e->reason);
            self::assertStringNotContainsString('wrong', $e->getMessage());
        }
    }
}
