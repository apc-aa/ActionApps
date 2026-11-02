<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckFormRobotRefusals;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckFormRobotRefusalsTest extends TestCase
{
    private const SLICE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_no_refusals_is_ok(): void
    {
        $findings = CheckFormRobotRefusals::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    /** mostly spam - only an info, with the page of the form to check */
    public function test_a_refused_slice_is_an_info_with_the_page(): void
    {
        $row = ['selector' => self::SLICE, 'days' => '3', 'first' => '1790000000', 'last' => '1790086400', 'params' => 'ulpan.cz/cz/kurzy/<b>'];

        [$finding] = CheckFormRobotRefusals::findingsFor([$row], [self::SLICE => 'Applications']);

        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertStringContainsString('Applications', $finding->title);
        $this->assertSame(3, $finding->count);
        $this->assertSame(self::SLICE, $finding->key);
        $this->assertSame('ulpan.cz/cz/kurzy/&lt;b&gt;', $finding->evidence[0][_m('Page')], 'the page comes from the request');
    }

    public function test_an_unknown_slice_is_named_by_its_id(): void
    {
        $row = ['selector' => self::SLICE, 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckFormRobotRefusals::findingsFor([$row], []);

        $this->assertStringContainsString(self::SLICE, $finding->title);
    }
}
