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
        foreach (["$this->docroot/sub/.hidden", "$this->docroot/.git", "$this->aa/include", "$this->aa/css", "$this->upload/abc", "$this->root/outside", "$this->docroot/aa-dev/include", "$this->docroot/aa-dev/css"] as $dir) {
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
            'www/disclaimer.pl.html'    => 'polish',
            'www/aa-dev/include/locsess.php' => '<?php',
            'www/aa-dev/include/config.php~' => '<?php $pwd;',
            'www/aa-dev/css/a.css'      => 'a{}',
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
            'a dot segment'               => ['./sub/./part.txt', 'part'],
            'a language variant'          => ['disclaimer.pl.html', 'polish'],
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
            'another AA tree'               => ['aa-dev/css/a.css'],
            'a backup in another AA tree'   => ['aa-dev/include/config.php~'],
        ];
    }

    #[DataProvider('refused')]
    public function test_contents_refused_file_throws(string $name): void
    {
        $this->expectException(\DomainException::class);
        $this->contents($name);
    }

    public function test_contents_empty_base_reads_nothing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no base directory');
        SiteFile::contents('', 'www/page.html', [], $this->aa);
    }

    public function test_contents_nul_byte_is_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->contents("page.html\0.txt");
    }

    /** a template asking for invented names writes no log rows */
    public function test_contents_missing_dotted_name_is_not_a_refusal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->contents('../missing/../.env');
    }

    public function test_contents_upload_kept_inside_an_aa_tree(): void
    {
        mkdir("$this->aa/uploads/abc", 0o777, true);
        file_put_contents("$this->aa/uploads/abc/x.html", 'inside');

        $this->assertSame('inside', SiteFile::contents($this->docroot, 'aaa/uploads/abc/x.html', ["$this->aa/uploads"], $this->aa));
        $this->expectException(\DomainException::class);
        SiteFile::contents($this->docroot, 'aaa/include/config.php', ["$this->aa/include"], $this->aa);
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


    /** a base inside an AA tree (a document root set to its misc/) opens nothing of it - only an upload directory there is the site's */
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
            'editor backup' => ['config.php~', true],
            'emacs backup'  => ['#config.php#', true],
            'renamed php'   => ['config.php-old', true],
            'key backup'    => ['key.pem~', true],
            'ini backup'    => ['config.ini.bak', true],
            'include part'  => ['menu.inc', true],
            'language'      => ['disclaimer.pl.html', false],
            'old in name'   => ['index.old.html', false],
            'no extension'  => ['README', false],
            'numbered key'  => ['hosting.pem.1', true],
            'dated key'     => ['server.key_2025', true],
            'rotated log'   => ['error.log.1', true],
            'dated page'    => ['report.2025.pdf', false],
            'backup'        => ['page.html.bak', true],
            'script backup' => ['run.php~', true],
            'rotated gzip'  => ['error.log.1.gz', true],
            'gzipped key'   => ['server.key.gz', true],
            'key version'   => ['hosting.pem.v2', true],
            'gzipped data'  => ['data.csv.gz', false],
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
        $real = str_contains($path, "\0") ? false : (string) realpath($path);
        (new \ReflectionProperty(LogOnce::class, 'seen'))->setValue(null, [SiteFile::EVENT . "\0mail:$real" => true, SiteFile::EVENT_OUTER . "\0mail:" . dirname((string) $real) => true]);
        try {
            return SiteFile::readAttachment($path, ["$this->docroot/img_upload/"], $this->aa);
        } finally {
            LogOnce::reset();
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
            'no extension elsewhere'   => ['/outside/passwd'],
            'a script in the upload'   => ['/volume/img_upload/abc/run.php'],
            'a script backup in the upload' => ['/volume/img_upload/abc/run.php~'],
            'another AA tree'          => ['/www/aa-dev/css/a.css'],
            'a NUL byte'               => ["/volume/img_upload/abc/x.html\0.pdf"],
        ];
    }

    #[DataProvider('refusedAttachments')]
    public function test_readAttachment_refused(string $path): void
    {
        file_put_contents("$this->upload/abc/.htaccess", 'Deny from all');
        file_put_contents("$this->upload/abc/run.php", '<?php');
        file_put_contents("$this->upload/abc/run.php~", '<?php');
        file_put_contents("$this->root/outside/passwd", 'root:x:0:0');
        symlink("$this->docroot/secret.key", "$this->upload/abc/key.pdf");

        $this->assertSame('', $this->attachment($this->root . $path));
    }

    public function test_attachmentDir_upload_by_any_spelling(): void
    {
        $bases = ["$this->docroot/img_upload/"];

        $this->assertNotNull(SiteFile::attachmentDir((string) realpath("$this->docroot/img_upload/abc/x.html"), $bases), 'a resolved upload goes the upload way');
        $this->assertNull(SiteFile::attachmentDir((string) realpath("$this->root/outside/file.html"), $bases));
    }
}
