<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckViewDirectRefusals;
use AA\Optimize\Finding;
use AA\Security\ViewDirect;
use PHPUnit\Framework\TestCase;

final class CheckViewDirectRefusalsTest extends TestCase
{
    private const SITE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_findingsFor_noRefusals_isOk(): void
    {
        $findings = CheckViewDirectRefusals::findingsFor([], [], []);

        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_findingsFor_refusedBySite_namesTheViewTheSiteAndThePageThatAsked(): void
    {
        $row = ['selector' => ParamImplode(['42', ViewDirect::BY_SITE, self::SITE]), 'days' => '3', 'first' => '1790000000', 'last' => '1790172800',
            'params' => ParamImplode(['example.org/aa/view.php', 'example.org/cz/akce'])];

        [$finding] = CheckViewDirectRefusals::findingsFor([$row], [self::SITE => 'Web <b>'], [42 => '/aa/admin/se_view.php?view_id=42']);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('View 42', $finding->title);
        $this->assertSame(3, $finding->count);
        $this->assertSame($row['selector'], $finding->key);
        $this->assertSame('the site Web &lt;b&gt; (settings of the site)', $finding->evidence[0][_m('Set by')]);
        $this->assertSame('example.org/aa/view.php', $finding->evidence[0][_m('Address asked for')]);
        $this->assertSame('example.org/cz/akce', $finding->evidence[0][_m('Link on page')]);
        $this->assertSame('/aa/admin/se_view.php?view_id=42', $finding->links[_m('View settings')]);
    }

    public function test_findingsFor_refusedByTheView_saysWhereItIsSet(): void
    {
        $row = ['selector' => ParamImplode(['7', ViewDirect::BY_VIEW, '']), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckViewDirectRefusals::findingsFor([$row], [], []);

        $this->assertSame('the view (&quot;Only inside pages&quot;)', $finding->evidence[0][_m('Set by')]);
        $this->assertSame('', $finding->evidence[0][_m('Link on page')]);
    }

    public function test_types_basicCheck(): void
    {
        $this->assertSame([AbstractOptimize::CHECK_BASIC], CheckViewDirectRefusals::types());
    }
}
