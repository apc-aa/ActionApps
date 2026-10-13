<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Expand\HttpInclude;
use AA\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\FakeHttp;

/**
 * What {include:...} puts on the wire.
 *
 * Templates on live installs depend on every one of these details: the server name prefixed to a
 * bare path, the visitor's cookies forwarded to a local URL (that is how an included page knows
 * who is reading it), URL_PARAMETERS substituted, and the error text handed back instead of the
 * page. Written against AA_Http first so it pinned the old behaviour, then moved onto
 * AA\Expand\HttpInclude - the one assertion that changed is the method, and it changed because
 * the old one was a bug (see the last test).
 *
 * include/stringexpand.php cannot be loaded on its own (perm_core, formutil, a session and a
 * database), so the class and the two functions are extracted from the real file and evaluated
 * without their parent, in the same way tests/Unit/Legacy/ImgCacheUrlTest.php does it.
 */
final class StringexpandIncludeTest extends TestCase
{
    private const CLS = 'AA_Test_Stringexpand_Include';

    private FakeHttp $http;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
        if (class_exists(self::CLS, false)) {
            return;
        }
        $source = (string) file_get_contents(__DIR__ . '/../../../include/stringexpand.php');

        eval('abstract class AA_Test_Stringexpand { public $itemview = null; }');
        // the two whose real bodies would drag the application in are renamed on the way in, so
        // they cannot clash with include/util.php if another test file loaded it first
        eval('
            function aa_test_self_server() { return "https://example.org"; }
            function aa_test_shtml_query_string() { return "a=1&b=2"; }
        ');
        // these keep their names - whichever definition is already loaded does the same job
        foreach ([
            'DeBackslash'    => 'return $a;',
            'aa_json_decode' => 'return json_decode($a, true);',
            'json2arr'       => 'return json_decode($a, true);',
        ] as $name => $body) {
            if (!function_exists($name)) {
                eval("function {$name}(\$a = null, \$b = null) { {$body} }");
            }
        }

        $rename = [
            // the extracted snippet has no `use` line of its own
            'HttpInclude::'           => '\AA\Expand\HttpInclude::',
            'PageCache::'             => '\AA\Cache\PageCache::',
            'self_server()'           => 'aa_test_self_server()',
            'shtml_query_string()'    => 'aa_test_shtml_query_string()',
            'class AA_Stringexpand_Include extends AA_Stringexpand' => 'class AA_Test_Stringexpand_Include extends AA_Test_Stringexpand',
            'expandFilenameWithHttp(' => 'aa_test_expandFilenameWithHttp(',
        ];
        eval(strtr(self::extract($source, T_CLASS, 'AA_Stringexpand_Include'), $rename));
        eval(strtr(self::extract($source, T_FUNCTION, 'expandFilenameWithHttp'), $rename));
        if (!function_exists('json2asoc')) {   // another test may have loaded the real file already
            eval(self::extract($source, T_FUNCTION, 'json2asoc'));
        }
    }

    protected function setUp(): void
    {
        // each test is a request of its own - forget the cookies read by the previous one
        (new \ReflectionProperty(\AA\Cache\PageCache::class, 'cookies_read'))->setValue(null, []);
        $_COOKIE    = [];
        $this->http = new FakeHttp([new Response(200, 'the page')]);
        HttpInclude::useHttp($this->http);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(\AA\Cache\PageCache::class, 'cookies_read'))->setValue(null, []);
        HttpInclude::useHttp(null);
    }

    /** Source of one class or function, from its keyword to the closing brace (comments/strings safe) */
    private static function extract(string $source, int $keyword, string $name): string
    {
        $tokens = token_get_all($source);
        $start  = null;
        $depth  = 0;
        $out    = '';
        foreach ($tokens as $i => $token) {
            if (is_null($start)) {
                if (is_array($token) && $token[0] === $keyword && ($tokens[$i + 2][1] ?? '') === $name) {
                    $start = $i;
                } else {
                    continue;
                }
            }
            $text = is_array($token) ? $token[1] : $token;
            $out .= $text;
            if ($text === '{') {
                $depth++;
            } elseif ($text === '}' && --$depth === 0) {
                return $out;
            }
        }
        self::fail("$name not found in include/stringexpand.php");
    }

    private static function expand(string $fn, string $type = '', string $headers = '', string $data = '', string $err = ''): string
    {
        $cls = self::CLS;

        return (new $cls())->expand($fn, $type, $headers, $data, $err);
    }

    /** @return array{method: string, url: string, body: ?string, headers: array<string, string>} */
    private function lastCall(): array
    {
        self::assertNotEmpty($this->http->calls, 'nothing was fetched at all');

        return $this->http->calls[array_key_last($this->http->calls)];
    }

    public function test_nothingToIncludeFetchesNothing(): void
    {
        self::assertSame('', self::expand(''));
        self::assertSame([], $this->http->calls);
    }

    public function test_anExplicitVerbGoesStraightToTheTransport(): void
    {
        self::assertSame('the page', self::expand('https://example.org/x', 'PUT', '{"X-Key":"k"}', 'body', 'oops'));

        $sent = $this->lastCall();
        self::assertSame('PUT', $sent['method']);
        self::assertSame('https://example.org/x', $sent['url']);
        self::assertSame('body', $sent['body']);
        self::assertSame('k', $sent['headers']['X-Key']);
    }

    public function test_aBarePathIsAskedOfThisServer(): void
    {
        self::expand('reports/daily.shtml');
        self::assertSame('https://example.org/reports/daily.shtml', $this->lastCall()['url'], 'a slash is added where it is missing');

        self::expand('/reports/daily.shtml');
        self::assertSame('https://example.org/reports/daily.shtml', $this->lastCall()['url']);
    }

    public function test_theVisitorsCookiesGoAlongToOurOwnServerOnly(): void
    {
        $_COOKIE = ['AA_Session' => 'abc', 'lang' => 'cs'];

        self::expand('/reports/daily.shtml');
        self::assertSame('AA_Session=abc; lang=cs', $this->lastCall()['headers']['Cookie'], 'an included page has to know who is reading it');

        self::expand('https://elsewhere.example/x');
        self::assertArrayNotHasKey('Cookie', $this->lastCall()['headers'], 'a stranger never sees our session');

        self::expand('/reports/daily.shtml', 'http', '{"Cookie":"mine=1"}');
        self::assertSame('mine=1', $this->lastCall()['headers']['Cookie'], 'a cookie the template set is left alone');
    }

    public function test_anAjaxIncludeSaysSo(): void
    {
        self::expand('/reports/daily.shtml', 'ajax');

        self::assertSame('XMLHttpRequest', $this->lastCall()['headers']['X-Requested-With']);
    }

    public function test_theQueryStringOfThisPageCanBePassedOn(): void
    {
        self::expand('/reports/daily.shtml?URL_PARAMETERS');

        self::assertSame('https://example.org/reports/daily.shtml?a=1&b=2', $this->lastCall()['url']);
    }

    public function test_noAnswerLeavesTheTemplateWithNothingRatherThanFalse(): void
    {
        HttpInclude::useHttp(new FakeHttp([new Response(500, 'server on fire')]));

        self::assertSame('', self::expand('/reports/daily.shtml'), 'a template must never render the word "false"');
    }

    /**
     * The fix, and the only assertion that changed with the move. The old path handed the
     * transport an empty method, and AA_Http read an empty method plus any header as POST. A local
     * include always gets a Cookie header when the visitor has cookies, so every
     * {include:/local/path} rendered for a logged-in visitor used to be a POST nobody asked for.
     */
    public function test_aLocalIncludeIsAGetNowThatAHeaderNoLongerForcesAPost(): void
    {
        $_COOKIE = ['AA_Session' => 'abc'];

        self::expand('/reports/daily.shtml');

        self::assertSame('GET', $this->lastCall()['method']);
        self::assertArrayHasKey('Cookie', $this->lastCall()['headers'], 'the header that used to turn this into a POST is still sent');
    }

    public function test_anIncludeWithABodyIsStillAPost(): void
    {
        self::expand('/reports/daily.shtml', 'http', '', 'q=kombucha');

        self::assertSame('POST', $this->lastCall()['method']);
        self::assertSame('q=kombucha', $this->lastCall()['body']);
    }
}
