<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * File Manager commands stay inside the File Manager's directory: a name with ../ from the request
 * (the file to edit, save, delete or rename) never reaches a file outside it.
 */
final class FilemanCommandTest extends TestCase
{
    private string $root;
    private string $base;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m()
        require_once __DIR__ . '/../../../include/fileman.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aa-fileman-' . bin2hex(random_bytes(4));
        $this->base = $this->root . '/files/';
        mkdir($this->base . 'site', 0777, true);
        file_put_contents($this->root . '/outside.txt', 'keep');
        file_put_contents($this->base . 'site/a.txt', 'a');
        $GLOBALS['err'] = [];
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    public function test_savefile_doesNotWriteAboveTheBase(): void
    {
        fileman_execute_command($this->base, '', 'savefile', ['savefile' => 'changed'], [], ['filename' => '../outside.txt']);
        self::assertSame('keep', file_get_contents($this->root . '/outside.txt'));
    }

    public function test_rename_keepsTheFileInItsDirectory(): void
    {
        fileman_execute_command($this->base, 'site/', 'rename', ['rename' => '../../moved.txt'], [], ['filename' => 'site/a.txt']);
        self::assertFileExists($this->base . 'site/moved.txt');
        self::assertFileDoesNotExist($this->root . '/moved.txt');
    }

    public function test_delete_doesNotRemoveAFileAboveTheBase(): void
    {
        fileman_execute_command($this->base, '', 'delete', ['delete' => ''], ['../outside.txt' => 'on'], []);
        self::assertFileExists($this->root . '/outside.txt');
    }

    public function test_edit_namesAFileInsideTheBase(): void
    {
        fileman_execute_command($this->base, '', 'edit', ['edit' => '../outside.txt'], [], []);
        self::assertStringNotContainsString('..', $GLOBALS['fe_filename']);
    }
}
