<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckUploadRefusals;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckUploadRefusalsTest extends TestCase
{
    private const SLICE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    /** @return array<string, ?string> */
    private function row(string $reason, int $days = 2): array
    {
        return ['selector' => ParamImplode([self::SLICE, $reason]), 'days' => (string) $days, 'first' => '1790000000', 'last' => '1790086400', 'params' => '192.0.2.1'];
    }

    public function test_no_refusals_is_ok(): void
    {
        $findings = CheckUploadRefusals::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_refused_types_are_warnings_named_by_slice(): void
    {
        [$finding] = CheckUploadRefusals::findingsFor([$this->row('type html')], [self::SLICE => 'News']);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('News', $finding->title);
        $this->assertStringContainsString('type html', $finding->title);
        $this->assertSame(2, $finding->count);
    }

    public function test_unwritable_directory_is_an_error(): void
    {
        [$finding] = CheckUploadRefusals::findingsFor([$this->row('dir')], []);
        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString(self::SLICE, $finding->title);
    }
}
