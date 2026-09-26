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

    public function test_page_is_host_and_path_without_query(): void
    {
        $server = ['HTTP_HOST' => 'www.example.org', 'REQUEST_URI' => '/formular/mail?x=1&token=secret'];
        $this->assertSame('www.example.org/formular/mail', CheckCkeditorUse::page($server));
    }

    public function test_page_survives_a_missing_request(): void
    {
        $this->assertSame('', CheckCkeditorUse::page([]));
    }

    public function test_no_use_is_ok(): void
    {
        $findings = CheckCkeditorUse::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_a_use_is_a_warning_naming_the_site_with_a_page(): void
    {
        $row = ['selector' => self::MODULE, 'days' => '3', 'first' => '1790000000', 'last' => '1790086400', 'params' => 'www.example.org/formular'];
        [$finding] = CheckCkeditorUse::findingsFor([$row], [self::MODULE => 'Web']);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('Web', $finding->title);
        $this->assertSame('www.example.org/formular', $finding->evidence[0][_m('Page')]);
        $this->assertSame(3, $finding->count);
    }

    public function test_without_a_site_the_page_names_it(): void
    {
        $row = ['selector' => '', 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => 'www.example.org/view.php'];
        [$finding] = CheckCkeditorUse::findingsFor([$row], []);
        $this->assertStringContainsString('www.example.org/view.php', $finding->title);
    }
}
