<?php

declare(strict_types=1);

/**
 * AA_Adminpageutil::printHead() expands the page begin as a template (for {generate:HEAD}), the <title> with it.
 * A title carries names (an import, a file), so its braces stay text.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA_Adminpageutil;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class AdminpageTitleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/adminpage.class.php';
    }

    private static function pageBegin(string $title): string
    {
        $page = new AA_Adminpageutil('sliceadmin', 'dataimports');
        $page->setTitle($title);
        return $page->getPageBegin();
    }

    public function test_getPageBegin_ordinaryTitle_asItIs(): void
    {
        self::assertStringContainsString("\n  <title>Import: Členové &amp; dárci</title>\n", self::pageBegin('Import: Členové &amp; dárci'));
    }

    public function test_getPageBegin_bracesInTitle_areText(): void
    {
        $begin = self::pageBegin('Import: {date:Y}&lt;b&gt;');
        self::assertStringContainsString('<title>Import: &#123;date:Y&#125;&lt;b&gt;</title>', $begin);
        self::assertStringContainsString('{generate:HEAD}', $begin);
        self::assertStringContainsString('<title>Import: &#123;date:Y&#125;&lt;b&gt;</title>', AA::Un($begin));
    }
}
