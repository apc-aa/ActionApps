<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\SafeFile;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\Alarm;
use Tests\Unit\Fixtures\ScriptedFile;

/** SafeFile in a directory of each test's own */
final class SafeFileTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/Alarm.php';
        require_once __DIR__ . '/../fixtures/ScriptedFile.php';
        ScriptedFile::register();
    }

    private string $dir = '';

    private string $path = '';

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/safefile-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/list.xml';
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir, 0o700);
        foreach (scandir($this->dir) ?: [] as $name) {
            if (($name !== '.') && ($name !== '..')) {
                is_dir("$this->dir/$name") && !is_link("$this->dir/$name") ? @rmdir("$this->dir/$name") : @unlink("$this->dir/$name");
            }
        }
        @rmdir($this->dir);
    }

    // never the system temp directory, which another server does not see - and a rename within the directory
    public function test_replace_throughANewFileInTheSameDirectory(): void
    {
        file_put_contents($this->path, 'old');
        $seen = [];

        SafeFile::replace($this->path, function () use (&$seen): string {
            $seen = array_map(static fn(string $f): array => [basename($f), is_file($f) && !is_link($f), filesize($f)], glob($this->dir . '/.list.xml.*') ?: []);
            return 'new';
        });

        self::assertCount(1, $seen, 'made before the content');
        self::assertMatchesRegularExpression('/^\.list\.xml\.[0-9a-f]{16}\z/', $seen[0][0]);
        self::assertSame([true, 0], [$seen[0][1], $seen[0][2]], 'a new empty regular file');
        self::assertSame('new', file_get_contents($this->path));
        self::assertSame(['list.xml'], $this->files());
    }

    // PHP has no fchmod(): the mode is the one the file was born with
    public function test_replace_modeFromTheUmask(): void
    {
        $umask = umask(0o027);
        try {
            SafeFile::replace($this->path, static fn(): string => 'x');
        } finally {
            umask($umask);
        }
        clearstatcache();
        self::assertSame(0o640, fileperms($this->path) & 0o777);
    }

    public function test_replace_failedContentKeepsThePreviousFile(): void
    {
        file_put_contents($this->path, 'old');

        try {
            SafeFile::replace($this->path, static fn(): string => throw new \UnexpectedValueException('not a list'));
            self::fail('the content failed');
        } catch (\UnexpectedValueException $e) {
            self::assertSame('not a list', $e->getMessage());
        }
        self::assertSame('old', file_get_contents($this->path));
        self::assertSame(['list.xml'], $this->files());
    }

    // rename() cannot put a file over a directory
    public function test_replace_failedRenameLeavesNothing(): void
    {
        mkdir($this->path);

        try {
            SafeFile::replace($this->path, static fn(): string => 'new');
            self::fail('a file renamed over a directory');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("Cannot replace $this->path", $e->getMessage());
        }
        self::assertSame(['list.xml'], $this->files());
    }

    // fails before the work, and puts nothing elsewhere
    public function test_replace_refusedInADirectoryPhpCannotWriteInto(): void
    {
        if (!function_exists('posix_geteuid') || (posix_geteuid() === 0)) {
            self::markTestSkipped('root writes into every directory');
        }
        chmod($this->dir, 0o500);
        $called = false;

        try {
            SafeFile::replace($this->path, static function () use (&$called): string {
                $called = true;
                return 'new';
            });
            self::fail('written into a directory PHP cannot write into');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("Cannot create a file in $this->dir - it is missing or not writable", $e->getMessage());
        }
        self::assertFalse($called);
        self::assertSame([], glob(sys_get_temp_dir() . '/.list.xml.*'));
    }

    // left by a request that died while writing
    public function test_replace_removesOldTemporaryFiles(): void
    {
        foreach (['.list.xml.0123456789abcdef' => 7200, '.list.xml.fedcba9876543210' => 60, '.list.xml.notours' => 7200, '.other.xml.0123456789abcdef' => 7200] as $name => $age) {
            touch("$this->dir/$name", time() - $age);
        }

        SafeFile::replace($this->path, static fn(): string => 'new');

        self::assertSame(['.list.xml.fedcba9876543210', '.list.xml.notours', '.other.xml.0123456789abcdef', 'list.xml'], $this->files());
    }

    public function test_read_wholeRegularFile(): void
    {
        file_put_contents($this->path, str_repeat('a', 3 * 1024 * 1024));

        self::assertSame(3 * 1024 * 1024, strlen(SafeFile::read($this->path, 3 * 1024 * 1024)));
        $this->expectException(\UnexpectedValueException::class);
        SafeFile::read($this->path, 3 * 1024 * 1024 - 1);
    }

    // a file PHP cannot open is a failed read, not another file: the exception type tells them apart
    public function test_open_failureIsNoOtherFile(): void
    {
        try {
            SafeFile::open($this->dir . '/none.xml');
            self::fail('a missing file opened');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(\UnexpectedValueException::class, $e);
            self::assertStringContainsString('Cannot read ' . $this->dir . '/none.xml', $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function notFiles(): array
    {
        return ['FIFO' => ['fifo'], 'device' => ['/dev/zero'], 'directory' => ['dir']];
    }

    // a FIFO would block the open, a device never end
    #[\PHPUnit\Framework\Attributes\DataProvider('notFiles')]
    public function test_open_refusesWhatIsNoRegularFileAtOnce(string $what): void
    {
        match ($what) {
            'fifo'  => posix_mkfifo($this->path, 0o600) ?: self::markTestSkipped('no FIFO here'),
            'dir'   => mkdir($this->path),
            default => symlink($what, $this->path),
        };

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('is not a regular file');
        Alarm::within(2, fn() => SafeFile::open($this->path));
    }

    // refused by the size fstat() tells, before a byte is read
    public function test_read_refusesALargerFileUnread(): void
    {
        ScriptedFile::$content = static fn(): string => str_repeat('a', 10);

        try {
            SafeFile::read('scripted://x', 5);
            self::fail('a larger file read');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('is not a regular file of at most 5 bytes', $e->getMessage());
        } finally {
            $served = ScriptedFile::$served;
            ScriptedFile::reset();
        }
        self::assertSame(0, $served);
    }

    // fstat() told 10 bytes at the open; someone keeps writing to it
    public function test_read_neverPastTheLimitOfAFileThatGrows(): void
    {
        ScriptedFile::$content = static fn(): string => str_repeat('a', 10);
        ScriptedFile::$growth  = "abcdefg\t\tx\n";

        try {
            SafeFile::read('scripted://list.xml', 1024 * 1024);
            self::fail('read past the limit');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('at most 1048576 bytes', $e->getMessage());
        } finally {
            $served = ScriptedFile::$served;
            ScriptedFile::reset();
        }
        self::assertLessThanOrEqual(3 * 1024 * 1024, $served, 'the limit and a chunk of the stream');
    }

    /** @return list<string> byte order - scandir() sorts by the locale another test may have set */
    private function files(): array
    {
        $files = array_values(array_diff((array) scandir($this->dir), ['.', '..']));
        sort($files, SORT_STRING);
        return $files;
    }
}
