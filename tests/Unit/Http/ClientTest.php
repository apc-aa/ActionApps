<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use AA\Http\Client;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * AA's HTTP door. The library does the work; what is worth pinning is the part we decided:
 * a status that is not 200 is an answer, not an exception, and no answer at all is.
 */
final class ClientTest extends TestCase
{
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

    public function test_noAnswerAtAllIsAnExceptionWorthRetrying(): void
    {
        $client = new Client(http: new MockHttpClient(static fn(): MockResponse => new MockResponse('', ['error' => 'Connection refused'])));

        $this->expectException(TransportException::class);
        $client->request('GET', 'https://example.org/x');
    }

    public function test_aBodyOverTheLimitIsRefusedByItsDeclaredLength(): void
    {
        $client = new Client(maxBytes: 10, http: new MockHttpClient([
            new MockResponse(str_repeat('x', 11), ['response_headers' => ['Content-Length' => '11']]),
        ]));

        $this->expectException(ResponseTooLargeException::class);
        $client->request('GET', 'https://example.org/big');
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

    public function test_theExcerptIsShortEnoughForALogLine(): void
    {
        $client = new Client(http: new MockHttpClient([new MockResponse(str_repeat('x', 5000), ['http_code' => 500])]));

        self::assertSame(200, strlen($client->request('GET', 'https://example.org/x')->excerpt()));
    }
}
