<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckSecurityProtections;
use AA\Optimize\Finding;
use AA\Security\Protection;
use PHPUnit\Framework\TestCase;

final class CheckSecurityProtectionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_allOnAndNothingSent_isOk(): void
    {
        $findings = CheckSecurityProtections::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_aProtectionSwitchedOff_warnsUntilItIsOn(): void
    {
        [$finding] = CheckSecurityProtections::findingsFor([[Protection::SignedExpressions, ['time' => '1790000000', 'user' => 'admin']]], []);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString(Protection::SignedExpressions->label(), $finding->title);
        $this->assertSame(1790000000, $finding->since);
        $this->assertSame('off:signed_expressions', $finding->key);
    }

    public function test_anUnsignedExpression_namesTheParameterAndThePage(): void
    {
        $row = ['selector' => ParamImplode(['refused', 'ret_code', 'example.org/darujte']), 'days' => '2', 'first' => '1790000000', 'last' => '1790086400', 'params' => ''];
        [$finding] = CheckSecurityProtections::findingsFor([], [[Protection::SignedExpressions, $row]]);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('ret_code', $finding->title);
        $this->assertStringContainsString('example.org/darujte', $finding->title);
        $this->assertStringContainsString('refused', $finding->title);
        $this->assertSame(2, $finding->count);
    }

    public function test_anExpressionInAUrlAlias_namesTheAliasAndThePage(): void
    {
        $row = ['selector' => ParamImplode(['refused', 'als[ROK]', 'example.org/cz/rocnik']), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];
        [$finding] = CheckSecurityProtections::findingsFor([], [[Protection::UrlValuesAsText, $row]]);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('als[ROK]', $finding->title);
        $this->assertStringContainsString('example.org/cz/rocnik', $finding->title);
        $this->assertStringContainsString('shown as text', $finding->title);
    }
}
