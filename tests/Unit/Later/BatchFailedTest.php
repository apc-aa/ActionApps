<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\BatchFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A run stores and shows only an error code and a place - never an exception text,
 *  which may carry source data or a secret. */
final class BatchFailedTest extends TestCase
{
    public function test_construct_rejects_lowercase_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchFailed('source down');
    }

    public function test_construct_keeps_reason_and_where(): void
    {
        $e = new BatchFailed('DEFINITION', 'stream:csu/job:3');

        $this->assertSame('DEFINITION', $e->reason);
        $this->assertSame('stream:csu/job:3', $e->where);
        $this->assertSame('DEFINITION', $e->getMessage(), 'the message is the code only, a log never gets source text from it');
    }

    /** @return array<string, array{string, bool}> */
    public static function codes(): array
    {
        return [
            '64 characters'   => ['A' . str_repeat('B', 63), true],
            '65 characters'   => ['A' . str_repeat('B', 64), false],
            'one letter'      => ['X', true],
            'digits after'    => ['HTTP_503', true],
            'leading digit'   => ['5XX', false],
            'leading _'       => ['_X', false],
            'empty'           => ['', false],
            'trailing newline' => ["TRANSPORT\n", false],
        ];
    }

    #[DataProvider('codes')]
    public function test_isCode_bounds(string $code, bool $valid): void
    {
        $this->assertSame($valid, BatchFailed::isCode($code));
    }
}
