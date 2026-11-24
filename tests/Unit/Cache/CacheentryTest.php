<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use AA\Cache\Cacheentry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Conditional requests (RFC 9110 §13): when a page answers 304 instead of its body; and who may keep the page */
final class CacheentryTest extends TestCase
{
    private const HASH    = '0123456789abcdef0123456789abcdef';
    private const LM      = 1_800_000_000;
    private const LM_DATE = 'Fri, 15 Jan 2027 08:00:00 GMT';

    public static function requests(): array
    {
        $h = self::HASH;
        return [
            'no condition'                => ['GET', null, null, false],
            'quoted etag'                 => ['GET', "\"$h\"", null, true],
            'weak etag, weak comparison'  => ['GET', "W/\"$h\"", null, true],
            'etag in a list'              => ['GET', "\"other\", W/\"$h\"", null, true],
            'unquoted etag is no etag'    => ['GET', $h, null, false],
            'star'                        => ['GET', '*', null, true],
            'other etag'                  => ['GET', '"other"', null, false],
            'etag as a substring'         => ['GET', "\"{$h}0\"", null, false],
            'HEAD'                        => ['HEAD', "\"$h\"", null, true],
            'POST ran already'            => ['POST', "\"$h\"", null, false],
            'POST ignores the date too'   => ['POST', null, self::LM_DATE, false],
            'same date'                   => ['GET', null, self::LM_DATE, true],
            'later date'                  => ['GET', null, 'Fri, 15 Jan 2027 08:00:01 GMT', false],
            'earlier date'                => ['GET', null, 'Fri, 15 Jan 2027 07:59:59 GMT', false],
            'invalid date'                => ['GET', null, 'garbage date', false],
            'etag beats the date (miss)'  => ['GET', '"other"', self::LM_DATE, false],
            'etag beats the date (match)' => ['GET', "\"$h\"", 'Fri, 15 Jan 2027 07:00:00 GMT', true],
        ];
    }

    #[DataProvider('requests')]
    public function test_notModified_request(string $method, ?string $if_none_match, ?string $if_modified_since, bool $expected): void
    {
        $server = array_filter(['REQUEST_METHOD' => $method, 'HTTP_IF_NONE_MATCH' => $if_none_match, 'HTTP_IF_MODIFIED_SINCE' => $if_modified_since], static fn($v) => $v !== null);
        $this->assertSame($expected, Cacheentry::notModified([], $server, self::HASH, self::LM));
    }

    public static function statuses(): array
    {
        return [
            'default' => [[], true],
            '200'     => [['status' => 'HTTP/1.1 200 OK'], true],
            '201'     => [['status' => 'HTTP/1.1 201 Created'], true],
            '404'     => [['status' => 'HTTP/1.0 404 Not Found'], false],
            '410'     => [['status' => 'HTTP/1.1 410 Gone'], false],
            '304 set by the template' => [['status' => 'HTTP/1.1 304 Not Modified'], true],
        ];
    }

    /** a 404 page is never "not modified"; {header:304} always is */
    #[DataProvider('statuses')]
    public function test_notModified_pageStatus(array $headers, bool $expected): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'HTTP_IF_NONE_MATCH' => '"' . self::HASH . '"'];
        $this->assertSame($expected, Cacheentry::notModified($headers, $server, self::HASH, self::LM));
    }

    public static function responses(): array
    {
        return [
            'visitor without a session'  => [false, ['Content-Type: text/html'], 'no-cache'],
            'nothing sent yet'           => [false, [], 'no-cache'],
            'open session'               => [true, [], 'private, no-cache'],
            'cookie being set'           => [false, ['Content-Type: text/html', 'Set-Cookie: lang=cz; path=/'], 'private, no-cache'],
            'header names are caseless'  => [false, ['set-cookie: lang=cz'], 'private, no-cache'],
            'cookie only inside a value' => [false, ['X-Note: Set-Cookie: no'], 'no-cache'],
        ];
    }

    /** every copy is checked with the server before reuse; a response that sets a cookie is one visitor's */
    #[DataProvider('responses')]
    public function test_cacheControl_response(bool $session, array $sent, string $expected): void
    {
        $this->assertSame($expected, Cacheentry::cacheControl($session, $sent));
    }

    /** the page cache holds entries stored with the cacheability an earlier version kept in the entry */
    public function test_unserialize_entryWithCacheability(): void
    {
        $stored = "O:19:\"AA\\Cache\\Cacheentry\":5:{s:4:\"\0*\0c\";s:4:\"body\";s:4:\"\0*\0h\";a:1:{s:4:\"type\";s:38:\"Content-Type: text/html; charset=utf-8\";}"
            . "s:4:\"\0*\0i\";s:6:\"itemid\";s:4:\"\0*\0p\";s:7:\"private\";s:4:\"\0*\0t\";i:1800000000;}";
        // a property the class does not declare would be reported on every page served from the cache
        set_error_handler(static function (int $no, string $msg): bool {
            throw new \ErrorException($msg, 0, $no);
        });
        try {
            $entry = unserialize($stored, ['allowed_classes' => [Cacheentry::class]]);
        } finally {
            restore_error_handler();
        }
        $this->assertInstanceOf(Cacheentry::class, $entry);
    }
}
