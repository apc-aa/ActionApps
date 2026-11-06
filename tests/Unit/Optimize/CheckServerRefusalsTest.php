<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckServerRefusals;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckServerRefusalsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_no_refusals_is_ok(): void
    {
        $findings = CheckServerRefusals::findingsFor([]);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_a_refused_name_is_a_warning_with_the_page_that_asked(): void
    {
        $row = ['selector' => 'HTTP_COOKIE', 'days' => '2', 'first' => '1790000000', 'last' => '1790086400', 'params' => 'www.example.org/cz/<b>'];

        [$finding] = CheckServerRefusals::findingsFor([$row]);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('{server:HTTP_COOKIE}', $finding->title);
        $this->assertSame(2, $finding->count);
        $this->assertSame('HTTP_COOKIE', $finding->key);
        $this->assertSame('www.example.org/cz/&lt;b&gt;', $finding->evidence[0][_m('Page')], 'the page comes from the request');
    }
}
