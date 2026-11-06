<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckSetupPage;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckSetupPageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../include/mgettext.php';
    }

    public function test_switched_off_is_ok(): void
    {
        $this->assertSame(Finding::OK, CheckSetupPage::assess(false)->severity);
    }

    public function test_switched_on_is_an_error_with_the_fix(): void
    {
        $finding = CheckSetupPage::assess(true);
        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString("define('AA_SETUP_ENABLED', true);", $finding->advice);
    }

    public function test_findings_reads_the_switch_of_this_installation(): void
    {
        // the test config does not switch the setup page on
        $this->assertSame(Finding::OK, (new CheckSetupPage())->findings()[0]->severity);
    }

    public function test_is_a_basic_security_check(): void
    {
        $this->assertSame([CheckSetupPage::CHECK_BASIC], CheckSetupPage::types());
        $this->assertSame('security', CheckSetupPage::category());
    }
}
