<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckCkeditorUse;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckCkeditorUseTest extends TestCase
{
    private const MODULE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_selector_is_the_module_and_the_page_without_query(): void
    {
        $server = ['HTTP_HOST' => 'www.example.org', 'REQUEST_URI' => '/formular/mail?x=1&token=secret'];
        $this->assertSame([self::MODULE, 'www.example.org/formular/mail'], ParamExplode(CheckCkeditorUse::selector($server, self::MODULE)));
    }

    public function test_selector_survives_a_missing_request(): void
    {
        $this->assertSame(['', ''], ParamExplode(CheckCkeditorUse::selector([], '')));
    }

    public function test_no_use_is_ok(): void
    {
        $findings = CheckCkeditorUse::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_a_use_is_a_warning_naming_the_page_and_the_module(): void
    {
        $row = ['selector' => ParamImplode([self::MODULE, 'www.example.org/formular']), 'days' => '3', 'first' => '1790000000', 'last' => '1790086400', 'params' => ''];
        [$finding] = CheckCkeditorUse::findingsFor([$row], [self::MODULE => 'Web']);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('www.example.org/formular', $finding->title);
        $this->assertSame('Web', $finding->evidence[0][_m('Module')]);
        $this->assertSame(3, $finding->count);
    }
}
