<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\BatchStepResult;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidUtf8(): array
    {
        return [
            'label'                => [['label' => "stream \xC3"]],
            'count name'           => [['counts' => ["read\xFF" => 1]]],
            'nested warning name'  => [['warnings' => ['W' => ["\xC3(" => 1]]]],
        ];
    }

    /** the end of the step stores them as JSON - invalid UTF-8 must fail in work(), not in that transaction */
    #[DataProvider('invalidUtf8')]
    public function test_construct_rejects_invalid_utf8(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchStepResult([], false, ...$args);
    }

    public function test_construct_accepts_utf8_label_and_names(): void
    {
        $r = new BatchStepResult([], false, ['přečteno' => 1], ['Ž' => ['kód' => 2]], 'proud Ústí');
        $this->assertSame('proud Ústí', $r->label);
    }
}
