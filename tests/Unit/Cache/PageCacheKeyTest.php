<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use AA\Cache\PageCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the tracker-stripping logic that keeps third-party
 * marketing parameters (utm_*, gclid, fbclid, ...) and analytics cookies
 * (IDE, NID, ...) out of the page-cache key.
 *
 * All helpers under test are pure; no DB or session is touched. We snapshot
 * & restore the superglobals we mutate so test order is irrelevant.
 */
final class PageCacheKeyTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedServer = [];

    /** @var array<string,mixed> */
    private array $savedCookie = [];

    /** @var array<string,mixed> */
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        $this->savedServer  = $_SERVER;
        $this->savedCookie  = $_COOKIE;
        $this->savedGlobals = [
            'apc_state' => $GLOBALS['apc_state'] ?? null,
            'als'       => $GLOBALS['als'] ?? null,
            'slice_pwd' => $GLOBALS['slice_pwd'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_COOKIE = $this->savedCookie;
        foreach ($this->savedGlobals as $k => $v) {
            if ($v === null) {
                unset($GLOBALS[$k]);
            } else {
                $GLOBALS[$k] = $v;
            }
        }
    }

    // -- isIgnoredParam --------------------------------------------------

    public static function ignoredParamProvider(): array
    {
        return [
            'utm prefix lowercase'    => ['utm_source', true],
            'utm prefix any suffix'   => ['utm_anything_at_all', true],
            'gclid exact'             => ['gclid', true],
            'fbclid exact'            => ['fbclid', true],
            'mc_cid exact'            => ['mc_cid', true],
            '_hsenc exact'            => ['_hsenc', true],
            'IDE exact (DoubleClick)' => ['IDE', true],
            'NID exact'               => ['NID', true],
            '1P_JAR exact'            => ['1P_JAR', true],
        ];
    }

    #[DataProvider('ignoredParamProvider')]
    public function test_isIgnoredParam_recognizes_trackers(string $name, bool $expected): void
    {
        $this->assertSame($expected, PageCache::isIgnoredParam($name));
    }

    public static function nonIgnoredParamProvider(): array
    {
        return [
            // case-sensitive: canonical lowercase utm_/gclid only
            'UTM_SOURCE wrong case'        => ['UTM_SOURCE'],
            'Gclid wrong case'             => ['Gclid'],
            'ide wrong case'               => ['ide'],
            // AA-consumed params must stay
            'vid'                          => ['vid'],
            'listlen'                      => ['listlen'],
            'mlx'                          => ['mlx'],
            'cmd'                          => ['cmd'],
            'set'                          => ['set'],
            // utm without underscore — must NOT match the utm_ prefix
            'utmsomething (no underscore)' => ['utmsomething'],
            // ambiguous names deliberately excluded — custom site safety
            'ref'                          => ['ref'],
            'source'                       => ['source'],
            'referrer'                     => ['referrer'],
            // custom site param that happens to start with similar letters
            'gclient_custom'               => ['gclient_custom'],
        ];
    }

    #[DataProvider('nonIgnoredParamProvider')]
    public function test_isIgnoredParam_leaves_other_names_alone(string $name): void
    {
        $this->assertFalse(PageCache::isIgnoredParam($name));
    }

    // -- filterIgnoredParams --------------------------------------------

    public function test_filterIgnoredParams_strips_trackers_keeps_rest(): void
    {
        $in  = ['vid' => '1', 'utm_source' => 'fb', 'gclid' => 'x', 'listlen' => '10'];
        $out = PageCache::filterIgnoredParams($in);
        $this->assertSame(['vid' => '1', 'listlen' => '10'], $out);
    }

    public function test_filterIgnoredParams_no_tracker_returns_input_unchanged(): void
    {
        $in  = ['vid' => '1', 'listlen' => '10', 'mlx' => 'CZ'];
        $out = PageCache::filterIgnoredParams($in);
        $this->assertSame($in, $out);
    }

    public function test_filterIgnoredParams_empty_array(): void
    {
        $this->assertSame([], PageCache::filterIgnoredParams([]));
    }

    public function test_filterIgnoredParams_preserves_non_string_keys(): void
    {
        // PHP coerces numeric string keys to int — those must survive untouched
        $in  = [0 => 'first', 1 => 'second', 'utm_source' => 'drop_me'];
        $out = PageCache::filterIgnoredParams($in);
        $this->assertSame([0 => 'first', 1 => 'second'], $out);
    }

    // -- normalizeQueryString -------------------------------------------

    public function test_normalizeQueryString_strips_trackers(): void
    {
        $this->assertSame(
            'vid=12&listlen=5',
            PageCache::normalizeQueryString('utm_source=a&vid=12&fbclid=x&listlen=5'),
        );
    }

    public function test_normalizeQueryString_preserves_encoding_of_kept_pairs(): void
    {
        // The 'vid' value is left percent-encoded exactly as supplied; we
        // never urldecode + re-encode (would lose original casing/encoding).
        $this->assertSame(
            'vid=12&q=summer%20sale',
            PageCache::normalizeQueryString('vid=12&utm_campaign=summer%20sale&q=summer%20sale'),
        );
    }

    public function test_normalizeQueryString_returns_identity_on_no_op(): void
    {
        $qs   = 'vid=12&listlen=5';
        $out  = PageCache::normalizeQueryString($qs);
        $this->assertSame($qs, $out);
    }

    public function test_normalizeQueryString_empty_string(): void
    {
        $this->assertSame('', PageCache::normalizeQueryString(''));
    }

    public function test_normalizeQueryString_only_trackers_becomes_empty(): void
    {
        $this->assertSame('', PageCache::normalizeQueryString('utm_source=a&gclid=x&fbclid=y'));
    }

    public function test_normalizeQueryString_handles_valueless_pairs(): void
    {
        // 'gclid' with no '=' value is still recognized as the tracker name
        $this->assertSame('vid=1', PageCache::normalizeQueryString('vid=1&gclid'));
    }

    public function test_normalizeQueryString_strips_trailing_ampersand_when_changed(): void
    {
        // The strip of utm_source creates a dangling '&' that must be cleaned
        // up so 'utm_source=a&vid=1' and 'vid=1' both land on the same key.
        $this->assertSame('vid=1', PageCache::normalizeQueryString('utm_source=a&vid=1&'));
    }

    public function test_normalizeQueryString_repeated_tracker_key_drops_all(): void
    {
        $this->assertSame('', PageCache::normalizeQueryString('utm_source=a&utm_source=b'));
    }

    public function test_normalizeQueryString_no_tracker_preserves_trailing_ampersand(): void
    {
        // No tracker present — we MUST NOT touch the string. Identity preserved.
        $qs = 'vid=1&';
        $this->assertSame($qs, PageCache::normalizeQueryString($qs));
    }

    // -- normalizeRequestUri --------------------------------------------

    public function test_normalizeRequestUri_no_query_returns_input(): void
    {
        $uri = '/path/to/page';
        $this->assertSame($uri, PageCache::normalizeRequestUri($uri));
    }

    public function test_normalizeRequestUri_strips_trackers_in_query(): void
    {
        $this->assertSame(
            '/x?vid=1',
            PageCache::normalizeRequestUri('/x?utm_source=fb&vid=1'),
        );
    }

    public function test_normalizeRequestUri_drops_trailing_question_mark_when_empty(): void
    {
        $this->assertSame('/x', PageCache::normalizeRequestUri('/x?utm_source=fb'));
    }

    public function test_normalizeRequestUri_drops_bare_question_mark(): void
    {
        // '/x?' and '/x' must produce identical cache keys.
        $this->assertSame('/x', PageCache::normalizeRequestUri('/x?'));
    }

    public function test_normalizeRequestUri_returns_identity_on_no_op(): void
    {
        $uri = '/x?vid=1&listlen=5';
        $this->assertSame($uri, PageCache::normalizeRequestUri($uri));
    }

    // -- globalKeyArray cookie filter -----------------------------------

    public function test_globalKeyArray_excludes_tracker_and_session_cookies(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.org';
        $_COOKIE = [
            'AA_lang'    => 'cs',        // legit AA cookie — must be in key
            'AA_Session' => 'sess-id',   // session — never in key
            '_ga'        => 'GA1.1.x',   // underscore-prefixed analytics — never in key
            '_fbp'       => 'fb.x',      // underscore-prefixed analytics — never in key
            '__hstc'     => 'hubspot',   // underscore-prefixed analytics — never in key
            'IDE'        => 'dc1',       // DoubleClick — newly excluded
            'NID'        => 'dc2',       // DoubleClick — newly excluded
            '1P_JAR'     => 'dc3',       // DoubleClick — newly excluded
            'DSID'       => 'dc4',       // DoubleClick — newly excluded
            'site_pref'  => 'compact',   // arbitrary custom site cookie — MUST stay
        ];

        $ks = PageCache::globalKeyArray();

        $this->assertSame('cs', $ks['CAA_lang'] ?? null, 'legitimate AA cookie must remain in key');
        $this->assertSame('compact', $ks['Csite_pref'] ?? null, 'custom site cookie must remain in key');

        foreach (['CAA_Session', 'C_ga', 'C_fbp', 'C__hstc', 'CIDE', 'CNID', 'C1P_JAR', 'CDSID'] as $excludedKey) {
            $this->assertArrayNotHasKey($excludedKey, $ks, "$excludedKey must not appear in cache key");
        }
    }

    public function test_globalKeyArray_host_strips_www_prefix(): void
    {
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_COOKIE = [];
        $ks = PageCache::globalKeyArray();
        $this->assertSame('example.org', $ks['host']);
    }

    public function test_globalKeyArray_tolerates_empty_cookie_name(): void
    {
        // Malformed `Cookie: =value` headers can land an empty-string key in
        // $_COOKIE. The loop must skip it without an offset warning.
        $_SERVER['HTTP_HOST'] = 'example.org';
        $_COOKIE = ['' => 'malformed', 'AA_lang' => 'cs'];

        $ks = PageCache::globalKeyArray();                       // no PHP warning expected

        $this->assertSame('cs', $ks['CAA_lang'] ?? null);
        $this->assertArrayNotHasKey('C', $ks, 'empty-name cookie must not become a key');
    }
}
