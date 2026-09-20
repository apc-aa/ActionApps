<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * What {include:...} puts on the wire today, pinned before it moves onto AA\Http\Client.
 *
 * Templates on live installs depend on every one of these details: the server name prefixed to a
 * bare path, the visitor's cookies forwarded to a local URL (that is how an included page knows
 * who is reading it), URL_PARAMETERS substituted, and the error text handed back instead of the
 * page. Whatever the rewrite changes, it has to change deliberately - so it is written down here
 * first, including the one thing that is plainly a bug.
 *
 * include/stringexpand.php cannot be loaded on its own (perm_core, formutil, a session and a
 * database), so the class and the two functions are extracted from the real file and evaluated
 * without their parent, in the same way tests/Unit/Legacy/ImgCacheUrlTest.php does it. AA_Http is
 * renamed on the way in, so what is recorded is exactly what the expander asked the transport for.
 */
final class StringexpandIncludeTest extends TestCase
{
    private const CLS = 'AA_Test_Stringexpand_Include';

    public static function setUpBeforeClass(): void
    {
        if (class_exists(self::CLS, false)) {
            return;
        }
        $source = (string) file_get_contents(__DIR__ . '/../../../include/stringexpand.php');

        // the stand-ins for what stringexpand.php would otherwise pull the whole application in for
        eval('
            class AA_Test_Http {
                public static array $calls = [];
                public static string|false $answer = "the page";
                public static function request($url, $data = [], $headers = [], $method = "", $err = "") {
                    self::$calls[] = ["url" => $url, "data" => $data, "headers" => $headers, "method" => $method, "err" => $err];
                    return self::$answer;
                }
                public static function lastErrMsg(): string { return "transport said no"; }
            }
            abstract class AA_Test_Stringexpand { public $itemview = null; }
        ');
        // the three whose real bodies would drag the application in are renamed on the way in, so
        // they cannot clash with include/util.php if another test file loaded it first
        eval('
            function aa_test_self_server() { return "https://example.org"; }
            function aa_test_shtml_query_string() { return "a=1&b=2"; }
            function aa_test_warn($msg) { $GLOBALS["aa_test_warnings"][] = $msg; }
        ');
        // these three keep their names - whichever definition is already loaded does the same job
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
            'AA_Http::'                   => 'AA_Test_Http::',
            'self_server()'               => 'aa_test_self_server()',
            'shtml_query_string()'        => 'aa_test_shtml_query_string()',
            'warn('                       => 'aa_test_warn(',
            'class AA_Stringexpand_Include extends AA_Stringexpand' => 'class AA_Test_Stringexpand_Include extends AA_Test_Stringexpand',
            'expandFilenameWithHttp('     => 'aa_test_expandFilenameWithHttp(',
        ];
        eval(strtr(self::extract($source, T_CLASS, 'AA_Stringexpand_Include'), $rename));
        eval(strtr(self::extract($source, T_FUNCTION, 'expandFilenameWithHttp'), $rename));
        if (!function_exists('json2asoc')) {   // another test may have loaded the real file already
            eval(self::extract($source, T_FUNCTION, 'json2asoc'));
        }
    }

    protected function setUp(): void
    {
        \AA_Test_Http::$calls  = [];
        \AA_Test_Http::$answer = 'the page';
        $_COOKIE                     = [];
        $GLOBALS['aa_test_warnings'] = [];
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

    /** @return array<string, mixed> */
    private static function lastCall(): array
    {
        self::assertNotEmpty(\AA_Test_Http::$calls, 'nothing was fetched at all');

        return \AA_Test_Http::$calls[array_key_last(\AA_Test_Http::$calls)];
    }

    public function test_nothingToIncludeFetchesNothing(): void
    {
        self::assertSame('', self::expand(''));
        self::assertSame([], \AA_Test_Http::$calls);
    }

    public function test_anExplicitVerbGoesStraightToTheTransport(): void
    {
        self::assertSame('the page', self::expand('https://example.org/x', 'PUT', '{"X-Key":"k"}', 'body', 'oops'));

        self::assertSame(
            ['url' => 'https://example.org/x', 'data' => 'body', 'headers' => ['X-Key' => 'k'], 'method' => 'PUT', 'err' => 'oops'],
            self::lastCall(),
        );
    }

    public function test_aBarePathIsAskedOfThisServer(): void
    {
        self::expand('reports/daily.shtml');
        self::assertSame('https://example.org/reports/daily.shtml', self::lastCall()['url'], 'a slash is added where it is missing');

        self::expand('/reports/daily.shtml');
        self::assertSame('https://example.org/reports/daily.shtml', self::lastCall()['url']);
    }

    public function test_theVisitorsCookiesGoAlongToOurOwnServerOnly(): void
    {
        $_COOKIE = ['AA_Session' => 'abc', 'lang' => 'cs'];

        self::expand('/reports/daily.shtml');
        self::assertSame('AA_Session=abc; lang=cs', self::lastCall()['headers']['Cookie'], 'an included page has to know who is reading it');

        self::expand('https://elsewhere.example/x');
        self::assertArrayNotHasKey('Cookie', self::lastCall()['headers'], 'a stranger never sees our session');

        self::expand('/reports/daily.shtml', 'http', '{"Cookie":"mine=1"}');
        self::assertSame('mine=1', self::lastCall()['headers']['Cookie'], 'a cookie the template set is left alone');
    }

    public function test_anAjaxIncludeSaysSo(): void
    {
        self::expand('/reports/daily.shtml', 'ajax');

        self::assertSame('XMLHttpRequest', self::lastCall()['headers']['X-Requested-With']);
    }

    public function test_theQueryStringOfThisPageCanBePassedOn(): void
    {
        self::expand('/reports/daily.shtml?URL_PARAMETERS');

        self::assertSame('https://example.org/reports/daily.shtml?a=1&b=2', self::lastCall()['url']);
    }

    public function test_noAnswerLeavesTheTemplateWithNothingRatherThanFalse(): void
    {
        \AA_Test_Http::$answer = false;

        self::assertSame('', self::expand('/reports/daily.shtml'), 'a template must never render the word "false"');
    }

    public function test_aFailedVerbIncludeWarnsTheEditor(): void
    {
        \AA_Test_Http::$answer = false;

        self::assertSame('', self::expand('https://example.org/x', 'GET'));
        self::assertSame(['transport said no'], $GLOBALS['aa_test_warnings']);
    }

    /**
     * The accident, pinned so the fix is on the record: expandFilenameWithHttp() hands the
     * transport an empty method, and AA_Http turns an empty method plus any header into a POST.
     * Since a local include always gets a Cookie header when the visitor has cookies, every
     * {include:/local/path} rendered for a logged-in visitor is a POST today. Nobody asked for
     * that, and AA\Expand\HttpInclude will send GET.
     */
    public function test_accident_aLocalIncludeSendsAnEmptyMethodWhichBecomesAPost(): void
    {
        $_COOKIE = ['AA_Session' => 'abc'];

        self::expand('/reports/daily.shtml');

        self::assertSame('', self::lastCall()['method'], 'the expander says nothing, and AA_Http reads that as POST');
        self::assertArrayHasKey('Cookie', self::lastCall()['headers'], 'which is what makes it a POST');
    }
}
