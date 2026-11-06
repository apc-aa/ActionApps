<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckFileReadRefusals;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckFileReadRefusalsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamExplode()
    }

    public function test_no_refusals_is_ok(): void
    {
        $findings = CheckFileReadRefusals::findingsFor([]);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_a_refused_file_is_a_warning_with_its_reason(): void
    {
        $row = ['selector' => 'readfile:../include/<b>config.php', 'days' => '2', 'first' => '1790000000', 'last' => '1790086400', 'params' => 'in the AA tree:0123456789abcdef0123456789abcdef'];

        [$finding] = CheckFileReadRefusals::findingsFor([$row]);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('../include/<b>config.php', $finding->title, 'a plain-text title, escaped where shown');
        $this->assertStringContainsString('readfile', $finding->title);
        $this->assertSame(2, $finding->count);
        $this->assertSame('readfile:../include/<b>config.php', $finding->key);
        $this->assertSame('in the AA tree', $finding->evidence[0][_m('Reason')]);
        $this->assertSame('0123456789abcdef0123456789abcdef', $finding->evidence[0][_m('Slice')]);
        $this->assertSame('../include/&lt;b&gt;config.php', $finding->evidence[0][_m('File')]);
    }
}
