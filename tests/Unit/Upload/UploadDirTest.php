<?php

declare(strict_types=1);

namespace Tests\Unit\Upload;

use AA\Upload\UploadDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadDirTest extends TestCase
{
    private const URL = 'https://Example.org/img_upload/abc';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

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

        $this->expectException(\InvalidArgumentException::class);
        $this->uploads()->read(self::URL . '/a.pdf', 1000);
    }

    public function test_read_refusesLinkedDirectoryOutside(): void
    {
        file_put_contents("$this->root/outside/secret.pdf", self::PDF);
        symlink("$this->root/outside", "$this->dir/sub");

        $this->expectException(\InvalidArgumentException::class);
        $this->uploads()->read(self::URL . '/sub/secret.pdf', 1000);
    }

    // the upload directory itself may live on another volume, linked into the web tree
    public function test_read_followsLinkedUploadDirectory(): void
    {
        file_put_contents("$this->root/outside/a.pdf", self::PDF);
        symlink("$this->root/outside", "$this->root/upload/linked");

        self::assertSame(self::PDF, (new UploadDir(self::URL, "$this->root/upload/linked"))->read(self::URL . '/a.pdf', 1000));
    }

    public function test_read_missingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->uploads()->read(self::URL . '/missing.pdf', 1000);
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
        $this->uploads()->read(self::URL . '/a.pdf', 1000);
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

    // read() then resolves the path again
    public function test_openedPath_nullWithoutProc(): void
    {
        self::assertNull($this->openedPath("$this->root/no-proc"));
    }

    // a file holding more than fstat() says - /proc reports 0 bytes, like a file grown since
    public function test_read_refusesMoreThanTheSizeSaid(): void
    {
        if (!is_readable('/proc/self/status')) {
            self::markTestSkipped('no /proc');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('changed while it was read');
        (new UploadDir(self::URL, '/proc/self'))->read(self::URL . '/status', 1000);
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
