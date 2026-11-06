<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\LogOnce;
use AA\Util\SiteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteFileTest extends TestCase
{
    private string $root = '';
    private string $docroot = '';
    private string $aa = '';
    private string $upload = '';

    protected function setUp(): void
    {
        // the AA tree in the document root, the upload directory on another volume behind a link - as on the ECN servers
        $this->root    = sys_get_temp_dir() . '/aa-sitefile-' . bin2hex(random_bytes(6));
        $this->docroot = "$this->root/www";
        $this->aa      = "$this->docroot/aaa";
        $this->upload  = "$this->root/volume/img_upload";
        foreach (["$this->docroot/sub/.hidden", "$this->docroot/.git", "$this->aa/include", "$this->aa/css", "$this->upload/abc", "$this->root/outside"] as $dir) {
            mkdir($dir, 0o777, true);
        }
        $files = [
            'www/page.html'             => '<p>page</p>',
            'www/sub/part.txt'          => 'part',
            'www/sub/.hidden/x.txt'     => 'hidden',
            'www/.htaccess'             => 'Deny from all',
            'www/.git/config'           => '[core]',
            'www/secret.key'            => 'KEY',
            'www/config.php.bak'        => '<?php $pwd;',
            'www/aaa/include/config.php' => '<?php $pwd;',
            'www/aaa/css/a.css'         => 'a{}',
            'volume/img_upload/abc/x.html' => 'uploaded',
            'outside/file.html'         => 'outside',
        ];
        foreach ($files as $path => $content) {
            file_put_contents("$this->root/$path", $content);
        }
        symlink($this->upload, "$this->docroot/img_upload");
        symlink("$this->root/outside", "$this->docroot/link-out");
        symlink("$this->docroot/secret.key", "$this->docroot/nice.html");
        symlink($this->aa, "$this->docroot/aa-link");
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

    private function contents(string $name, string $base = ''): string
    {
        return SiteFile::contents(($base === '') ? $this->docroot : $base, $name, [$this->upload], $this->aa);
    }

    /** @return array<string, array{string, string}> */
    public static function allowed(): array
    {
        return [
            'a file of the site'          => ['page.html', '<p>page</p>'],
            'a leading slash'             => ['/page.html', '<p>page</p>'],
            'a subdirectory'              => ['sub/part.txt', 'part'],
            'doubled slashes'             => ['sub//part.txt', 'part'],
            'an upload behind a link'     => ['img_upload/abc/x.html', 'uploaded'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_contents_allowed_file_is_read(string $name, string $expected): void
    {
        $this->assertSame($expected, $this->contents($name));
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'up out of the document root'   => ['../outside/file.html'],
            'up and back'                   => ['sub/../page.html'],
            'a dot segment'                 => ['./page.html'],
            'a dotfile'                     => ['.htaccess'],
            'a dot directory'               => ['.git/config'],
            'a hidden subdirectory'         => ['sub/.hidden/x.txt'],
            'a link out of the site'        => ['link-out/file.html'],
            'the AA tree'                   => ['aaa/css/a.css'],
            'the AA configuration'          => ['aaa/include/config.php'],
            'the AA tree behind a link'     => ['aa-link/css/a.css'],
            'a key'                         => ['secret.key'],
            'a backup of a script'          => ['config.php.bak'],
            'an innocent link to a key'     => ['nice.html'],
            'a NUL byte'                    => ["page.html\0.txt"],
        ];
    }

    #[DataProvider('refused')]
    public function test_contents_refused_file_throws(string $name): void
    {
        $this->expectException(\DomainException::class);
        $this->contents($name);
    }

    public function test_contents_empty_base_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        SiteFile::contents('', 'www/page.html', [], $this->aa);
    }

    public function test_contents_missing_file_is_not_a_refusal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->contents('missing.html');
    }

    public function test_contents_directory_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->contents('sub');
    }

    public function test_contents_larger_file_is_not_read(): void
    {
        $this->expectException(\RuntimeException::class);
        SiteFile::contents($this->docroot, 'page.html', [], $this->aa, 5);
    }

    public function test_contents_base_inside_the_aa_tree_reads_nothing(): void
    {
        $this->expectException(\DomainException::class);
        $this->contents('a.css', "$this->aa/css");
    }

    public function test_read_allowed_file(): void
    {
        $this->assertSame('part', SiteFile::read($this->docroot, 'sub/part.txt', [], 'readfile'));
    }

    public function test_read_missing_file_is_empty(): void
    {
        $this->assertSame('', SiteFile::read($this->docroot, 'missing.html', [], 'readfile'));
    }

    /** @return array<string, array{string, bool}> */
    public static function names(): array
    {
        return [
            'html'          => ['page.html', false],
            'pdf'           => ['report.pdf', false],
            'php'           => ['index.php', true],
            'php backup'    => ['config.php.bak', true],
            'ini'           => ['php.ini', true],
            'user ini'      => ['.user.ini', true],
            'env'           => ['.env', true],
            'pem'           => ['cert.PEM', true],
            'htaccess'      => ['.htaccess', true],
        ];
    }

    #[DataProvider('names')]
    public function test_isSecretName(string $name, bool $secret): void
    {
        $this->assertSame($secret, SiteFile::isSecretName($name));
    }

    /** a refusal or a file from outside the uploads writes a log row; seen already, so the test reaches no database */
    private function attachment(string $path): string
    {
        $seen = new \ReflectionProperty(LogOnce::class, 'seen');
        $seen->setValue(null, [SiteFile::EVENT . "\0mail:$path" => true, SiteFile::EVENT_OUTER . "\0mail:$path" => true]);
        try {
            return SiteFile::readAttachment($path, ["$this->root/volume/img_upload/"], $this->aa);
        } finally {
            $seen->setValue(null, []);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function attachments(): array
    {
        return [
            'an upload of any type'          => ['/volume/img_upload/abc/report.xlsx', 'xlsx'],
            'a doubled slash of a join'      => ['/volume/img_upload//abc/x.html', 'uploaded'],
            'an upload behind a link'        => ['/www/img_upload/abc/x.html', 'uploaded'],
            'a harmless file elsewhere'      => ['/outside/file.html', 'outside'],
            'a link out to a harmless file'  => ['/volume/img_upload/abc/out.html', 'outside'],
        ];
    }

    #[DataProvider('attachments')]
    public function test_readAttachment_sent(string $path, string $expected): void
    {
        file_put_contents("$this->upload/abc/report.xlsx", 'xlsx');
        symlink("$this->root/outside/file.html", "$this->upload/abc/out.html");

        $this->assertSame($expected, $this->attachment($this->root . $path));
    }

    /** @return array<string, array{string}> */
    public static function refusedAttachments(): array
    {
        return [
            'the AA configuration'     => ['/www/aaa/include/config.php'],
            'a file of the AA tree'    => ['/www/aaa/css/a.css'],
            'up out of the upload'     => ['/volume/img_upload/abc/../../../www/secret.key'],
            'a key elsewhere'          => ['/www/secret.key'],
            'a dotfile elsewhere'      => ['/www/.htaccess'],
            'a dotfile of the upload'  => ['/volume/img_upload/abc/.htaccess'],
            'a link out to a key'      => ['/volume/img_upload/abc/key.pdf'],
            'a missing file'           => ['/volume/img_upload/abc/missing.pdf'],
        ];
    }

    #[DataProvider('refusedAttachments')]
    public function test_readAttachment_refused(string $path): void
    {
        file_put_contents("$this->upload/abc/.htaccess", 'Deny from all');
        symlink("$this->docroot/secret.key", "$this->upload/abc/key.pdf");

        $this->assertSame('', $this->attachment($this->root . $path));
    }
}
