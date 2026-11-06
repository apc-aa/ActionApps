<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Util\LogOnce;
use AA\Util\SiteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What {fileinfo:<url>:crc|time|mime|size} tells of a file on the disk: an uploaded one only.
 *
 * include/stringexpand.php cannot be loaded on its own, so the class is extracted from the real file
 * and evaluated with the upload constants and the web fetch replaced, as StringexpandIncludeTest does.
 */
final class StringexpandFileinfoTest extends TestCase
{
    private const CLS = 'AA_Test_Stringexpand_Fileinfo';

    private string $root = '';
    private ?string $host = null;

    public static function setUpBeforeClass(): void
    {
        if (class_exists(self::CLS, false)) {
            return;
        }
        $source = (string) file_get_contents(__DIR__ . '/../../../include/stringexpand.php');
        $tokens = token_get_all($source);
        $class  = '';
        $depth  = 0;
        foreach ($tokens as $i => $token) {
            if (($class === '') && !(is_array($token) && ($token[0] === T_CLASS) && (($tokens[$i + 2][1] ?? '') === 'AA_Stringexpand_Fileinfo'))) {
                continue;
            }
            $text   = is_array($token) ? $token[1] : $token;
            $class .= $text;
            if (($text === '{') || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (($text === '}') && (--$depth === 0)) {
                break;
            }
        }
        eval(strtr($class, [
            'class AA_Stringexpand_Fileinfo extends AA_Stringexpand' => 'class ' . self::CLS,
            'SiteFile::uploadRoots()' => '[$GLOBALS["aa_test_fileinfo"]["path"]]',
            'SiteFile::'              => '\AA\Util\SiteFile::',
            'HttpInclude::fetch('     => '\\' . self::class . '::fetch(',
            'IMG_UPLOAD_URL'          => '$GLOBALS["aa_test_fileinfo"]["url"]',
            'IMG_UPLOAD_PATH'         => '$GLOBALS["aa_test_fileinfo"]["path"]',
        ]));
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aa-fileinfo-' . bin2hex(random_bytes(6));
        mkdir("$this->root/img_upload/abc", 0o777, true);
        mkdir("$this->root/outside", 0o777, true);
        file_put_contents("$this->root/img_upload/abc/doc.txt", 'uploaded file');
        file_put_contents("$this->root/outside/passwd", 'root:x:0:0');
        touch("$this->root/img_upload/abc/doc.txt", 1700000000);
        // the size of a file not uploaded here is asked of the web, from this host for a bare path
        $this->host = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'example.org';
        $GLOBALS['aa_test_fileinfo'] = ['url' => 'https://example.org/img_upload/', 'path' => "$this->root/img_upload/", 'fetched' => []];
        // a refused file writes a log row; seen already, so the test reaches no database
        (new \ReflectionProperty(LogOnce::class, 'seen'))->setValue(null, [SiteFile::EVENT . "\0fileinfo:" . realpath("$this->root/outside/passwd") => true]);
    }

    protected function tearDown(): void
    {
        LogOnce::reset();
        unset($GLOBALS['aa_test_fileinfo']);
        if ($this->host === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->host;
        }
        foreach (["img_upload/abc/doc.txt", "outside/passwd"] as $file) {
            @unlink("$this->root/$file");
        }
        foreach (["img_upload/abc", "img_upload", "outside", ""] as $dir) {
            @rmdir("$this->root/$dir");
        }
    }

    /** HttpInclude::fetch() - nothing is fetched */
    public static function fetch(string $method, string $url): string
    {
        $GLOBALS['aa_test_fileinfo']['fetched'][] = $url;
        return '';
    }

    private static function info(string $url, string $info): string
    {
        $cls = self::CLS;
        return (new $cls())->expand($url, $info);
    }

    /** @return array<string, array{string, string}> */
    public static function uploaded(): array
    {
        return [
            'crc'      => ['crc', hash('crc32', 'uploaded file')],
            'time'     => ['time', '1700000000'],
            'mime'     => ['mime', 'text/plain'],
            'bytesize' => ['bytesize', '13'],
            'size'     => ['size', '0 kB'],
        ];
    }

    #[DataProvider('uploaded')]
    public function test_expand_uploaded_file(string $info, string $expected): void
    {
        $this->assertSame($expected, self::info('https://example.org/img_upload/abc/doc.txt', $info));
    }

    /** @return array<string, array{string}> */
    public static function outside(): array
    {
        return [
            'a disk path'          => ['{root}/outside/passwd'],
            'up out of the upload' => ['https://example.org/img_upload/abc/../../outside/passwd'],
        ];
    }

    #[DataProvider('outside')]
    public function test_expand_file_outside_the_upload_tells_nothing(string $url): void
    {
        $url = str_replace('{root}', $this->root, $url);
        foreach (['crc', 'time', 'mime', 'bytesize'] as $info) {
            $this->assertSame('', self::info($url, $info), $info);
        }
        $this->assertSame([($url[0] === '/') ? "https://example.org$url" : $url], $GLOBALS['aa_test_fileinfo']['fetched'], 'the size is asked of the web, as for a file elsewhere');
    }
}
