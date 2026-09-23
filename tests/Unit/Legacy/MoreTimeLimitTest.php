<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/** MoreTimeLimit() may only raise the limit - set_time_limit() on an unlimited script (0, the CLI
 *  and many long-running setups) would put a limit on it. */
final class MoreTimeLimitTest extends TestCase
{
    private string $saved;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    protected function setUp(): void
    {
        $this->saved = (string) ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        set_time_limit((int) $this->saved);
    }

    public function test_unlimited_stays_unlimited(): void
    {
        set_time_limit(0);

        MoreTimeLimit(360);

        $this->assertSame('0', ini_get('max_execution_time'));
    }

    public function test_lower_limit_is_raised(): void
    {
        set_time_limit(30);

        MoreTimeLimit(360);

        $this->assertSame('360', ini_get('max_execution_time'));
    }

    public function test_higher_limit_is_kept(): void
    {
        set_time_limit(900);

        MoreTimeLimit(360);

        $this->assertSame('900', ini_get('max_execution_time'));
    }
}
