<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use AA\Credential\Secret;
use AA\Http\Client;
use AA\Http\ClientCertificate;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * AA's HTTP door. The library does the work; what is worth pinning is the part we decided:
 * a status that is not 200 is an answer, not an exception, and no answer at all is.
 */
final class ClientTest extends TestCase
{
    /** a public address as a literal, so no test asks DNS */
    private const PUBLIC_IP = '93.184.215.14';

    public function test_theStatusAndBodyComeBackWhateverTheServiceSaid(): void
    {
        $client = new Client(http: new MockHttpClient([
            new MockResponse('{"ok":true}', ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]),
            new MockResponse('{"error":"no credit"}', ['http_code' => 402]),
        ]));

        $ok = $client->request('GET', 'https://example.org/a');
        self::assertTrue($ok->ok());
        self::assertSame(['ok' => true], $ok->json());
        self::assertSame('application/json', $ok->headers['content-type']);

        $paid = $client->request('GET', 'https://example.org/b');
        self::assertFalse($paid->ok(), 'a refusal is an answer - the caller decides what it means');
        self::assertSame(402, $paid->status);
    }

    public function test_aBodyAndHeadersAreSentAsGiven(): void
    {
        $seen = null;
        $client = new Client(http: new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null, 'headers' => $options['headers'] ?? []];
            return new MockResponse('{}');
        }));

        $client->request('post', 'https://example.org/x', '{"a":1}', ['Authorization' => 'Bearer t']);

        self::assertSame('POST', $seen['method']);
        self::assertSame('{"a":1}', $seen['body']);
        self::assertContains('Authorization: Bearer t', $seen['headers']);
    }

    // a cast to int would turn half a second into 0, which the library reads as no limit at all
    public function test_aFractionOfASecondStaysALimit(): void
    {
        $seen   = null;
        $client = new Client(timeout: 0.5, idle: 0.25, http: new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$options['max_duration'], $options['timeout']];
            return new MockResponse('{}');
        }));

        $client->request('GET', 'https://example.org/x');

        self::assertSame([0.5, 0.25], $seen);
    }

    // libcurl would send a POST again on a kept-alive connection the server closed unanswered
    #[DataProvider('reuse')]
    public function test_construct_connectionReuse(bool $reuse, array $expected): void
    {
        $seen   = null;
        $client = new Client(reuse: $reuse, http: new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['extra']['curl'] ?? [];
            return new MockResponse('{}');
        }));

        $client->request('POST', 'https://example.org/x', 'a');

        self::assertSame($expected, $seen);
    }

    public static function reuse(): array
    {
        return [
            'kept by default' => [true, []],
            'forbidden'       => [false, [\CURLOPT_FRESH_CONNECT => true, \CURLOPT_FORBID_REUSE => true]],
        ];
    }

    public function test_noAnswerAtAllIsAnExceptionWorthRetrying(): void
    {
        $client = new Client(http: new MockHttpClient(static fn(): MockResponse => new MockResponse('', ['error' => 'Connection refused'])));

        $this->expectException(TransportException::class);
        $client->request('GET', 'https://example.org/x');
    }

    private static function throwing(\Throwable $e): HttpClientInterface
    {
        return new class ($e) implements HttpClientInterface {
            public function __construct(private \Throwable $e) {}

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw $this->e;
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \LogicException('not used');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
    }

    public function test_anAnswerTheLibraryCannotFollowIsNoAnswer(): void
    {
        // what the SSRF guard's wrapper throws for "HTTP/1.1 0" followed by a body
        $broken = self::throwing(new \LogicException('Instance of "CurlResponse" is already consumed and cannot be managed by "NoPrivateNetworkHttpClient". A decorated client should not call any of the response\'s methods in its "request()" method.'));

        $this->expectException(TransportException::class);
        (new Client(http: $broken))->request('HEAD', 'https://example.org/x');
    }

    public function test_anyOtherLogicExceptionIsABugNotAnOutage(): void
    {
        $this->expectException(\LogicException::class);
        (new Client(http: self::throwing(new \LogicException('A chunk passthru must yield an "isLast()" chunk.'))))->request('GET', 'https://example.org/x');
    }

    public function test_aBodyOverTheLimitIsRefusedByItsDeclaredLength(): void
    {
        // a short body under a long declared length: only the Content-Length check can refuse it
        // ('identity' keeps the mock from checking the length itself)
        $client = new Client(maxBytes: 10, http: new MockHttpClient([
            new MockResponse('short', ['response_headers' => ['Content-Length' => '11', 'Content-Encoding' => 'identity']]),
        ]));

        $this->expectException(ResponseTooLargeException::class);
        $client->request('GET', 'https://example.org/big');
    }

    public function test_aHeadIsNotRefusedForTheBodyItDoesNotSend(): void
    {
        $client = new Client(maxBytes: 10, http: new MockHttpClient([
            new MockResponse('', ['response_headers' => ['Content-Length' => '67108864']]),
        ]));

        self::assertSame(200, $client->request('HEAD', 'https://example.org/big')->status);
    }

    public function test_underTheLimitAStalledOrBrokenBodyIsStillNoAnswer(): void
    {
        $broken = static function (): \Generator {
            yield 'xx';
            throw new \RuntimeException('connection reset');
        };
        foreach ([new MockResponse(['xxxx', '']), new MockResponse($broken())] as $mock) {
            $client = new Client(idle: 1, maxBytes: 10, http: new MockHttpClient([$mock]));
            try {
                $client->request('GET', 'https://example.org/stall');
                self::fail('a body that never finishes is not an answer');
            } catch (TransportException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_aLimitedReadStillSaysWhereARedirectPoints(): void
    {
        $client = new Client(maxBytes: 10, http: new MockHttpClient([
            new MockResponse('', ['http_code' => 301, 'redirect_url' => 'https://example.org/new']),
        ]));

        self::assertSame('https://example.org/new', $client->request('GET', 'https://example.org/old')->redirect);
    }

    public function test_aBodyOverTheLimitIsRefusedWithoutADeclaredLength(): void
    {
        // the mock hands over the whole body at once, so this pins the refusal, not how early the
        // download stops - that is stream() + cancel() on a real connection
        $client = new Client(maxBytes: 10, http: new MockHttpClient([new MockResponse(['xxxx', 'xxxx', 'xxxx'])]));

        $this->expectException(ResponseTooLargeException::class);
        $client->request('GET', 'https://example.org/endless');
    }

    public function test_aBodyUpToTheLimitComesBackWhole(): void
    {
        $client = new Client(maxBytes: 10, http: new MockHttpClient([
            new MockResponse(['12345', '67890'], ['http_code' => 404]),
        ]));

        $answer = $client->request('GET', 'https://example.org/exact');
        self::assertSame('1234567890', $answer->body);
        self::assertSame(404, $answer->status, 'the limit does not change what a status means');
    }

    public function test_aBodyThatIsNotJsonIsRefusedRatherThanGuessed(): void
    {
        $client = new Client(http: new MockHttpClient([new MockResponse('<html>gateway</html>')]));

        $this->expectException(\JsonException::class);
        $client->request('GET', 'https://example.org/x')->json();
    }

    public function test_redirectsAreNotFollowedUnlessAsked(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options['max_redirects'];
            return new MockResponse('{}');
        });

        (new Client(http: $mock))->request('GET', 'https://example.org/a');
        (new Client(redirects: 3, http: $mock))->request('GET', 'https://example.org/b');

        self::assertSame([0, 3], $seen, 'an API answer is never a detour; whoever has to follow says so');
    }

    /**
     * There is no "send no User-Agent": left out, the library fills in its own name, and a blank
     * one is not neutral either - Google's keyless translate endpoint answers it with a 302.
     */
    public function test_everyRequestSaysWhoIsAsking(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options['headers'] ?? [];
            return new MockResponse('{}');
        });

        (new Client(http: $mock))->request('GET', 'https://example.org/a');
        (new Client(agent: Client::agent('link checker'), http: $mock))->request('GET', 'https://example.org/b');

        self::assertContains('User-Agent: ActionApps (+https://actionapps.org)', $seen[0]);
        self::assertContains('User-Agent: ActionApps (+https://actionapps.org; link checker)', $seen[1]);
    }

    /**
     * A 3xx is an answer, not a detour - so the caller gets to see where it points. The link
     * checker walks the chain hop by hop on this, and State Check compares the target.
     */
    public function test_aRedirectWeDidNotFollowSaysWhereItPoints(): void
    {
        $client = new Client(http: new MockHttpClient([
            new MockResponse('Moved', ['http_code' => 301, 'redirect_url' => 'https://example.org/new']),
            new MockResponse('{}', ['http_code' => 200]),
        ]));

        $moved = $client->request('GET', 'https://example.org/old');
        self::assertSame(301, $moved->status);
        self::assertSame('https://example.org/new', $moved->redirect);

        self::assertSame('', $client->request('GET', 'https://example.org/new')->redirect, 'an answer that is not a 3xx points nowhere');
    }

    // the transport would answer, so an exception means the guard refused the address - and nothing was even sent
    #[DataProvider('privateUrls')]
    public function test_publicOnly_refusesThePrivateNetwork(string $url, string $host): void
    {
        $sent   = 0;
        $client = Client::publicOnly(transport: new MockHttpClient(static function () use (&$sent): MockResponse {
            $sent++;
            return new MockResponse('secret');
        }));
        try {
            $client->request('GET', $url);
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertSame($host, $e->blockedHost());
            self::assertSame(0, $sent);
        }
    }

    public static function privateUrls(): array
    {
        return [
            'loopback'                   => ['http://127.0.0.1/server-status', '127.0.0.1'],
            'loopback, other port'       => ['http://127.0.0.2:8080/admin', '127.0.0.2'],
            'private'                    => ['http://10.0.0.1/admin', '10.0.0.1'],
            'private 192.168/16'         => ['https://192.168.1.1/', '192.168.1.1'],
            'private 172.16/12'          => ['http://172.16.0.1/', '172.16.0.1'],
            'link-local'                 => ['http://169.254.169.254/latest/meta-data/', '169.254.169.254'],
            'this host'                  => ['http://0.0.0.0/', '0.0.0.0'],
            'ipv6'                       => ['http://[::1]/', '[::1]'],
            'IPv6 unique local fc00::/7' => ['http://[fc00::1]/', '[fc00::1]'],
            'IPv6 unique local fd..'     => ['http://[fd12:3456::1]/', '[fd12:3456::1]'],
            'IPv6 link local'            => ['http://[fe80::1]/', '[fe80::1]'],
            'IPv4 in IPv6'               => ['http://[::ffff:127.0.0.1]/', '[::ffff:127.0.0.1]'],
            'a name of this host'        => ['http://localhost/', 'localhost'],
            'decimal loopback'           => ['http://2130706433/', '2130706433'],
        ];
    }

    /** a public page that sends us on to a private one is refused at the hop, before it is asked */
    public function test_publicOnly_refusesARedirectToAPrivateAddress(): void
    {
        $asked = [];
        $client = Client::publicOnly(redirects: 3, transport: new MockHttpClient(static function (string $method, string $url) use (&$asked): MockResponse {
            $asked[] = $url;
            return new MockResponse('', ['http_code' => 302, 'primary_ip' => self::PUBLIC_IP, 'redirect_url' => 'http://169.254.169.254/latest/meta-data/', 'response_headers' => ['Location' => 'http://169.254.169.254/latest/meta-data/']]);
        }));

        try {
            $client->request('GET', 'http://' . self::PUBLIC_IP . '/page');
            self::fail('the redirect was followed');
        } catch (TransportException) {
            self::assertSame(['http://' . self::PUBLIC_IP . '/page'], $asked);
        }
    }

    /** the name was public when checked, the connection landed elsewhere (a DNS answer that changed) */
    public function test_publicOnly_refusesAConnectionThatLandsOnAPrivateAddress(): void
    {
        $client = Client::publicOnly(transport: new MockHttpClient([new MockResponse('secret', ['primary_ip' => '10.0.0.7'])]));

        $this->expectException(TransportException::class);
        $client->request('GET', 'http://' . self::PUBLIC_IP . '/');
    }

    public function test_publicOnly_followsAPublicRedirect(): void
    {
        $client = Client::publicOnly(redirects: 3, transport: new MockHttpClient([
            new MockResponse('', ['http_code' => 301, 'primary_ip' => self::PUBLIC_IP, 'redirect_url' => 'http://' . self::PUBLIC_IP . '/new', 'response_headers' => ['Location' => '/new']]),
            new MockResponse('<html>hello</html>', ['primary_ip' => self::PUBLIC_IP]),
        ]));

        $answer = $client->request('GET', 'http://' . self::PUBLIC_IP . '/old');
        self::assertSame([200, '<html>hello</html>'], [$answer->status, $answer->body], 'a public redirect is followed as asked');
    }

    /** the guard follows redirects itself - it must still stop where the caller said */
    public function test_publicOnly_followsNoMoreRedirectsThanAsked(): void
    {
        $asked = 0;
        $client = Client::publicOnly(redirects: 2, transport: new MockHttpClient(static function () use (&$asked): MockResponse {
            $asked++;
            return new MockResponse('', ['http_code' => 302, 'primary_ip' => self::PUBLIC_IP, 'redirect_url' => 'http://' . self::PUBLIC_IP . "/hop$asked"]);
        }));

        $answer = $client->request('GET', 'http://' . self::PUBLIC_IP . '/');
        self::assertSame([302, 3], [$answer->status, $asked], 'the first request and two hops');
        self::assertSame(302, (Client::publicOnly(transport: new MockHttpClient([new MockResponse('', ['http_code' => 302, 'primary_ip' => self::PUBLIC_IP, 'redirect_url' => 'http://' . self::PUBLIC_IP . '/x'])])))->request('GET', 'http://' . self::PUBLIC_IP . '/')->status, 'none by default');
    }

    public function test_publicOnly_onlyWebAddresses(): void
    {
        $client = Client::publicOnly(transport: new MockHttpClient([new MockResponse('x')]));

        $this->expectException(TransportException::class);
        $client->request('GET', 'ftp://' . self::PUBLIC_IP . '/file');
    }

    // a public address literal: the guard resolves host names, and the documentation ranges count as reserved
    public function test_publicOnly_passesAPublicAddress(): void
    {
        $client = Client::publicOnly(transport: new MockHttpClient(new MockResponse('page')));
        self::assertSame('page', $client->request('GET', 'https://1.1.1.1/')->body);
    }

    // a proxy from the server's environment would resolve the name again, after the guard checked it
    public function test_publicOnly_neverGoesThroughAProxy(): void
    {
        $options = [];
        $client  = Client::publicOnly(transport: new MockHttpClient(function (string $method, string $url, array $sent) use (&$options): MockResponse {
            $options = $sent;
            return new MockResponse('page');
        }));

        $client->request('GET', 'https://1.1.1.1/');

        self::assertSame('*', $options['no_proxy'] ?? null);
    }

    // the policy of the client still applies under the guard
    public function test_publicOnly_keepsTheSizeCeiling(): void
    {
        $this->expectException(ResponseTooLargeException::class);
        Client::publicOnly(transport: new MockHttpClient(new MockResponse(str_repeat('x', 11))), maxBytes: 10)->request('GET', 'https://1.1.1.1/');
    }

    public function test_publicOnly_keepsTheIdleLimit(): void
    {
        $slow = Client::publicOnly(idle: 1, maxBytes: 10, transport: new MockHttpClient([new MockResponse(['xx', ''], ['primary_ip' => self::PUBLIC_IP])]));

        $this->expectException(TransportException::class);
        $slow->request('GET', 'http://' . self::PUBLIC_IP . '/stall');
    }

    /** @param array{?string, ?string, ?string} $expected local_cert, local_pk, passphrase */
    #[DataProvider('certificates')]
    public function test_request_clientCertificate(?ClientCertificate $certificate, array $expected): void
    {
        $seen   = null;
        $client = new Client(certificate: $certificate, http: new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$options['local_cert'] ?? null, $options['local_pk'] ?? null, $options['passphrase'] ?? null];
            return new MockResponse('{}');
        }));

        $client->request('POST', 'https://example.org/x', 'a');

        self::assertSame($expected, $seen);
    }

    public static function certificates(): array
    {
        return [
            'none'              => [null, [null, null, null]],
            // never null: OpenSSL would ask for it on stdin
            'key in the file'   => [new ClientCertificate('/etc/aa/client.pem'), ['/etc/aa/client.pem', null, '']],
            'key apart'         => [new ClientCertificate('/etc/aa/client.pem', '/etc/aa/client.key'), ['/etc/aa/client.pem', '/etc/aa/client.key', '']],
            'encrypted key'     => [new ClientCertificate('/etc/aa/client.pem', '/etc/aa/client.key', new Secret('Pass-phrase')), ['/etc/aa/client.pem', '/etc/aa/client.key', 'Pass-phrase']],
            'empty passphrase'  => [new ClientCertificate('/etc/aa/client.pem', passphrase: new Secret('')), ['/etc/aa/client.pem', null, '']],
        ];
    }

    // a certificate adds to the policy, it never loosens it
    // the certificate is added to the request, the rest stays: the answer limit, TLS checks, fresh connections, no redirects
    public function test_request_certificateKeepsThePolicy(): void
    {
        $seen   = [];
        $client = new Client(maxBytes: 10, reuse: false, certificate: new ClientCertificate('/etc/aa/client.pem'), http: new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;
            return new MockResponse(str_repeat('x', 11));
        }));

        try {
            $client->request('POST', 'https://example.org/x', 'a');
            self::fail('ResponseTooLargeException expected');
        } catch (ResponseTooLargeException) {
        }
        self::assertTrue($seen['verify_peer'] ?? null);
        self::assertTrue($seen['verify_host'] ?? null);
        self::assertSame([\CURLOPT_FRESH_CONNECT => true, \CURLOPT_FORBID_REUSE => true], $seen['extra']['curl'] ?? null);
        self::assertSame(0, $seen['max_redirects'] ?? null);
    }

    public function test_request_passphraseNeverShown(): void
    {
        $passphrase = 'Pass-phrase-never-shown';
        $client     = new Client(certificate: new ClientCertificate('/etc/aa/client.pem', passphrase: new Secret($passphrase)), http: new MockHttpClient(
            static fn(): MockResponse => new MockResponse('', ['error' => 'could not load PEM client certificate from /etc/aa/client.pem']),
        ));
        ob_start();
        var_dump($client);
        $dumps = (string) ob_get_clean() . print_r($client, true) . var_export($client, true);
        try {
            $client->request('GET', 'https://example.org/x');
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            $dumps .= $e->getMessage() . ($e->getPrevious()?->getMessage() ?? '');
        }

        self::assertStringNotContainsString($passphrase, $dumps);
    }

    public function test_theExcerptIsShortEnoughForALogLine(): void
    {
        $client = new Client(http: new MockHttpClient([new MockResponse(str_repeat('x', 5000), ['http_code' => 500])]));

        self::assertSame(200, strlen($client->request('GET', 'https://example.org/x')->excerpt()));
    }
}
