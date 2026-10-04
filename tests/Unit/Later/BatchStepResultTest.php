<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\BatchStepResult;
use PHPUnit\Framework\TestCase;

/** retry ends up as the run's error code once the retries are spent - the same rule as BatchFailed. */
final class BatchStepResultTest extends TestCase
{
    public function test_construct_keeps_defaults(): void
    {
        $r = new BatchStepResult(['job' => 1], false);

        $this->assertSame(['job' => 1], $r->position);
        $this->assertFalse($r->finished);
        $this->assertSame('', $r->retry);
        $this->assertSame([], $r->counts);
        $this->assertSame([], $r->extra);
    }

    public function test_construct_accepts_retry_code(): void
    {
        $this->assertSame('SOURCE_UNSTABLE', (new BatchStepResult([], false, retry: 'SOURCE_UNSTABLE'))->retry);
    }

    public function test_construct_rejects_retry_that_is_not_a_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchStepResult([], false, retry: 'Connection refused');
    }
}
