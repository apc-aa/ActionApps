<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use AA\Cache\Cacheentry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Conditional requests (RFC 9110 §13): when a page answers 304 instead of its body */
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
}
