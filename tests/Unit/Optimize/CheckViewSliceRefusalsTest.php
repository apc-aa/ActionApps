<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckViewSliceRefusals;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckViewSliceRefusalsTest extends TestCase
{
    private const SLICE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_findingsFor_noRefusals_isOk(): void
    {
        $findings = CheckViewSliceRefusals::findingsFor([], []);

        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_findingsFor_refusedSlice_isAWarningNamingTheViewAndSlice(): void
    {
        $row = ['selector' => ParamImplode(['42', self::SLICE]), 'days' => '2', 'first' => '1790000000', 'last' => '1790086400', 'params' => 'example.org/news'];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [self::SLICE => 'Members']);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('View 42', $finding->title);
        $this->assertStringContainsString('Members', $finding->title);
        $this->assertSame(2, $finding->count);
        $this->assertSame($row['selector'], $finding->key);
        $this->assertSame('example.org/news', $finding->evidence[0][_m('Page asked for')]);
    }

    /** a bare view.php?vid= over the slice is refused too - the advice must not send the reader after slice parameters only */
    public function test_findingsFor_refusedSlice_adviceNamesTheViewCalledOverTheSlice(): void
    {
        $row = ['selector' => ParamImplode(['42', self::SLICE]), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], []);

        $this->assertStringStartsWith('The view was called from the URL (view.php, jsview.php, discussion.php) over that slice, or its URL parameters', $finding->advice);
    }

    public function test_findingsFor_sliceScript_isNamedAsItIs(): void
    {
        $row = ['selector' => ParamImplode(['slice.php', self::SLICE]), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [self::SLICE => 'Members']);

        $this->assertStringStartsWith('slice.php ', $finding->title);
    }

    public function test_findingsFor_unknownSlice_isNamedByItsId(): void
    {
        $row = ['selector' => ParamImplode(['42', self::SLICE]), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], []);

        $this->assertStringContainsString(self::SLICE, $finding->title);
    }
}
