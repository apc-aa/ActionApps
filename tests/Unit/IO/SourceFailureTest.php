<?php

declare(strict_types=1);

namespace Tests\Unit\IO;

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
    public function test_forStatus_mapsStatus(int $status, ?string $class, string $reason): void
    {
        $failure = SourceFailure::forStatus($status, "HTTP $status from example.org");

        if (is_null($class)) {
            self::assertNull($failure);
            return;
        }
        self::assertInstanceOf($class, $failure);
        self::assertSame($reason, $failure->reason);
        self::assertSame("HTTP $status from example.org", $failure->getMessage());
    }

    public static function statuses(): array
    {
        return [
            '200'               => [200, null, ''],
            '204'               => [204, null, ''],
            '299'               => [299, null, ''],
            '500'               => [500, SourceUnavailable::class, 'TRANSPORT'],
            '503'               => [503, SourceUnavailable::class, 'TRANSPORT'],
            '599'               => [599, SourceUnavailable::class, 'TRANSPORT'],
            '429'               => [429, SourceUnavailable::class, 'TRANSPORT'],
            '401'               => [401, SourceRefused::class, 'AUTH'],
            '403'               => [403, SourceRefused::class, 'AUTH'],
            '400'               => [400, SourceRefused::class, 'SOURCE'],
            '404'               => [404, SourceRefused::class, 'SOURCE'],
            '410'               => [410, SourceRefused::class, 'SOURCE'],
            'unfollowed 3xx'    => [302, SourceRefused::class, 'SOURCE'],
            'informational 1xx' => [100, SourceRefused::class, 'SOURCE'],
        ];
    }
}
