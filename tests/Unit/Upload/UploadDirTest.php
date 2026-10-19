<?php

declare(strict_types=1);

namespace Tests\Unit\Upload;

use AA\Upload\UploadDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\Alarm;

final class UploadDirTest extends TestCase
{
    private const URL = 'https://Example.org/img_upload/abc';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/Alarm.php';
    }

    private string $root = '';
    private string $dir  = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aa-uploaddir-' . bin2hex(random_bytes(6));
        $this->dir  = "$this->root/upload/abc";
        mkdir($this->dir, 0o777, true);
        mkdir("$this->root/outside");
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if (($entry !== '.') && ($entry !== '..')) {
                self::remove("$path/$entry");
            }
        }
        rmdir($path);
    }

    private function uploads(): UploadDir
    {
        return new UploadDir(self::URL . '/', $this->dir . '/');
    }

    public function test_read_bytesOfTheFile(): void
    {
        file_put_contents("$this->dir/pozvanka-2026.pdf", self::PDF);

        self::assertSame(self::PDF, $this->uploads()->read(self::URL . '/pozvanka-2026.pdf', 1000));
    }

    #[DataProvider('sameDirectory')]
    public function test_read_urlOfTheDirectory(string $url): void
    {
        file_put_contents("$this->dir/a.pdf", self::PDF);

        self::assertTrue($this->uploads()->contains($url));
        self::assertSame(self::PDF, $this->uploads()->read($url, 1000));
    }

    public static function sameDirectory(): array
    {
        return [
            'http'               => ['http://example.org/img_upload/abc/a.pdf'],
            'host in capitals'   => ['https://EXAMPLE.ORG/img_upload/abc/a.pdf'],
            'host with the dot'  => ['https://example.org./img_upload/abc/a.pdf'],
            'https default port' => ['https://example.org:443/img_upload/abc/a.pdf'],
            'http default port'  => ['http://example.org:80/img_upload/abc/a.pdf'],
            'protocol relative'  => ['//example.org/img_upload/abc/a.pdf'],
            'without the host'   => ['/img_upload/abc/a.pdf'],
            'spaces around'      => [' https://example.org/img_upload/abc/a.pdf '],
        ];
    }

    #[DataProvider('notUploads')]
    public function test_read_refusesOtherUrls(string $url): void
    {
        file_put_contents("$this->dir/a.pdf", self::PDF);
        file_put_contents("$this->root/upload/abcd.pdf", self::PDF);
        file_put_contents("$this->root/outside/secret.pdf", self::PDF);

        self::assertFalse($this->uploads()->contains($url));
        $this->expectException(\InvalidArgumentException::class);
        $this->uploads()->read($url, 1000);
    }

    public static function notUploads(): array
    {
        return [
            'other host'           => ['https://evil.example/img_upload/abc/a.pdf'],
            'host with two dots'   => ['https://example.org../img_upload/abc/a.pdf'],
            'other port'           => ['https://example.org:8443/img_upload/abc/a.pdf'],
            'https on port 80'     => ['https://example.org:80/img_upload/abc/a.pdf'],
            'with a user'          => ['https://user@example.org/img_upload/abc/a.pdf'],
            'ftp'                  => ['ftp://example.org/img_upload/abc/a.pdf'],
            'other directory'      => ['https://example.org/img_upload/abd/a.pdf'],
            'prefix of a name'     => ['https://example.org/img_upload/abcd.pdf'],
            'directory itself'     => ['https://example.org/img_upload/abc/'],
            'parent'               => ['https://example.org/img_upload/abc/../../outside/secret.pdf'],
            'encoded parent'       => ['https://example.org/img_upload/abc/%2e%2e/%2e%2e/outside/secret.pdf'],
            'encoded slash parent' => ['https://example.org/img_upload/abc/..%2F..%2Foutside/secret.pdf'],
            'current directory'    => ['https://example.org/img_upload/abc/./a.pdf'],
            'double slash'         => ['https://example.org/img_upload/abc//a.pdf'],
            'nul'                  => ['https://example.org/img_upload/abc/a.pdf%00.png'],
            'query'                => ['https://example.org/img_upload/abc/a.pdf?x=1'],
            'fragment'             => ['https://example.org/img_upload/abc/a.pdf#x'],
            'relative'             => ['img_upload/abc/a.pdf'],
            'scheme without host'  => ['file:///img_upload/abc/a.pdf'],
            'empty'                => [''],
        ];
    }

    public function test_read_refusesLinkOutOfTheDirectory(): void
    {
        file_put_contents("$this->root/outside/secret.pdf", self::PDF);
        symlink("$this->root/outside/secret.pdf", "$this->dir/a.pdf");

        $this->assertNotUploaded(self::URL . '/a.pdf');
    }

    public function test_read_refusesLinkedDirectoryOutside(): void
    {
        file_put_contents("$this->root/outside/secret.pdf", self::PDF);
        symlink("$this->root/outside", "$this->dir/sub");

        $this->assertNotUploaded(self::URL . '/sub/secret.pdf');
    }

    // "abc/" is the prefix to keep, not "abc"
    public function test_read_refusesSiblingWithTheSamePrefix(): void
    {
        mkdir("$this->root/upload/abcd");
        file_put_contents("$this->root/upload/abcd/secret.pdf", self::PDF);
        symlink("$this->root/upload/abcd", "$this->dir/sub");

        $this->assertNotUploaded(self::URL . '/sub/secret.pdf');
    }

    // the upload directory, the parent of the slice directory, may live on another volume, linked into the web tree
    public function test_read_followsLinkedUploadDirectory(): void
    {
        mkdir("$this->root/outside/abc/sub", 0o777, true);
        file_put_contents("$this->root/outside/abc/sub/a.pdf", self::PDF);
        symlink("$this->root/outside", "$this->root/linked");

        self::assertSame(self::PDF, (new UploadDir(self::URL, "$this->root/linked/abc"))->read(self::URL . '/sub/a.pdf', 1000));
    }

    // the slice directory is an entry of the upload directory, which other users of the server may write
    #[DataProvider('linkedSliceDirectories')]
    public function test_read_refusesLinkedSliceDirectory(bool $existed): void
    {
        file_put_contents("$this->root/outside/secret.pdf", self::PDF);
        if ($existed) {
            rename($this->dir, "$this->dir.moved");
        } else {
            rmdir($this->dir);
        }
        symlink("$this->root/outside", $this->dir);

        $this->assertLinkedSliceDirectory(self::URL . '/secret.pdf');
    }

    public static function linkedSliceDirectories(): array
    {
        return [
            'renamed for a link'   => [true],
            'missing, made a link' => [false],
        ];
    }

    public function test_read_refusesSliceDirectoryLinkedSinceAnEarlierRead(): void
    {
        $this->linkSliceDirectorySinceAnEarlierRead();

        $this->assertLinkedSliceDirectory(self::URL . '/secret.pdf');
    }

    // open_basedir hides /proc/self/fd unless it names it: read() then resolves the path instead
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_read_withoutProcSelfFd_bytesOfTheFile(): void
    {
        file_put_contents("$this->dir/a.pdf", self::PDF);
        mkdir("$this->root/outside/abc/sub", 0o777, true);
        file_put_contents("$this->root/outside/abc/sub/b.pdf", self::PDF);
        symlink("$this->root/outside", "$this->root/linked");
        self::hideProcSelfFd();

        self::assertSame(self::PDF, $this->uploads()->read(self::URL . '/a.pdf', 1000));
        self::assertSame(self::PDF, (new UploadDir(self::URL, "$this->root/linked/abc"))->read(self::URL . '/sub/b.pdf', 1000));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_read_withoutProcSelfFd_refusesLinksOut(): void
    {
        file_put_contents("$this->root/outside/secret.pdf", self::PDF);
        symlink("$this->root/outside/secret.pdf", "$this->dir/a.pdf");
        symlink("$this->root/outside", "$this->dir/sub");
        self::hideProcSelfFd();

        $this->assertNotUploaded(self::URL . '/a.pdf');
        $this->assertNotUploaded(self::URL . '/sub/secret.pdf');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_read_withoutProcSelfFd_refusesLinkedSliceDirectory(): void
    {
        file_put_contents("$this->root/outside/secret.pdf", self::PDF);
        rename($this->dir, "$this->dir.moved");
        symlink("$this->root/outside", $this->dir);
        self::hideProcSelfFd();

        $this->assertLinkedSliceDirectory(self::URL . '/secret.pdf');
    }

    // the directories the earlier read resolved are cached, the slice directory among them
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_read_withoutProcSelfFd_refusesSliceDirectoryLinkedSinceAnEarlierRead(): void
    {
        self::hideProcSelfFd();
        $this->linkSliceDirectorySinceAnEarlierRead();

        $this->assertLinkedSliceDirectory(self::URL . '/secret.pdf');
    }

    /** open_basedir without /proc/self/fd, which stays for the rest of the process: only in a separate one */
    private static function hideProcSelfFd(): void
    {
        $vendor = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName() ?: '', 2);
        ini_set('open_basedir', implode(PATH_SEPARATOR, [sys_get_temp_dir(), dirname(__DIR__, 3), realpath($vendor) ?: $vendor]));
        self::assertFalse(@scandir('/proc/self/fd'), '/proc/self/fd is still listed');
    }

    /**
     * a.pdf read, then the slice directory replaced by a link to outside/, which holds secret.pdf.
     * PHP's own rename() empties the cache of resolved paths, so the link comes from another process.
     */
    private function linkSliceDirectorySinceAnEarlierRead(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl or posix is not loaded');
        }
        file_put_contents("$this->dir/a.pdf", self::PDF);
        file_put_contents("$this->root/outside/secret.pdf", self::PDF);
        self::assertSame(self::PDF, $this->uploads()->read(self::URL . '/a.pdf', 1000));

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            try {
                rename($this->dir, "$this->dir.moved");
                symlink("$this->root/outside", $this->dir);
            } finally {
                // no shutdown of PHPUnit in the copy of this process, whatever happened
                posix_kill(posix_getpid(), SIGKILL);
            }
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(is_link($this->dir), 'the other process made no link');
    }

    /** refused like any file outside the directory: the message names neither the disk nor the bytes */
    private function assertNotUploaded(string $url): void
    {
        try {
            $this->uploads()->read($url, 1000);
            self::fail('a file outside the directory read');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("$url is not a file uploaded to", $e->getMessage());
            self::assertStringNotContainsString($this->root, $e->getMessage());
            self::assertStringNotContainsString('%PDF', $e->getMessage());
        }
    }

    /** refused with the reason, which the editor could not tell from the URL - no disk path, no bytes */
    private function assertLinkedSliceDirectory(string $url): void
    {
        try {
            $this->uploads()->read($url, 1000);
            self::fail('a file outside the directory read');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('The upload directory of this slice is a link;', $e->getMessage());
            self::assertStringNotContainsString($this->root, $e->getMessage());
            self::assertStringNotContainsString('%PDF', $e->getMessage());
        }
    }

    public function test_read_missingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->uploads()->read(self::URL . '/missing.pdf', 1000);
    }

    // PHP refused the open (EACCES, as on GlusterFS): the message is shown to the editor, never the disk path
    public function test_read_unreadableFileWithoutDiskPath(): void
    {
        if (!function_exists('posix_geteuid') || (posix_geteuid() === 0)) {
            self::markTestSkipped('root reads every file');
        }
        file_put_contents("$this->dir/a.pdf", self::PDF);
        chmod("$this->dir/a.pdf", 0);

        try {
            $this->uploads()->read(self::URL . '/a.pdf', 1000);
            self::fail('an unreadable file read');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(self::URL . '/a.pdf', $e->getMessage());
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }
    }

    public function test_read_directoryIsNoFile(): void
    {
        mkdir("$this->dir/x.pdf");

        $this->expectException(\RuntimeException::class);
        $this->uploads()->read(self::URL . '/x.pdf', 1000);
    }

    // refused by its size on the disk, before it is read
    public function test_read_refusesOverMaxBytes(): void
    {
        $fh = fopen("$this->dir/velka.pdf", 'w');
        self::assertNotFalse($fh);
        ftruncate($fh, 20_000_001);
        fclose($fh);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than 20000000 bytes');
        $this->uploads()->read(self::URL . '/velka.pdf', 20_000_000);
    }

    public function test_read_acceptsMaxBytes(): void
    {
        file_put_contents("$this->dir/a.txt", '0123456789');

        self::assertSame('0123456789', $this->uploads()->read(self::URL . '/a.txt', 10));
        $this->expectException(\InvalidArgumentException::class);
        $this->uploads()->read(self::URL . '/a.txt', 9);
    }

    // a FIFO would block the request until something writes into it
    public function test_read_refusesFifoWithoutWaiting(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix is not loaded');
        }
        posix_mkfifo("$this->dir/a.pdf", 0o600);

        $this->expectException(\RuntimeException::class);
        Alarm::within(2, fn() => $this->uploads()->read(self::URL . '/a.pdf', 1000));
    }

    /** openedPath() with $fds in place of /proc/self/fd */
    private function openedPath(string $fds): mixed
    {
        file_put_contents("$this->dir/a.pdf", self::PDF);
        return (new \ReflectionMethod(UploadDir::class, 'openedPath'))->invoke(null, stat("$this->dir/a.pdf"), $fds);
    }

    public function test_openedPath_pathOfTheHandle(): void
    {
        mkdir("$this->root/fd");
        symlink("$this->root/outside", "$this->root/fd/3");
        symlink("$this->dir/a.pdf", "$this->root/fd/4");

        self::assertSame("$this->dir/a.pdf", $this->openedPath("$this->root/fd"));
    }

    // read() then refuses the file: open_basedir hides a handle on a path outside it
    public function test_openedPath_falseWithoutTheHandle(): void
    {
        mkdir("$this->root/fd");
        file_put_contents("$this->root/outside/b.pdf", self::PDF);
        symlink("$this->root/outside/b.pdf", "$this->root/fd/3");

        self::assertFalse($this->openedPath("$this->root/fd"));
    }

    // the file itself in place of a link: found, but its path cannot be read
    public function test_openedPath_falseWithoutItsPath(): void
    {
        mkdir("$this->root/fd");
        file_put_contents("$this->dir/a.pdf", self::PDF);
        link("$this->dir/a.pdf", "$this->root/fd/3");

        self::assertFalse($this->openedPath("$this->root/fd"));
    }

    // read() then refuses the file
    public function test_openedPath_nullWithoutProc(): void
    {
        self::assertNull($this->openedPath("$this->root/no-proc"));
    }

    // a file holding more than fstat() says - /proc reports 0 bytes, like a file grown since;
    // /proc/<pid>, as /proc/self is a link and a linked directory holds no file
    public function test_read_refusesMoreThanTheSizeSaid(): void
    {
        $dir = '/proc/' . getmypid();
        if (!is_readable("$dir/status")) {
            self::markTestSkipped('no /proc');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('changed while it was read');
        (new UploadDir(self::URL, $dir))->read(self::URL . '/status', 1000);
    }

    public function test_name_decodedFileName(): void
    {
        self::assertSame('Příloha č. 1 – žádost.pdf', $this->uploads()->name(self::URL . '/sub/' . rawurlencode('Příloha č. 1 – žádost.pdf')));
    }

    public function test_name_refusesOtherUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->uploads()->name('https://evil.example/img_upload/abc/a.pdf');
    }

    #[DataProvider('badBases')]
    public function test_construct_refuses(string $url, string $dir): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UploadDir($url, $dir);
    }

    public static function badBases(): array
    {
        return [
            'no directory'   => [self::URL, ''],
            'root'           => [self::URL, '/'],
            'ends with .'    => [self::URL, '/tmp/abc/.'],
            'ends with ..'   => [self::URL, '/tmp/abc/../'],
            'no url'         => ['', '/tmp'],
            'no host'        => ['/img_upload/abc', '/tmp'],
            'no scheme'      => ['example.org/img_upload', '/tmp'],
            'ftp'            => ['ftp://example.org/img_upload', '/tmp'],
            'with a user'    => ['https://user@example.org/img_upload', '/tmp'],
            'with a query'   => ['https://example.org/img_upload?a=1', '/tmp'],
            'not a url'      => ['https://', '/tmp'],
        ];
    }

    public function test_construct_hostWithTheDotOfTheRoot(): void
    {
        file_put_contents("$this->dir/a.pdf", self::PDF);

        self::assertSame(self::PDF, (new UploadDir('https://example.org./img_upload/abc', $this->dir))->read('https://example.org/img_upload/abc/a.pdf', 1000));
    }

    public function test_forUploadBase_baseOfTheSlice(): void
    {
        file_put_contents("$this->dir/a.pdf", self::PDF);

        self::assertSame(self::PDF, UploadDir::forUploadBase(['path' => $this->dir, 'url' => self::URL, 'perms' => 0o2775])->read(self::URL . '/a.pdf', 1000));
    }

    // the editor cannot fix the URL: the message says where it is set
    public function test_forUploadBase_namesWhereToSetTheUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('~"/img_upload/abc".*IMG_UPLOAD_URL.*include/config\.php.*settings of the slice~');
        UploadDir::forUploadBase(['path' => $this->dir, 'url' => '/img_upload/abc', 'perms' => 0o2775]);
    }

    public function test_construct_explicitDefaultPort(): void
    {
        file_put_contents("$this->dir/a.pdf", self::PDF);

        self::assertSame(self::PDF, (new UploadDir('https://example.org:443/img_upload/abc', $this->dir))->read('https://example.org/img_upload/abc/a.pdf', 1000));
    }
}
