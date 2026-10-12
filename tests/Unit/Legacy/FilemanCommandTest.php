<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * File Manager commands stay inside the File Manager's directory: a name with ../ from the request
 * (the file to edit, save, delete or rename) never reaches a file outside it. It keeps only files
 * the server serves: no script or server setting is created, written, edited or renamed into.
 */
final class FilemanCommandTest extends TestCase
{
    private string $root;
    private string $base;

    public static function setUpBeforeClass(): void
    {
        if (!\defined('DEFAULT_LANG_INCLUDE')) {
            \define('DEFAULT_LANG_INCLUDE', 'en_utf8.php3');
        }
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m()
        require_once __DIR__ . '/../../../include/files.class.php';
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

    /** @return array<string, array{string}> */
    public static function refusedNames(): array
    {
        return [
            'php'           => ['x.php'],
            'upper case'    => ['X.PHP'],
            'inner'         => ['x.php.html'],
            'server config' => ['.htaccess'],
            'php settings'  => ['.user.ini'],
            'trailing line' => ["x.php\n"],
        ];
    }

    #[DataProvider('refusedNames')]
    public function test_createfile_refusesAScriptOrSetting(string $name): void
    {
        fileman_execute_command($this->base, 'site/', 'createfile', ['createfile' => $name], [], []);
        self::assertFileDoesNotExist($this->base . 'site/' . $name);
        self::assertNotEmpty($GLOBALS['err']);
    }

    #[DataProvider('refusedNames')]
    public function test_rename_refusesAScriptOrSetting(string $name): void
    {
        fileman_execute_command($this->base, 'site/', 'rename', ['rename' => $name], [], ['filename' => 'site/a.txt']);
        self::assertFileExists($this->base . 'site/a.txt');
        self::assertFileDoesNotExist($this->base . 'site/' . $name);
    }

    public function test_rename_letsAnOldScriptBeRenamedAway(): void
    {
        file_put_contents($this->base . 'site/old.php', 'x');
        fileman_execute_command($this->base, 'site/', 'rename', ['rename' => 'old.txt'], [], ['filename' => 'site/old.php']);
        self::assertFileExists($this->base . 'site/old.txt');
    }

    public function test_savefile_doesNotWriteAScript(): void
    {
        file_put_contents($this->base . 'site/old.php', 'x');
        fileman_execute_command($this->base, 'site/', 'savefile', ['savefile' => 'changed'], [], ['filename' => 'site/old.php']);
        self::assertSame('x', file_get_contents($this->base . 'site/old.php'));
    }

    public function test_edit_doesNotOpenAScript(): void
    {
        $GLOBALS['fe_filename'] = null;
        fileman_execute_command($this->base, 'site/', 'edit', ['edit' => 'site/old.php'], [], []);
        self::assertNull($GLOBALS['fe_filename']);
    }

    public function test_createfile_makesAPage(): void
    {
        if (!\defined('FILEMAN_MODE_FILE')) {
            \define('FILEMAN_MODE_FILE', 0o664);
        }
        fileman_execute_command($this->base, 'site/', 'createfile', ['createfile' => 'page'], [], []);
        self::assertFileExists($this->base . 'site/page.html');
    }
}
