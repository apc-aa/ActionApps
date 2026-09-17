<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Files::createFolder() makes the directories uploads land in (one per slice under
 * IMG_UPLOAD_PATH, the image cache root). Several PHP users share them - the /aaa/ and
 * /aa-dev/ trees, cron - through the group of the upload directory, which its setgid bit
 * hands down to every new directory. The explicit chmod() after mkdir() is there so that
 * a process umask cannot strip the group write bit (aa/CLAUDE.md, "File Permissions").
 */
final class FilesCreateFolderTest extends TestCase
{
    private string $parent;
    private int $umask;

    protected function setUp(): void
    {
        $this->parent = sys_get_temp_dir() . '/aa-createfolder-' . bin2hex(random_bytes(8));
        mkdir($this->parent);
        $this->umask = umask(0o022);   // the umask which bit the shared checkout (PHPStorm, 2026-09-10)
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        @rmdir($this->parent . '/new');
        @rmdir($this->parent);
    }

    /** characterization: the mode asked for, whatever the umask */
    public function test_mode_is_applied_whatever_the_umask(): void
    {
        chmod($this->parent, 0o775);

        $this->assertTrue(\Files::createFolder($this->parent . '/new', 0o774));
        $this->assertSame(0o774, fileperms($this->parent . '/new') & 0o7777);
    }

    /** chmod() with a mode lacking the setgid bit used to clear the one the directory inherited */
    public function test_setgid_inherited_from_the_parent_is_kept(): void
    {
        chmod($this->parent, 0o2775);
        if ((fileperms($this->parent) & 0o2000) === 0) {
            $this->markTestSkipped('this filesystem does not keep the setgid bit on directories');
        }

        $this->assertTrue(\Files::createFolder($this->parent . '/new', 0o774));
        $this->assertSame(0o2774, fileperms($this->parent . '/new') & 0o7777);
    }
}
