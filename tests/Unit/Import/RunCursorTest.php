<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\RunCursor;
use Tests\Unit\Import\Fixtures\ScriptedSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/ScriptedSource.php';

final class RunCursorTest extends TestCase
{
    public function test_fromPosition_typesOfStartAndNothingElse(): void
    {
        $pos = RunCursor::fromPosition(['i' => '2', 'job' => 7, 'url' => 'https://x', 'pages' => null]);

        self::assertSame(array_keys(RunCursor::START), array_keys($pos), 'a stored position cannot add a field');
        self::assertSame([2, '7', 0], [$pos['i'], $pos['job'], $pos['pages']]);
    }

    public function test_nextStream_keepsOnlyTheStreamIndexTheCheckAndTheRunsKey(): void
    {
        $pos = ['i' => 3, 'job' => 's4:b', 'pages' => 9, 'wrote' => 1, 'failed' => 1, 'after' => 's3', 'checked' => 1, 'keyed' => 1, 'nokey' => 2, 'rkeyed' => 1] + RunCursor::START;

        self::assertSame(['i' => 4, 'checked' => 1, 'rkeyed' => 1] + RunCursor::START, RunCursor::nextStream($pos));
    }

    public function test_cycles_repeatedJobOrTooManyPages(): void
    {
        $pos = RunCursor::started(RunCursor::START, 's1:a');

        self::assertTrue(RunCursor::cycles($pos, 's1:a'));
        self::assertFalse(RunCursor::cycles($pos, 's1:b'));
        self::assertTrue(RunCursor::cycles(['pages' => RunCursor::MAX_PAGES] + $pos, 's1:b'));
        self::assertFalse(RunCursor::cycles(['pages' => RunCursor::MAX_PAGES] + $pos, 's1:b', RunCursor::MAX_PAGES + 1), 'a source may declare more jobs');
        self::assertTrue(RunCursor::cycles(['pages' => 5] + $pos, 's1:b', 5));
    }

    public function test_jobLimit_ofTheSourceWithinBounds(): void
    {
        $source = new ScriptedSource();

        self::assertSame(RunCursor::MAX_PAGES, RunCursor::jobLimit($source));
        $source->maxJobs = 10000;
        self::assertSame(10000, RunCursor::jobLimit($source));
        $source->maxJobs = PHP_INT_MAX;
        self::assertSame(RunCursor::MAX_JOBS, RunCursor::jobLimit($source));
        $source->maxJobs = 0;
        self::assertSame(1, RunCursor::jobLimit($source));
    }

    public function test_skippedJob_countsTheSkipsInARow(): void
    {
        $next = RunCursor::started(RunCursor::START, 's1:b');

        self::assertSame(['skips' => 1] + $next, RunCursor::skippedJob(RunCursor::START, $next));
        self::assertSame(['skips' => 2] + $next, RunCursor::skippedJob(['skips' => 1] + RunCursor::START, $next));
    }

    public function test_endsTheStream_atTheCap(): void
    {
        $pos = RunCursor::started(RunCursor::START, 's1:b');

        self::assertFalse(RunCursor::endsTheStream(['skips' => RunCursor::SKIPS_IN_A_ROW - 2] + $pos));
        self::assertTrue(RunCursor::endsTheStream(['skips' => RunCursor::SKIPS_IN_A_ROW - 1] + $pos));
        self::assertFalse(RunCursor::endsTheStream(['skips' => RunCursor::SKIPS_IN_A_ROW - 1, 'job' => ''] + $pos), 'no job: the stream failed before its first one');
    }

    /** @return array<string, array{string, string, string}> */
    public static function whereCases(): array
    {
        return [
            'job named by its stream' => ['s1', 's1:p2', 's1:p2'],
            'job of its own name'     => ['s1', 'p2', 's1 p2'],
            'no job'                  => ['s1', '', 's1'],
            'odd characters'          => ['s1', "p<2>\nx", 's1 p_2__x'],
        ];
    }

    #[DataProvider('whereCases')]
    public function test_where_shape(string $stream, string $job, string $expected): void
    {
        self::assertSame($expected, RunCursor::where($stream, $job, null));
    }

    public function test_where_cutTo200Bytes(): void
    {
        self::assertSame(200, strlen(RunCursor::where(str_repeat('s', 300), '', null)));
    }
}
