<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckDiscussionRefusals;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckDiscussionRefusalsTest extends TestCase
{
    private const SLICE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_no_refusals_is_ok(): void
    {
        $findings = CheckDiscussionRefusals::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_a_refused_slice_is_a_warning_named_by_the_slice(): void
    {
        $row = ['selector' => self::SLICE, 'days' => '3', 'first' => '1790000000', 'last' => '1790086400', 'params' => 'no discussion view'];

        [$finding] = CheckDiscussionRefusals::findingsFor([$row], [self::SLICE => 'News']);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('News', $finding->title);
        $this->assertSame(3, $finding->count);
        $this->assertSame(self::SLICE, $finding->key);
    }

    public function test_an_unknown_slice_is_named_by_its_id(): void
    {
        $row = ['selector' => self::SLICE, 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckDiscussionRefusals::findingsFor([$row], []);

        $this->assertStringContainsString(self::SLICE, $finding->title);
    }
}
