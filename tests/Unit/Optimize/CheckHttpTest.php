<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Http\Response;
use AA\Http\TransportException;
use AA\Optimize\CheckHttp;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\FakeHttp;

/**
 * A server that cannot fetch over HTTP fails far away from the cause: {include} of a local page
 * renders empty, Central goes quiet, the link checker calls everything dead. This check is what
 * turns those three mysteries into one sentence.
 */
final class CheckHttpTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // AA_INSTAL_URL, _m()
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
    }

    private static function finding(Response $answer): Finding
    {
        $findings = (new CheckHttp(new FakeHttp([$answer])))->findings();

        self::assertCount(1, $findings, 'the check says exactly one thing about this server');

        return $findings[0];
    }

    public function test_aServerThatReachesItselfIsFine(): void
    {
        $finding = self::finding(new Response(200, '<html>AA</html>'));

        self::assertSame(Finding::OK, $finding->severity);
        self::assertFalse($finding->isProblem());
    }

    public function test_noAnswerAtAllIsAnErrorWithTheReasonAndWhatToTry(): void
    {
        $silent = new class implements \AA\Http\ClientInterface {
            public function request(string $method, string $url, ?string $body = null, array $headers = []): Response
            {
                throw new TransportException('No answer from ' . $url . ' - Could not resolve host');
            }
        };

        $findings = (new CheckHttp($silent))->findings();

        self::assertSame(Finding::ERROR, $findings[0]->severity);
        self::assertStringContainsString('Could not resolve host', (string) json_encode($findings[0]->evidence));
        self::assertStringContainsString('firewall', $findings[0]->advice, 'the reader is told what to look at');
    }

    public function test_anAddressThatRedirectsIsWorthFixingBeforeItCostsASession(): void
    {
        $finding = self::finding(new Response(301, 'Moved', [], 'https://example.org/aa/'));

        self::assertSame(Finding::WARNING, $finding->severity);
        self::assertStringContainsString('https://example.org/aa/', $finding->title);
        self::assertSame('redirect', $finding->key);
    }

    public function test_anAddressThatLeadsSomewhereElseIsReported(): void
    {
        $finding = self::finding(new Response(404, 'not here'));

        self::assertSame(Finding::WARNING, $finding->severity);
        self::assertSame('status', $finding->key);
    }

    // Apache with no index page and no directory listing answers 403 to the bare AA directory
    public function test_aDirectoryWithoutAnIndexPageIsFineWhenAFileUnderItAnswers(): void
    {
        $http     = new FakeHttp([new Response(403, 'Forbidden'), new Response(200, 'icon', ['content-type' => 'image/x-icon'])]);
        $findings = (new CheckHttp($http))->findings();

        self::assertSame(Finding::OK, $findings[0]->severity);
        self::assertStringEndsWith('/images/favicon.ico', $http->calls[1]['url']);
        self::assertStringContainsString('403', (string) json_encode($findings[0]->evidence), 'the note says what the address itself answered');
        self::assertStringContainsString('favicon.ico', (string) json_encode($findings[0]->evidence), 'and which address answered 200');
    }

    public function test_aFileThatDoesNotAnswerEitherLeavesTheWarning(): void
    {
        $http     = new FakeHttp([new Response(403, 'Forbidden'), new Response(404, 'not here')]);
        $findings = (new CheckHttp($http))->findings();

        self::assertSame(Finding::WARNING, $findings[0]->severity);
        self::assertSame('status', $findings[0]->key);
        self::assertCount(2, $http->calls, 'the file was asked for');
    }

    // a front controller that answers every address with a page proves nothing about AA
    public function test_aPageInsteadOfTheFileLeavesTheWarning(): void
    {
        $http = new FakeHttp([new Response(404, 'not here'), new Response(200, '<html>', ['content-type' => 'text/html'])]);

        self::assertSame(Finding::WARNING, (new CheckHttp($http))->findings()[0]->severity);
    }

    public function test_aFileThatCannotBeFetchedLeavesTheWarning(): void
    {
        $http = new class implements \AA\Http\ClientInterface {
            private int $calls = 0;

            public function request(string $method, string $url, ?string $body = null, array $headers = []): Response
            {
                return (++$this->calls === 1) ? new Response(403, 'Forbidden') : throw new TransportException('No answer from ' . $url);
            }
        };

        self::assertSame(Finding::WARNING, (new CheckHttp($http))->findings()[0]->severity);
    }

    // a server error is not a missing index page - the file answering does not excuse it
    public function test_aServerErrorIsReportedEvenWhenAFileAnswers(): void
    {
        $http = new FakeHttp([new Response(500, 'boom'), new Response(200, 'icon', ['content-type' => 'image/x-icon'])]);

        self::assertSame(Finding::WARNING, (new CheckHttp($http))->findings()[0]->severity);
        self::assertCount(1, $http->calls);
    }

    public function test_itIsAnExtendedCheckBecauseItMakesARealRequest(): void
    {
        self::assertSame([CheckHttp::CHECK_EXTENDED], CheckHttp::types());
        self::assertSame('environment', CheckHttp::category());
    }
}
