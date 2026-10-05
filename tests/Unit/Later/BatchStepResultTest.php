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

    /** @return array<string, array{array<string, mixed>}> */
    public static function badShapes(): array
    {
        $deep = 1;
        for ($i = 0; $i < 16; ++$i) {
            $deep = [$deep];
        }
        return [
            'counts three levels deep' => [['counts' => ['jobs' => ['ok' => ['a' => 1]]]]],
            'a count that is a string' => [['counts' => ['read' => '3']]],
            'a float warning'          => [['warnings' => ['W' => ['x' => 1.5]]]],
            'a float in the position'  => [['position' => ['n' => 1.5]]],
            'position 17 levels deep'  => [['position' => ['d' => $deep]]],
        ];
    }

    /** the end of the step adds and stores them - a bad shape must fail in work(), not in that transaction */
    #[DataProvider('badShapes')]
    public function test_construct_rejects_bad_shape(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchStepResult(...($args + ['position' => [], 'finished' => false]));
    }

    public function test_construct_accepts_position_16_levels_deep(): void
    {
        $deep = 1;
        for ($i = 0; $i < 15; ++$i) {
            $deep = [$deep];
        }
        $this->assertSame(['d' => $deep], (new BatchStepResult(['d' => $deep], false))->position);
    }

    public function test_withPosition_keeps_the_rest_and_clears_retry(): void
    {
        $r = new BatchStepResult(['n' => 1], false, ['read' => 1], ['W' => 2], 'task 4', 1, 9, 'TRANSPORT', ['task' => 4]);
        $n = $r->withPosition(['n' => 2]);

        $this->assertSame(['n' => 2], $n->position);
        $this->assertSame('', $n->retry);
        $this->assertSame([false, ['read' => 1], ['W' => 2], 'task 4', 1, 9, ['task' => 4]], [$n->finished, $n->counts, $n->warnings, $n->label, $n->done, $n->total, $n->extra]);
    }

    public function test_construct_accepts_utf8_label_and_names(): void
    {
        $r = new BatchStepResult([], false, ['přečteno' => 1], ['Ž' => ['kód' => 2]], 'proud Ústí');
        $this->assertSame('proud Ústí', $r->label);
    }
}
