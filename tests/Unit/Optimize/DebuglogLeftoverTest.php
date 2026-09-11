<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\DebuglogLeftover;
use PHPUnit\Framework\TestCase;

/** snippet() is the only DB-free piece; the LIKE scans need a live schema. */
final class DebuglogLeftoverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // safe()
    }

    public function test_match_is_marked_and_context_escaped(): void
    {
        $this->assertSame(
            '&lt;b&gt;<mark>{debuglog:x:2026-09-15}</mark>&lt;/b&gt;',
            DebuglogLeftover::snippet('<b>{debuglog:x:2026-09-15}</b>'),
        );
    }

    public function test_long_code_is_cut_on_both_sides(): void
    {
        $code = str_repeat('a', 100) . '{debuglog:y}' . str_repeat('b', 100);
        $out  = DebuglogLeftover::snippet($code, 10);
        $this->assertSame('…' . str_repeat('a', 10) . '<mark>{debuglog:y}</mark>' . str_repeat('b', 10) . '…', $out);
    }

    public function test_unclosed_expression_runs_to_the_end(): void
    {
        $this->assertSame('x<mark>{debuglog:open</mark>', DebuglogLeftover::snippet('x{debuglog:open'));
    }
}
