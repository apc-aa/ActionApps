<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Facts about the request that is being served, which is why these two never belonged to the
 * outgoing HTTP client. X-Forwarded-Proto is not read here: TrustedProxy turns the header of a
 * trusted proxy into HTTPS, from anyone else it means nothing.
 *
 * Why it matters: makeProtocolCompatible() decides whether a preview frame loads at all. Get
 * isHttps() wrong behind a proxy and the browser refuses the http:// frame on an https:// page.
 * isTrackingParam() decides what the page cache leaves out of its key: a tracker it misses stores
 * a copy of the page per visit, a site parameter it takes for a tracker serves the wrong page.
 */
final class UrlTest extends TestCase
{
    private array $server_backup = [];

    protected function setUp(): void
    {
        $this->server_backup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server_backup;
    }

    /** @return array<string, array{array<string, string|int>, bool}> */
    public static function requests(): array
    {
        return [
            'plain http'                 => [[], false],
            'HTTPS=on'                   => [['HTTPS' => 'on'], true],
            'HTTPS=off means off'        => [['HTTPS' => 'off'], false],
            'HTTPS=OFF, any case'        => [['HTTPS' => 'OFF'], false],
            'IIS sends HTTPS=1'          => [['HTTPS' => '1'], true],
            'port 443 alone is enough'   => [['SERVER_PORT' => 443], true],
            'port 443 as a string'       => [['SERVER_PORT' => '443'], true],
            'port 80'                    => [['SERVER_PORT' => 80], false],
            'X-Forwarded-Proto alone is not trusted' => [['HTTP_X_FORWARDED_PROTO' => 'https'], false],
        ];
    }

    /** @param array<string, string|int> $server */
    #[DataProvider('requests')]
    public function test_whetherThePageIsBeingServedOverHttps(array $server, bool $expected): void
    {
        self::assertSame($expected, self::isHttps($server));
    }

    public function test_anHttpUrlIsLiftedToHttpsOnlyOnAnHttpsPage(): void
    {
        self::withServer(['HTTPS' => 'on']);
        self::assertSame('https://example.org/a', Url::makeProtocolCompatible('http://example.org/a'));

        self::withServer([]);
        self::assertSame('http://example.org/a', Url::makeProtocolCompatible('http://example.org/a'), 'an http page may keep its http frames');
    }

    public function test_everythingThatIsNotAnHttpUrlIsLeftAlone(): void
    {
        self::withServer(['HTTPS' => 'on']);

        foreach (['https://example.org/a', '/relative/path', '//example.org/a', 'ftp://example.org/a', ''] as $url) {
            self::assertSame($url, Url::makeProtocolCompatible($url));
        }
    }

    /** @param array<string, string|int> $server */
    private static function isHttps(array $server): bool
    {
        self::withServer($server);

        return Url::isHttps();
    }

    /** @param array<string, string|int> $server */
    private static function withServer(array $server): void
    {
        foreach (['HTTPS', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER = array_merge($_SERVER, $server);
    }

    /** @return array<string, array{string}> */
    public static function trackingParams(): array
    {
        return [
            'utm prefix'                  => ['utm_source'],
            'utm prefix, any suffix'      => ['utm_anything_at_all'],
            'gclid'                       => ['gclid'],
            'fbclid'                      => ['fbclid'],
            'Google search results'       => ['srsltid'],
            'Google Analytics linker'     => ['_ga'],
            'Google Analytics 4 linker'   => ['_gl'],
            'Mailchimp'                   => ['mc_cid'],
            'HubSpot'                     => ['_hsenc'],
        ];
    }

    #[DataProvider('trackingParams')]
    public function test_isTrackingParam_knowsTheTrackers(string $name): void
    {
        self::assertTrue(Url::isTrackingParam($name));
    }

    /** @return array<string, array{string}> */
    public static function ownParams(): array
    {
        return [
            // case-sensitive: trackers use one spelling, a site's own parameter may differ only in case
            'UTM_SOURCE in capitals'       => ['UTM_SOURCE'],
            'Gclid'                        => ['Gclid'],
            // AA's own parameters
            'vid'                          => ['vid'],
            'listlen'                      => ['listlen'],
            'mlx'                          => ['mlx'],
            'cmd'                          => ['cmd'],
            'set'                          => ['set'],
            'utm without the underscore'   => ['utmsomething'],
            // ambiguous names a site may well use for itself
            'ref'                          => ['ref'],
            'source'                       => ['source'],
            'referrer'                     => ['referrer'],
            'gclient_custom'               => ['gclient_custom'],
            // DoubleClick cookie names are not URL trackers - PageCache skips them as cookies only
            'NID'                          => ['NID'],
            'IDE'                          => ['IDE'],
        ];
    }

    #[DataProvider('ownParams')]
    public function test_isTrackingParam_leavesOtherNamesAlone(string $name): void
    {
        self::assertFalse(Url::isTrackingParam($name));
    }
}
