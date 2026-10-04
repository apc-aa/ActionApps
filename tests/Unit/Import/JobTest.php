<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Job;
use AA\Import\Stream;
use AA\IO\Grabber\AbstractGrabber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    private static function grabber(): AbstractGrabber
    {
        return new class extends AbstractGrabber {};
    }

    public function test_job_paramsOverrideStream(): void
    {
        $stream = new Stream('ind7', 'Indicator 7', ['year' => '2023', 'geo' => 'obec']);
        $job    = new Job('ind7:2024', self::grabber(), ['year' => 2024, 'page' => '2']);

        self::assertSame(['year' => '2024', 'geo' => 'obec', 'page' => '2'], $job->params($stream));
    }

    #[DataProvider('foreignKeys')]
    public function test_job_keyMustContainStreamKey(string $key): void
    {
        $job = new Job($key, self::grabber(), []);

        $this->expectException(InvalidArgumentException::class);
        $job->params(new Stream('ind7', 'Indicator 7', []));
    }

    public static function foreignKeys(): array
    {
        return [
            'other stream'     => ['ind8:2024'],
            'stream in middle' => ['x:ind7:2024'],
            'no separator'     => ['ind70:2024'],
        ];
    }

    // stream "a" with job "a1" would be the same key as stream "a1" with job "a1"
    public function test_job_keyMayBeStreamKey(): void
    {
        self::assertSame(['year' => '2023'], (new Job('ind7', self::grabber(), []))->params(new Stream('ind7', 'Indicator 7', ['year' => '2023'])));
    }

    #[DataProvider('badKeys')]
    public function test_job_rejectsBadKey(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Job($key, self::grabber(), []);
    }

    public static function badKeys(): array
    {
        return [
            'empty'     => [''],
            'space'     => ['ind7 2024'],
            'newline'   => ["ind7:2024\n"],
            'non ascii' => ['ind7:č'],
            'too long'  => ['ind7:' . str_repeat('x', 196)],
        ];
    }

    public function test_job_acceptsLongPrintableKey(): void
    {
        self::assertSame(200, strlen((new Job('ind7:' . str_repeat('?', 195), self::grabber(), []))->key));
    }

    public function test_job_rejectsBadParamName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Job('ind7:1', self::grabber(), ['Page' => '1']);
    }

    public function test_job_keepsGrabberAndUrl(): void
    {
        $grabber = self::grabber();
        $job     = new Job('ind7:1', $grabber, [], 'https://example.org/data?key=***');

        self::assertSame($grabber, $job->grabber);
        self::assertSame('https://example.org/data?key=***', $job->url);
    }
}
