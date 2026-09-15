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

    public function test_server_settings_are_dropped_whole_when_the_row_would_not_fit(): void
    {
        $info = ['aa' => '2026.6', 'svn' => '5382M (5365)', 'php' => '8.3.33', 'db' => '10.11.19-MariaDB-log/DB_PDO', 'mem' => '128318 MB', 'cpu' => 'AMD Ryzen 9 5950X 16-Core Processor', 'server' => 'f1 s1 c0 d0 b40G'];
        $this->assertSame('f1 s1 c0 d0 b40G', LogSystemstatus::fitted(new AA_Logentry_Sys($info))->server, '121 bytes fit');

        $info['cpu'] = 'Intel(R) Xeon(R) Platinum 8375C CPU @ 2.90GHz';
        $entry = LogSystemstatus::fitted(new AA_Logentry_Sys($info));
        $this->assertSame('', $entry->server, 'the one optional field gives way whole: a cut token would not read back');
        $this->assertLessThanOrEqual(128, strlen($entry->serializeToString()));
    }

    public function test_a_last_row_lacking_only_the_server_settings_is_not_written_again(): void
    {
        $info  = ['aa' => '2026.6', 'svn' => '5382M (5365)', 'php' => '8.4.25', 'db' => '10.11.19-MariaDB-log/DB_PDO', 'mem' => '128318 MB', 'cpu' => 'x', 'server' => 'f1 s1 c0 d0 b40G'];
        $entry = new AA_Logentry_Sys($info);
        $older = 'Sys:2026.6:5382M (5365):8.4.25:10.11.19-MariaDB-log/DB_PDO:128318 MB:x';   // six fields, as an older AA writes it

        $this->assertTrue(LogSystemstatus::sameButForServer($older, $entry), 'an older AA on the same database wrote the row without the field');
        $this->assertFalse(LogSystemstatus::sameButForServer($this->lastRow('5379M (5365)'), $entry), 'a different tree is a change');
        $this->assertFalse(LogSystemstatus::sameButForServer('', $entry));
        $this->assertFalse(LogSystemstatus::sameButForServer($older, new AA_Logentry_Sys(['server' => ''] + $info)), 'nothing to add: writeIfChanged decides');
    }

    public function test_git_build_stamp_is_a_readable_tree(): void
    {
        // a dist checkout: include/release.php names the main commit, aa_version() reports it as tree and revision
        $this->assertSame('a1b2c3d (a1b2c3d)', LogSystemstatus::svnField('a1b2c3d (a1b2c3d)', $this->lastRow('5378M (5365)')), 'the first git build after the SVN era');
        $this->assertSame('b2c3d4e (b2c3d4e)', LogSystemstatus::svnField('b2c3d4e (b2c3d4e)', $this->lastRow('a1b2c3d (a1b2c3d)')));
        $this->assertSame('a1b2c3d ()', LogSystemstatus::svnField(' ()', $this->lastRow('a1b2c3d (a1b2c3d)')), 'a source checkout has no stamp: keep the last tree, no revision of its own');
    }

    public function test_nothing_to_carry_leaves_the_tree_empty(): void
    {
        $this->assertSame(' (5365)', LogSystemstatus::svnField('Unversioned directory (5365)', ''));
        $this->assertSame(' (5365)', LogSystemstatus::svnField('Unversioned directory (5365)', $this->lastRow('Unversioned directory (5360)')));
        $this->assertSame(' (5365)', LogSystemstatus::svnField('Unversioned directory (5365)', 'Ms:smtp:1:ok:a@b.c:subj'), 'a foreign row is ignored');
    }
}
