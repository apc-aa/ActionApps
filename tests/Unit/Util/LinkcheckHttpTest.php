<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Http\Response;
use AA\Http\TransportException;
use AA\Util\Linkcheck;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\FakeHttp;

/**
 * The link checker walks a redirect chain itself, one hop at a time, because it reports every hop:
 * "200, but after four hops" is a different answer to an editor than a plain 200. A client that
 * follows redirects on its own would hand back only the last hop's headers.
 */
final class LinkcheckHttpTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
    }

    public function test_aLinkThatAnswersIsReportedWithItsTypeAndCharset(): void
    {
        $http   = new FakeHttp([new Response(200, '', ['content-type' => 'text/html; charset=utf-8'])]);
        $result = (new Linkcheck($http))->check_url('https://example.org/page');

        self::assertSame('200', $result['code']);
        self::assertSame('text/html', $result['mime']);
        self::assertSame('utf-8', $result['charset']);
        self::assertSame([], $result['redir_chain'], 'nothing moved');
        self::assertSame('HEAD', $http->calls[0]['method'], 'a link check does not download the page');
    }

    public function test_aServerThatRefusesHeadIsAskedAgainWithGet(): void
    {
        $http = new FakeHttp([
            new Response(405, ''),
            new Response(200, '', ['content-type' => 'text/html']),
        ]);

        $result = (new Linkcheck($http))->check_url('https://example.org/page');

        self::assertSame('200', $result['code']);
        self::assertSame(['HEAD', 'GET'], array_column($http->calls, 'method'));
    }

    public function test_everyHopOfARedirectIsRecordedAndTheFirstCodeIsTheAnswer(): void
    {
        $http = new FakeHttp([
            new Response(301, '', [], 'https://example.org/new'),
            new Response(200, '', ['content-type' => 'text/html']),
        ]);

        $result = (new Linkcheck($http))->check_url('https://example.org/old');

        self::assertSame('301', $result['code'], 'the editor is told the link moved, not that it works');
        self::assertSame(['https://example.org/new'], $result['redir_chain']);
        self::assertSame(['301', '200'], $result['http_codes']);
        self::assertSame('https://example.org/new', $result['redir']);
        self::assertStringEndsWith('Redirected [200]', (string) $result['comment']);
        self::assertSame(['https://example.org/old', 'https://example.org/new'], array_column($http->calls, 'url'), 'the second hop is fetched, not guessed');
    }

    /**
     * A chain that never ends is a dead link, not the 302 its first hop answered with. Reporting
     * the 302 would file weight 1 into links_links.valid_rank - the same rank as a link that
     * moved once and works.
     */
    public function test_anEndlessRedirectIsADeadLinkNotAHealthyOne(): void
    {
        $http = new FakeHttp([new Response(302, '', [], 'https://example.org/loop')]);

        $result = (new Linkcheck($http))->check_url('https://example.org/loop');

        self::assertCount(11, $http->calls, 'ten hops and the one that started it');
        self::assertSame('999', $result['code']);
        self::assertSame(5, $result['weight'], 'as bad as a host that never answered');
        self::assertStringContainsString('more than 10 redirects', (string) $result['error']);
        self::assertCount(10, $result['redir_chain'], 'where it went before we gave up is still worth showing');
    }

    public function test_aLinkThatAnswersNothingIsNotMistakenForABadPage(): void
    {
        $silent = new class implements \AA\Http\ClientInterface {
            public function request(string $method, string $url, ?string $body = null, array $headers = []): Response
            {
                throw new TransportException('No answer from ' . $url . ' - Could not resolve host');
            }
        };

        $result = (new Linkcheck($silent))->check_url('https://nonexistent.example/page');

        self::assertSame('999', $result['code']);
        self::assertSame(5, $result['weight']);
        self::assertStringContainsString('Could not resolve host', (string) $result['error']);
    }

    public function test_somethingThatIsNotAWebAddressIsNotFetchedAtAll(): void
    {
        $http   = new FakeHttp([new Response(200, '')]);
        $result = (new Linkcheck($http))->check_url('ftp://example.org/file');

        self::assertSame('N/A', $result['code']);
        self::assertSame([], $http->calls);
    }
}
