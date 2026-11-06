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
        $row = ['selector' => 'fileman:/data/www/aa/include/<b>config.php', 'days' => '2', 'first' => '1790000000', 'last' => '1790086400', 'params' => 'aa:0123456789abcdef0123456789abcdef'];

        [$finding] = CheckFileReadRefusals::findingsFor([$row]);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('/data/www/aa/include/<b>config.php', $finding->title, 'a plain-text title, escaped where shown');
        $this->assertStringContainsString('fileman', $finding->title);
        $this->assertSame(2, $finding->count);
        $this->assertSame('fileman:/data/www/aa/include/<b>config.php', $finding->key);
        $this->assertSame('in the directory of an AA installation', $finding->evidence[0][_m('Reason')]);
        $this->assertSame('0123456789abcdef0123456789abcdef', $finding->evidence[0][_m('Slice')]);
        $this->assertSame('/data/www/aa/include/&lt;b&gt;config.php', $finding->evidence[0][_m('File')]);
    }

    public function test_a_readfile_use_names_the_slice_and_what_to_use_instead(): void
    {
        $row = ['selector' => 'readfile:0123456789abcdef0123456789abcdef', 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => 'parts/<b>menu.html'];

        [$finding] = CheckFileReadRefusals::findingsFor([$row]);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('{include:parts/<b>menu.html:readfile}', $finding->title);
        $this->assertSame('0123456789abcdef0123456789abcdef', $finding->evidence[0][_m('Slice or site')]);
        $this->assertSame('parts/&lt;b&gt;menu.html', $finding->evidence[0][_m('File')]);
        $this->assertStringContainsString('{include:<file>:fileman}', $finding->advice);
        $this->assertSame('readfile:0123456789abcdef0123456789abcdef', $finding->key);
    }

    public function test_a_mail_file_from_outside_the_uploads_is_info(): void
    {
        $row = ['selector' => 'mail:/data/webs/x/upload/a.pdf', 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckFileReadRefusals::findingsFor([], [$row]);

        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertStringContainsString('/data/webs/x/upload/a.pdf', $finding->title);
        $this->assertSame('outer:mail:/data/webs/x/upload/a.pdf', $finding->key);
    }
}
