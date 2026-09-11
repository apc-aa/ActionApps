<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\LogSystemstatus;
use AA_Logentry_Sys;
use PHPUnit\Framework\TestCase;

/**
 * The SYS_STAT row is written only when its serialized form changes, so the
 * `svn` field must not flap between a real svnversion and "Unversioned
 * directory" when two processes with different views of the checkout share one
 * DB (aadev: 3 635 never-cleaned rows). An unreadable tree carries the last
 * stored one; the util.php revision in parentheses is always this run's.
 *
 * include/logs.php autoloads via the classmap; the util.php helpers it needs
 * come from fixtures/util_shims.php.
 */
final class LogSystemstatusTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        class_exists(AA_Logentry_Sys::class);   // include/logs.php via the classmap
    }

    private function lastRow(string $svn): string
    {
        return (new AA_Logentry_Sys(['aa' => '2026.6', 'svn' => $svn, 'php' => '8.4.1', 'db' => '10.11/mysql', 'mem' => '7971 MB', 'cpu' => 'x']))->serializeToString();
    }

    public function test_readable_tree_is_stored_as_read(): void
    {
        $this->assertSame('5378M (5365)', LogSystemstatus::svnField('5378M (5365)', $this->lastRow('5301 (5290)')));
        $this->assertSame('5240:5245MS (5245)', LogSystemstatus::svnField('5240:5245MS (5245)', ''));
    }

    public function test_unreadable_tree_carries_the_last_stored_one(): void
    {
        $this->assertSame('5378M (5365)', LogSystemstatus::svnField('Unversioned directory (5365)', $this->lastRow('5378M (5360)')));
        $this->assertSame('5378M (5365)', LogSystemstatus::svnField(' (5365)', $this->lastRow('5378M (5365)')));
    }

    public function test_revision_in_parentheses_is_always_this_runs(): void
    {
        $this->assertSame('5378M (5370)', LogSystemstatus::svnField('Unversioned directory (5370)', $this->lastRow('5378M (5365)')));
    }

    public function test_nothing_to_carry_leaves_the_tree_empty(): void
    {
        $this->assertSame(' (5365)', LogSystemstatus::svnField('Unversioned directory (5365)', ''));
        $this->assertSame(' (5365)', LogSystemstatus::svnField('Unversioned directory (5365)', $this->lastRow('Unversioned directory (5360)')));
        $this->assertSame(' (5365)', LogSystemstatus::svnField('Unversioned directory (5365)', 'Ms:smtp:1:ok:a@b.c:subj'), 'a foreign row is ignored');
    }
}
