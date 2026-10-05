<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Http\TransportException;
use AA\Optimize\CheckBlockedRequests;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckBlockedRequestsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_no_refusals_is_ok(): void
    {
        $findings = CheckBlockedRequests::findingsFor([]);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_a_refused_host_is_a_warning_naming_what_asked(): void
    {
        $row = ['selector' => ParamImplode(['ok_url', '192.0.2.1']), 'days' => '3', 'first' => '1790000000', 'last' => '1790172800', 'params' => '169.254.169.254'];
        [$finding] = CheckBlockedRequests::findingsFor([$row]);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('ok_url', $finding->title);
        $this->assertStringContainsString('169.254.169.254', $finding->title);
        $this->assertSame(3, $finding->count);
        $this->assertStringContainsString('192.0.2.1', $finding->title);
        $this->assertSame('169.254.169.254', $finding->evidence[0][_m('Host')]);
    }

    // a timeout or DNS failure is not a refusal - nothing to log, and no database is touched
    public function test_record_otherFailure_logsNothing(): void
    {
        $this->assertFalse(CheckBlockedRequests::record(new TransportException('No answer', 0, new \RuntimeException('Timeout reached')), 'ok_url', '192.0.2.1'));
    }
}
