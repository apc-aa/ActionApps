<?php

declare(strict_types=1);

namespace Tests\Unit\IO;

use AA\IO\ImportFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The CSV import passes only the name of its data file between the steps; ImportFile::path()
 * turns a name from the request back into a path, and only into a generated file of the slice's
 * upload directory - step 2 reads that file and deletes it.
 */
final class ImportFileTest extends TestCase
{
    private const NAME = 'importdata_0123456789abcdef0123456789abcdef_10042026';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aa-importfile-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/' . self::NAME, "a;b\n1;2\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_path_generatedFileInDir_returnsItsPath(): void
    {
        self::assertSame(realpath($this->dir . '/' . self::NAME), ImportFile::path($this->dir, self::NAME));
    }

    #[DataProvider('foreignNames')]
    public function test_path_otherName_returnsNull(string $name): void
    {
        self::assertNull(ImportFile::path($this->dir, $name));
    }

    public static function foreignNames(): array
    {
        return [
            'empty'           => [''],
            'absolute path'   => ['/etc/passwd'],
            'traversal'       => ['../' . self::NAME],
            'path in name'    => [self::NAME . '/x'],
            'stream'          => ['php://filter/resource=' . self::NAME],
            'url'             => ['https://example.org/data.csv'],
            'other prefix'    => ['csvdata_0123456789abcdef0123456789abcdef_10042026'],
            'trailing chars'  => [self::NAME . '.php'],
            'trailing newline' => [self::NAME . "\n"],
            'missing file'    => ['importdata_ffffffffffffffffffffffffffffffff_10042026'],
        ];
    }

    public function test_path_noDir_returnsNull(): void
    {
        self::assertNull(ImportFile::path('', self::NAME));
    }

    public function test_path_linkLeadingOut_returnsNull(): void
    {
        $name = 'importdata_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa_10042026';
        symlink(__FILE__, $this->dir . '/' . $name);
        self::assertNull(ImportFile::path($this->dir, $name));
    }

    // a public address literal: the guard resolves host names, and the documentation ranges count as reserved
    public function test_fetch_webAddress_returnsBody(): void
    {
        self::assertSame("a;b\n", ImportFile::fetch('https://1.1.1.1/data.csv', new MockHttpClient(new MockResponse("a;b\n"))));
    }

    public function test_fetch_errorStatus_returnsNull(): void
    {
        self::assertNull(ImportFile::fetch('https://1.1.1.1/data.csv', new MockHttpClient(new MockResponse('', ['http_code' => 404]))));
    }

    public function test_fetch_tooLarge_returnsNull(): void
    {
        self::assertNull(ImportFile::fetch('https://1.1.1.1/data.csv', new MockHttpClient(new MockResponse(str_repeat('x', 11))), 10));
    }

    // the transport would answer, so null means the address was refused
    #[DataProvider('refusedAddresses')]
    public function test_fetch_refusedAddress_returnsNull(string $url): void
    {
        self::assertNull(ImportFile::fetch($url, new MockHttpClient(new MockResponse('secret'))));
    }

    public static function refusedAddresses(): array
    {
        return [
            'local path'      => ['/etc/passwd'],
            'file scheme'     => ['file:///etc/passwd'],
            'php stream'      => ['php://filter/resource=/etc/passwd'],
            'ftp'             => ['ftp://192.0.2.10/data.csv'],
            'loopback'        => ['http://127.0.0.1/server-status'],
            'private network' => ['http://192.168.1.1/data.csv'],
            'link-local'      => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback'   => ['http://[::1]/data.csv'],
            'no host'         => ['http://'],
        ];
    }
}
