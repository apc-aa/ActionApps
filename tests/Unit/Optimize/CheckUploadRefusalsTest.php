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
        require_once __DIR__ . '/../../../include/logs.php';     // AA_Logentry_Un/Unv - the rows FilesController writes
    }

    /** @return array<string, ?string> */
    private function row(string $reason, string $detail = '', int $days = 2): array
    {
        return ['selector' => (new \AA_Logentry_Un(['slice' => self::SLICE, 'reason' => $reason]))->serializeToString(true), 'days' => (string) $days, 'first' => '1790000000', 'last' => '1790086400',
            'params' => (new \AA_Logentry_Unv(['ip' => '2001:db8::1', 'detail' => $detail]))->serializeToString()];
    }

    public function test_no_refusals_is_ok(): void
    {
        $findings = CheckUploadRefusals::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_refused_types_are_warnings_named_by_slice(): void
    {
        [$finding] = CheckUploadRefusals::findingsFor([$this->row('type', 'html')], [self::SLICE => 'News']);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('News', $finding->title);
        $this->assertStringContainsString('type', $finding->title);
        $this->assertSame(2, $finding->count);
        $this->assertSame('2001:db8::1', $finding->evidence[0][_m('Address')], 'the colons of an IPv6 address come back unescaped');
        $this->assertSame('html', $finding->evidence[0][_m('Detail')]);
    }

    public function test_a_failed_store_is_an_error(): void
    {
        [$finding] = CheckUploadRefusals::findingsFor([$this->row('store', "Can't move /tmp/x")], []);
        $this->assertSame(Finding::ERROR, $finding->severity);
    }

    public function test_unwritable_directory_is_an_error(): void
    {
        [$finding] = CheckUploadRefusals::findingsFor([$this->row('dir')], []);
        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString(self::SLICE, $finding->title);
    }
}
