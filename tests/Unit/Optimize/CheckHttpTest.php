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

    public function test_itIsAnExtendedCheckBecauseItMakesARealRequest(): void
    {
        self::assertSame([CheckHttp::CHECK_EXTENDED], CheckHttp::types());
        self::assertSame('environment', CheckHttp::category());
    }
}
