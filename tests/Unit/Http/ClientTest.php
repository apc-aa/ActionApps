<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use AA\Http\Client;
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

    public function test_aBodyThatIsNotJsonIsRefusedRatherThanGuessed(): void
    {
        $client = new Client(http: new MockHttpClient([new MockResponse('<html>gateway</html>')]));

        $this->expectException(\JsonException::class);
        $client->request('GET', 'https://example.org/x')->json();
    }

    public function test_theExcerptIsShortEnoughForALogLine(): void
    {
        $client = new Client(http: new MockHttpClient([new MockResponse(str_repeat('x', 5000), ['http_code' => 500])]));

        self::assertSame(200, strlen($client->request('GET', 'https://example.org/x')->excerpt()));
    }
}
