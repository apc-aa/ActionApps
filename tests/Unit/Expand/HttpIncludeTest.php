<?php

declare(strict_types=1);

namespace Tests\Unit\Expand;

use AA\Expand\HttpInclude;
use AA\Http\Client;
use AA\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\FakeHttp;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The contract {include:...} has with a template, now that it is served by AA\Http\Client.
 *
 * What a template author sees must not change: the page on success, their own $err text on
 * failure with _#status / _#errmsg filled in, and an empty string when they gave no $err - never
 * the word "false" and never a PHP warning in the middle of the page.
 *
 * The old behaviour these assertions came from is pinned in
 * tests/Unit/Legacy/StringexpandIncludeTest.php.
 */
final class HttpIncludeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
    }

    protected function tearDown(): void
    {
        HttpInclude::useHttp(null);
    }

    /** @param list<MockResponse> $answers */
    private static function serving(array $answers): void
    {
        HttpInclude::useHttp(new Client(http: new MockHttpClient($answers)));
    }

    public function test_aPageThatAnsweredIsWhatTheTemplateGets(): void
    {
        self::serving([new MockResponse('<p>the report</p>', ['http_code' => 200])]);

        self::assertSame('<p>the report</p>', HttpInclude::fetch('GET', 'https://example.org/report'));
    }

    public function test_aRefusalFillsInTheTemplatesOwnErrorText(): void
    {
        self::serving([new MockResponse('nothing here', ['http_code' => 404])]);

        $out = HttpInclude::fetch('GET', 'https://example.org/gone', '', [], 'sorry: _#status / _#errmsg');

        self::assertSame('sorry: 404 / HTTP 404', $out);
    }

    /**
     * _#errmsg is substituted into the page a template author wrote, so it must never carry a
     * word the other end chose. A template that passes URL_PARAMETERS on gives a visitor a say
     * in a parameter most APIs echo back in their 400 body - that is a full reflected-XSS chain
     * with no template-author complicity.
     */
    public function test_theRemoteBodyNeverReachesThePage(): void
    {
        self::serving([new MockResponse('<script>alert(document.cookie)</script>', ['http_code' => 400])]);

        $out = HttpInclude::fetch('GET', 'https://api.example/x?q=whatever', '', [], 'sorry: _#errmsg');

        self::assertSame('sorry: HTTP 400', $out);
        self::assertStringNotContainsString('script', $out);
    }

    public function test_anErrorTextWithoutPlaceholdersIsUsedVerbatim(): void
    {
        self::serving([new MockResponse('', ['http_code' => 500])]);

        self::assertSame('the report is unavailable', HttpInclude::fetch('GET', 'https://example.org/x', '', [], 'the report is unavailable'));
    }

    public function test_withoutAnErrorTextAFailureRendersNothing(): void
    {
        self::serving([new MockResponse('', ['http_code' => 503])]);

        self::assertSame('', HttpInclude::fetch('GET', 'https://example.org/x'), 'a template must never render an error it did not ask for');
    }

    public function test_noAnswerAtAllHasNoStatusToReport(): void
    {
        self::serving([new MockResponse('', ['error' => 'Connection refused'])]);

        $out = HttpInclude::fetch('GET', 'https://example.org/x', '', [], 'status:_#status msg:_#errmsg');

        self::assertStringStartsWith('status: msg:', $out, 'there was no HTTP conversation, so there is no code');
        self::assertStringContainsString('No answer from', $out);
    }

    public function test_aBodyGoesAsAFormUnlessTheTemplateSaidOtherwise(): void
    {
        $http = new FakeHttp([new Response(200, 'ok')]);
        HttpInclude::useHttp($http);

        HttpInclude::fetch('POST', 'https://example.org/x', 'a=1');
        self::assertSame('application/x-www-form-urlencoded', $http->calls[0]['headers']['Content-Type'], 'PHP\'s wrapper did this, and templates rely on it');

        HttpInclude::fetch('POST', 'https://example.org/x', '{"a":1}', ['content-type' => 'application/json']);
        self::assertSame(['content-type' => 'application/json'], $http->calls[1]['headers'], 'whatever the template set is left alone, whatever its case');
    }

    public function test_aBodylessRequestSendsNoBodyAtAll(): void
    {
        $http = new FakeHttp([new Response(200, 'ok')]);
        HttpInclude::useHttp($http);

        HttpInclude::fetch('GET', 'https://example.org/x');

        self::assertSame('GET', $http->calls[0]['method']);
        self::assertNull($http->calls[0]['body']);
        self::assertSame([], $http->calls[0]['headers'], 'nothing to declare when there is no body');
    }

    /** {file2text:} pulls whole documents down this path; 30 s is a page's budget, not a download's */
    public function test_aDownloadMayTakeLongerThanAPageRenderWouldWaitFor(): void
    {
        $slow = new class implements \AA\Http\ClientInterface {
            public array $calls = [];
            public function request(string $method, string $url, ?string $body = null, array $headers = []): \AA\Http\Response
            {
                return new \AA\Http\Response(200, 'the pdf');
            }
        };
        HttpInclude::useHttp($slow);

        self::assertSame('the pdf', HttpInclude::fetch('GET', 'https://example.org/big.pdf', '', [], '', HttpInclude::DOWNLOAD_SECONDS));
        self::assertGreaterThan(30, HttpInclude::DOWNLOAD_SECONDS, 'a slow 40 MB pdf is not an error');
    }

    public function test_aHeadRequestAnswersWithNothingAndThatIsNotAFailure(): void
    {
        self::serving([new MockResponse('', ['http_code' => 200])]);

        self::assertSame('', HttpInclude::fetch('HEAD', 'https://example.org/x', '', [], 'should not be used'));
    }

    /**
     * Only a 2xx is the page. The fetch does follow redirects in production (http() asks for ten,
     * and ClientTest proves that number reaches the library) - this pins the other half: whatever
     * arrives unfollowed must not be rendered into someone's site as if it were the page.
     */
    public function test_aRedirectIsNeverMistakenForThePage(): void
    {
        self::serving([new MockResponse('<h1>Moved Permanently</h1>', ['http_code' => 301, 'redirect_url' => 'https://example.org/new'])]);

        self::assertSame('', HttpInclude::fetch('GET', 'https://example.org/old'), 'only a 2xx is the page');
    }
}
