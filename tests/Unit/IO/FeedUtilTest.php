<?php

declare(strict_types=1);

namespace Tests\Unit\IO;

use AA\IO\FeedUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FeedUtil::http_fetch() reads feed sources set in the admin (rssfeeds.server_url,
 * nodes.server_url). Anything but an http(s) address must not reach the file
 * reader, which would open local files and php:// streams as well.
 */
final class FeedUtilTest extends TestCase
{
    #[DataProvider('nonWebSources')]
    public function test_httpFetch_nonWebSource_returnsEmptyWithoutReading(string $source): void
    {
        $this->assertSame('', FeedUtil::http_fetch($source, ['node_name' => 'x']));
    }

    public static function nonWebSources(): array
    {
        return [
            'empty'           => [''],
            'whitespace only' => ["  \t"],
            'absolute path'   => [__FILE__],
            'relative path'   => ['tests/Unit/IO/FeedUtilTest.php'],
            'file scheme'     => ['file://' . __FILE__],
            'php stream'      => ['php://filter/resource=' . __FILE__],
            'ftp'             => ['ftp://example.org/feed.xml'],
            'scheme-relative' => ['//example.org/feed.xml'],
        ];
    }
}
