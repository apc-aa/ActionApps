<?php

declare(strict_types=1);

namespace Tests\Unit\IO;

use AA\Http\TransportException;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The one rule turning an HTTP status into "ask again later" or "refused for good" */
final class SourceFailureTest extends TestCase
{
    /** @param ?class-string<SourceFailure> $class */
    #[DataProvider('statuses')]
    public function test_forStatus_mapsStatus(int $status, ?string $class, string $reason, string $detail = ''): void
    {
        $failure = SourceFailure::forStatus($status, "HTTP $status from example.org");

        if (is_null($class)) {
            self::assertNull($failure);
            return;
        }
        self::assertInstanceOf($class, $failure);
        self::assertSame($reason, $failure->reason);
        self::assertSame("HTTP $status from example.org", $failure->getMessage());
        self::assertSame($detail, $failure->detail);
    }

    public static function statuses(): array
    {
        return [
            '200'               => [200, null, ''],
            '204'               => [204, null, ''],
            '299'               => [299, null, ''],
            '500'               => [500, SourceUnavailable::class, 'TRANSPORT', 'http:500'],
            '503'               => [503, SourceUnavailable::class, 'TRANSPORT', 'http:503'],
            '599'               => [599, SourceUnavailable::class, 'TRANSPORT', 'http:599'],
            '429'               => [429, SourceUnavailable::class, 'TRANSPORT', 'http:429'],
            '401'               => [401, SourceRefused::class, 'AUTH', 'http:401'],
            '403'               => [403, SourceRefused::class, 'AUTH', 'http:403'],
            '400'               => [400, SourceRefused::class, 'SOURCE', 'http:400'],
            '404'               => [404, SourceRefused::class, 'SOURCE', 'http:404'],
            '410'               => [410, SourceRefused::class, 'SOURCE', 'http:410'],
            'unfollowed 3xx'    => [302, SourceRefused::class, 'SOURCE', 'http:302'],
            'informational 1xx' => [100, SourceRefused::class, 'SOURCE', 'http:100'],
            'out of range'      => [99, SourceRefused::class, 'SOURCE', ''],
        ];
    }

    // the detail is stored and shown as it is: only the closed set, never a text that could carry a URL
    #[DataProvider('badDetails')]
    public function test_construct_rejectsDetailOutsideTheSet(string $detail): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SourceRefused('SOURCE', 'HTTP 404', $detail);
    }

    public static function badDetails(): array
    {
        return [
            'a URL'           => ['https://data.example/x?key=s3cret'],
            'a status phrase' => ['Not Found'],
            'a bare status'   => ['404'],
            'status too big'  => ['http:600'],
            'an unknown word' => ['dns2'],
        ];
    }

    #[DataProvider('goodDetails')]
    public function test_construct_keepsDetailOfTheSet(string $detail): void
    {
        self::assertSame($detail, (new SourceUnavailable('TRANSPORT', 'No answer', $detail))->detail);
    }

    public static function goodDetails(): array
    {
        return array_map(static fn(string $d): array => [$d], array_combine(
            ['none', 'dns', 'timeout', 'tls', 'refused', 'too_large', 'redirects', 'address', 'status'],
            ['', 'dns', 'timeout', 'tls', 'refused', 'too_large', 'redirects', 'address', 'http:503'],
        ));
    }

    public function test_detailOf_bySourceFailureOrTransport(): void
    {
        self::assertSame('http:410', SourceFailure::detailOf(SourceFailure::forStatus(410, 'HTTP 410') ?? throw new \LogicException()));
        self::assertSame('dns', SourceFailure::detailOf(new TransportException('No answer', 0, new \RuntimeException('Could not resolve host: data.example'))));
        self::assertSame('', SourceFailure::detailOf(new \RuntimeException('https://data.example/?key=s3cret')));
    }
}
