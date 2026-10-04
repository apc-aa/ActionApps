<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\BatchPosition;
use PHPUnit\Framework\TestCase;

/**
 * A step made progress only when its position changed in meaning: positions travel
 * through JSON in object_text, so key order and "5" vs 5 must not look like progress,
 * and the queue row's fingerprint must not change with them either.
 */
final class BatchPositionTest extends TestCase
{
    public function test_same_ignores_key_order(): void
    {
        $a = ['stream' => 's1', 'job' => ['offset' => 10, 'page' => 2]];
        $b = ['job' => ['page' => 2, 'offset' => 10], 'stream' => 's1'];

        $this->assertTrue(BatchPosition::same($a, $b), 'nested keys too');
    }

    public function test_same_string_and_int_equal(): void
    {
        $this->assertTrue(BatchPosition::same(['n' => '5'], ['n' => 5]));
    }

    public function test_same_false_and_zero_equal(): void
    {
        $this->assertTrue(BatchPosition::same(['done' => false], ['done' => 0]));
        $this->assertTrue(BatchPosition::same(['done' => true], ['done' => '1']));
    }

    public function test_same_null_and_empty_string_equal(): void
    {
        $this->assertTrue(BatchPosition::same(['next' => null], ['next' => '']));
    }

    public function test_same_different_values_differ(): void
    {
        $this->assertFalse(BatchPosition::same(['n' => 5], ['n' => 6]));
        $this->assertFalse(BatchPosition::same(['n' => 5], ['m' => 5]), 'a key is part of the meaning');
        $this->assertFalse(BatchPosition::same(['n' => 5], ['n' => 5, 'm' => 1]));
        $this->assertFalse(BatchPosition::same(['n' => []], ['n' => '']), 'an empty list is not an empty value');
        $this->assertFalse(BatchPosition::same(['a', 'b'], ['b', 'a']), 'list order is meaning');
    }

    public function test_canonical_rejects_objects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BatchPosition::canonical(['at' => new \DateTimeImmutable('2026-01-01')]);
    }

    public function test_canonical_rejects_floats(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BatchPosition::canonical(['share' => ['of' => 0.1 + 0.2]]);
    }

    public function test_canonical_rejects_invalid_utf8(): void
    {
        $this->expectException(\JsonException::class);
        BatchPosition::canonical(['page' => "\xC3\x28"]);
    }

    public function test_same_long_list_keeps_order(): void
    {
        $list = range(1, 12);
        $swapped = $list;
        [$swapped[9], $swapped[10]] = [$swapped[10], $swapped[9]];

        $this->assertTrue(BatchPosition::same($list, array_map('strval', $list)), 'keys 10 and 11 sort as strings, the same way on both sides');
        $this->assertFalse(BatchPosition::same($list, $swapped));
    }

    public function test_same_survives_storage_round_trip(): void
    {
        $position = [
            'streams' => ['csu', 'nzis'],
            'job'     => ['n' => 7, 'next' => null, 'restarted' => false, 'cursor' => ['page' => '3', 'ids' => [10, 2]]],
            'closing' => true,
        ];

        $this->assertTrue(BatchPosition::same($position, json_decode(json_encode($position, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)));
    }

    public function test_fingerprint_depends_on_step(): void
    {
        $position = ['job' => 3];

        $this->assertNotSame(BatchPosition::fingerprint(4, $position), BatchPosition::fingerprint(5, $position));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', BatchPosition::fingerprint(4, $position));
    }

    public function test_fingerprint_stable_across_key_order(): void
    {
        $this->assertSame(
            BatchPosition::fingerprint(7, ['a' => 1, 'b' => ['x' => null, 'y' => '2']]),
            BatchPosition::fingerprint(7, ['b' => ['y' => 2, 'x' => ''], 'a' => '1']),
        );
    }
}
