<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\RunCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunCursorTest extends TestCase
{
    public function test_fromPosition_typesOfStartAndNothingElse(): void
    {
        $pos = RunCursor::fromPosition(['i' => '2', 'job' => 7, 'url' => 'https://x', 'pages' => null]);

        self::assertSame(array_keys(RunCursor::START), array_keys($pos), 'a stored position cannot add a field');
        self::assertSame([2, '7', 0], [$pos['i'], $pos['job'], $pos['pages']]);
    }

    public function test_nextStream_keepsOnlyTheStreamIndexAndTheCheck(): void
    {
        $pos = ['i' => 3, 'job' => 's4:b', 'pages' => 9, 'wrote' => 1, 'failed' => 1, 'after' => 's3', 'checked' => 1] + RunCursor::START;

        self::assertSame(['i' => 4, 'checked' => 1] + RunCursor::START, RunCursor::nextStream($pos));
    }

    public function test_cycles_repeatedJobOrTooManyPages(): void
    {
        $pos = RunCursor::started(RunCursor::START, 's1:a');

        self::assertTrue(RunCursor::cycles($pos, 's1:a'));
        self::assertFalse(RunCursor::cycles($pos, 's1:b'));
        self::assertTrue(RunCursor::cycles(['pages' => RunCursor::MAX_PAGES] + $pos, 's1:b'));
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
